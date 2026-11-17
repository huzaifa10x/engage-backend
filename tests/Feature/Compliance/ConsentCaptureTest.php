<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Messaging\Enums\ConsentState;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\WhatsApp\Models\PhoneNumber;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

/** Batch 4, item 4: consent capture (flow step, Inbox question, import, website) and campaign guardrails. */
final class ConsentCaptureTest extends TestCase
{
    use InteractsWithWhatsapp;

    private Tenant $tenant;

    private TenantMembership $owner;

    private PhoneNumber $number;

    /** @var list<array<string, mixed>> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
        $this->tenant = $this->createTenant(['name' => 'Nova Fitness']);
        $this->tenantContext()->bypass(fn () => $this->tenant->forceFill(['settings' => ['compliance' => ['quiet_hours_enabled' => false, 'campaign_hourly_share' => 100]]])->save());
        $this->subscribe($this->tenant, 'pro');
        $this->owner = $this->addMember($this->tenant);
        $this->number = $this->connectNumber($this->tenant);
        $this->tenantContext()->run($this->tenant, fn () => $this->number->forceFill(['messaging_limit_tier' => 'TIER_10K'])->save());
        $this->actingAsMember($this->owner);
        Http::preventStrayRequests();
        Http::fake([
            'graph.facebook.com/v27.0/102290129340398/message_templates*' => Http::response(['data' => [
                ['id' => '900001', 'name' => 'big_sale', 'language' => 'en', 'status' => 'APPROVED', 'category' => 'MARKETING',
                    'components' => [['type' => 'BODY', 'text' => 'Our big sale starts today.']]],
                ['id' => '900002', 'name' => 'class_reminder', 'language' => 'en', 'status' => 'APPROVED', 'category' => 'UTILITY',
                    'components' => [['type' => 'BODY', 'text' => 'Your class is tomorrow.']]],
            ]]),
            'graph.facebook.com/*/messages*' => function (Request $r) {
                $this->sent[] = $r->data();

                return Http::response(['messaging_product' => 'whatsapp', 'contacts' => [['wa_id' => '0']], 'messages' => [['id' => 'wamid.K'.bin2hex(random_bytes(6))]]]);
            },
        ]);
        $this->postJson('/api/v1/templates/sync')->assertOk();
    }

    private function contact(string $waId): ?Contact
    {
        return $this->tenantContext()->run($this->tenant, fn () => Contact::query()->where('wa_id', $waId)->first());
    }

    private function consentFlow(): void
    {
        $nodes = [
            ['id' => 'start', 'type' => 'start', 'title' => 'Start', 'config' => ['any_message' => true], 'outputs' => [['id' => 'next', 'to' => 'ask']]],
            ['id' => 'ask', 'type' => 'consent', 'title' => 'Consent', 'config' => ['text' => 'Can we send you offers on WhatsApp?', 'yes_label' => 'Yes please', 'no_label' => 'No thanks'],
                'outputs' => [['id' => 'yes', 'to' => 'thanks'], ['id' => 'no', 'to' => 'ok']]],
            ['id' => 'thanks', 'type' => 'handoff', 'title' => 'Thanks', 'config' => ['text' => 'Great, welcome aboard!'], 'outputs' => []],
            ['id' => 'ok', 'type' => 'handoff', 'title' => 'OK', 'config' => ['text' => 'No problem.'], 'outputs' => []],
        ];
        $id = $this->postJson('/api/v1/chatbots', ['name' => 'Consent', 'phone_number_id' => $this->number->id, 'template' => 'blank'])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/chatbots/{$id}", ['draft' => ['nodes' => $nodes]])->assertOk();
        $this->postJson("/api/v1/chatbots/{$id}/publish")->assertOk();
        $this->postJson("/api/v1/chatbots/{$id}/activate", ['active' => true])->assertOk();
    }

    private function says(string $waId, array $message): void
    {
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue($message, waId: $waId, bsuid: null)))->assertOk();
    }

    public function test_yes_in_the_flow_step_opts_in_with_source_wording_and_time_and_no_opts_out_of_marketing(): void
    {
        $this->consentFlow();

        $this->says('971501110001', ['text' => ['body' => 'Hi']]);
        $question = collect($this->sent)->last();
        $this->assertSame('Can we send you offers on WhatsApp?', $question['interactive']['body']['text']);
        $this->assertSame(['Yes please', 'No thanks'], array_column(array_column($question['interactive']['action']['buttons'], 'reply'), 'title'));
        $yesId = $question['interactive']['action']['buttons'][0]['reply']['id'];
        $this->says('971501110001', ['type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => $yesId, 'title' => 'Yes please']]]);

        $yes = $this->contact('971501110001');
        $this->assertSame(ConsentState::OptedIn, $yes->consent_state);
        $this->assertSame('opted_in', $yes->marketingConsent());
        $history = $this->getJson("/api/v1/contacts/{$yes->id}/consent-history")->assertOk()->json('data');
        $this->assertSame('opted_in', $history[0]['action']);
        $this->assertSame('flow_step', $history[0]['source']);
        $this->assertSame('Flow step', $history[0]['source_label']);
        $this->assertSame('whatsapp', $history[0]['channel']);
        $this->assertSame('Can we send you offers on WhatsApp? [Yes please]', $history[0]['wording']);
        $this->assertNotNull($history[0]['created_at']);

        $this->says('971501110002', ['text' => ['body' => 'Hi']]);
        $noId = collect($this->sent)->last()['interactive']['action']['buttons'][1]['reply']['id'];
        $this->says('971501110002', ['type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => $noId, 'title' => 'No thanks']]]);
        $no = $this->contact('971501110002');
        $this->assertSame('opted_out', $no->marketingConsent());
        $this->assertTrue($no->marketing_opted_out);
        $this->assertSame(ConsentState::Unknown, $no->consent_state); // utility messages still allowed
        $this->assertSame('marketing_opted_out', $this->getJson("/api/v1/contacts/{$no->id}/consent-history")->json('data.0.action'));
        $this->assertSame('No problem.', collect($this->sent)->last()['text']['body']);
    }

    public function test_no_thanks_to_the_inbox_consent_question_opts_out_of_marketing_with_the_question_text(): void
    {
        $this->says('971501110003', ['text' => ['body' => 'Hello']]);
        $this->says('971501110003', ['type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'consent_decline', 'title' => 'No thanks']]]);
        $c = $this->contact('971501110003');
        $this->assertTrue($c->marketing_opted_out);
        $history = $this->getJson("/api/v1/contacts/{$c->id}/consent-history")->json('data');
        $this->assertSame(['marketing_opted_out', 'consent_declined'], array_column(array_slice($history, 0, 2), 'action'));
        $this->assertStringEndsWith('[No thanks]', (string) $history[0]['wording']);
    }

    public function test_imports_read_a_consent_status_column_and_keep_the_clients_source(): void
    {
        $csv = UploadedFile::fake()->createWithContent('contacts.csv', "phone,name,consent status\n+971501110011,Ana,opted in\n+971501110012,Ben,opted out\n+971501110013,Cy,\n");
        $this->postJson('/api/v1/contacts/import', ['file' => $csv, 'consent_source' => 'collected at store'])->assertOk();

        $ana = $this->contact('971501110011');
        $this->assertSame('opted_in', $ana->marketingConsent());
        $h = $this->getJson("/api/v1/contacts/{$ana->id}/consent-history")->json('data.0');
        $this->assertSame(['import', 'Import', 'Imported – collected at store'], [$h['source'], $h['source_label'], $h['detail']]);
        $this->assertSame('opted_out', $this->contact('971501110012')->marketingConsent());
        $this->assertSame('unknown', $this->contact('971501110013')->marketingConsent());
    }

    /** 60 opted in, 30 unknown, 10 opted out. */
    private function hundred(): string
    {
        $this->tenantContext()->run($this->tenant, function () {
            $rows = [];
            for ($i = 1; $i <= 100; $i++) {
                $rows[] = ['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'wa_id' => '9715022'.str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                    'name' => "Guest {$i}", 'tags' => '{Sale}', 'consent_state' => $i <= 60 ? 'opted_in' : ($i <= 90 ? 'unknown' : 'opted_out'),
                    'marketing_opted_out' => false, 'created_at' => now(), 'updated_at' => now()];
            }
            DB::table('contacts')->insert($rows);
        });

        return (string) collect($this->getJson('/api/v1/templates')->json('data'))->firstWhere('name', 'big_sale')['id'];
    }

    private function campaign(string $template, bool $optedInOnly = false): string
    {
        return (string) $this->postJson('/api/v1/campaigns', ['name' => 'Big sale', 'phone_number_id' => $this->number->id, 'template_id' => $template,
            'audience_tag' => 'Sale', 'opted_in_only' => $optedInOnly])->assertCreated()->json('data.id');
    }

    public function test_a_marketing_campaign_shows_the_three_counts_needs_the_confirmation_sends_to_90_and_logs_it(): void
    {
        $template = $this->hundred();
        $this->getJson("/api/v1/campaigns/audience?audience_tag=Sale&template_id={$template}&phone_number_id={$this->number->id}")->assertOk()
            ->assertJsonPath('data.consent', ['opted_in' => 60, 'unknown' => 30, 'opted_out' => 10])->assertJsonPath('data.eligible', 90);

        $id = $this->campaign($template);
        $this->postJson("/api/v1/campaigns/{$id}/launch")->assertUnprocessable()
            ->assertJsonPath('error.details.fields.consent_confirmed.0', '30 contacts have no consent record. Confirm these contacts agreed to receive messages from your business, or send only to opted-in contacts.');
        $this->postJson("/api/v1/campaigns/{$id}/launch", ['consent_confirmed' => true])->assertOk();

        $this->getJson("/api/v1/campaigns/{$id}")->assertJsonPath('data.stats.sent', 90)->assertJsonPath('data.stats.skipped', 10)
            ->assertJsonPath('data.consent_confirmation.unknown', 30)
            ->assertJsonPath('data.stats.by_consent.opted_in.sent', 60)->assertJsonPath('data.stats.by_consent.unknown.sent', 30)
            ->assertJsonPath('data.stats.by_consent.opted_out.sent', 0);
        $log = $this->tenantContext()->bypass(fn () => AuditLog::query()->where('action', 'campaign.consent_confirmed')->firstOrFail());
        $this->assertSame(30, $log->getAttribute('meta')['unknown_consent']);
        $this->assertSame('Big sale', $log->getAttribute('meta')['campaign']);
        $this->assertSame($id, $log->getAttribute('entity_id'));
        $this->assertNotNull($log->getAttribute('actor_id'));
    }

    public function test_send_only_to_opted_in_contacts_sends_to_60_without_a_confirmation(): void
    {
        $template = $this->hundred();
        $this->getJson("/api/v1/campaigns/audience?audience_tag=Sale&template_id={$template}&phone_number_id={$this->number->id}&opted_in_only=1")
            ->assertJsonPath('data.eligible', 60)->assertJsonPath('data.excluded.no_consent_record', 30);
        $id = $this->campaign($template, true);
        $this->postJson("/api/v1/campaigns/{$id}/launch")->assertOk();
        $this->getJson("/api/v1/campaigns/{$id}")->assertJsonPath('data.stats.sent', 60)->assertJsonPath('data.opted_in_only', true)
            ->assertJsonPath('data.consent_confirmation', null);
    }

    public function test_utility_templates_ignore_marketing_consent(): void
    {
        $this->hundred();
        $this->tenantContext()->run($this->tenant, fn () => Contact::query()->where('consent_state', 'unknown')->limit(5)->update(['marketing_opted_out' => true]));
        $template = (string) collect($this->getJson('/api/v1/templates')->json('data'))->firstWhere('name', 'class_reminder')['id'];
        $id = $this->campaign($template);
        $this->postJson("/api/v1/campaigns/{$id}/launch")->assertOk(); // no confirmation for utility
        // Contacts who opted out of marketing (the 5) still receive utility messages.
        $this->getJson("/api/v1/campaigns/{$id}")->assertJsonPath('data.stats.sent', 100)->assertJsonPath('data.stats.by_consent', null);
    }
}
