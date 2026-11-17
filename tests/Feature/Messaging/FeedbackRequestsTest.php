<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use App\Application\WhatsApp\NumberAccess;
use App\Domain\Access\SystemRole;
use App\Domain\Feedback\FeedbackRequest;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Templates\Models\MessageTemplate;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\WhatsApp\Models\PhoneNumber;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

/** Batch 5, item 4: CSAT after a support chat is closed, and feedback requests after delivered orders and completed bookings. */
final class FeedbackRequestsTest extends TestCase
{
    use InteractsWithWhatsapp;

    private Tenant $tenant;

    private TenantMembership $owner;

    private TenantMembership $agent;

    private PhoneNumber $number;

    /** @var list<array<string, mixed>> */
    private array $sent = [];

    private string $storeId = '';

    private string $secret = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
        config(['app.url' => 'https://api.engage.test']);
        $this->tenant = $this->createTenant(['name' => 'Nova Fitness', 'timezone' => 'Asia/Dubai']);
        $this->tenantContext()->bypass(fn () => $this->tenant->forceFill(['settings' => ['compliance' => ['quiet_hours_enabled' => false]]])->save());
        $this->subscribe($this->tenant, 'pro');
        $this->owner = $this->addMember($this->tenant);
        $this->agent = $this->addMember($this->tenant, SystemRole::Agent);
        $this->number = $this->connectNumber($this->tenant);
        $this->tenantContext()->run($this->tenant, fn () => app(NumberAccess::class)->setGrants($this->agent, [$this->number->id]));
        Http::fake(['graph.facebook.com/*/messages*' => function (Request $r) {
            $this->sent[] = $r->data();

            return Http::response(['messaging_product' => 'whatsapp', 'contacts' => [['wa_id' => '0']], 'messages' => [['id' => 'wamid.F'.bin2hex(random_bytes(8))]]]);
        }]);
        $this->tenantContext()->run($this->tenant, fn () => MessageTemplate::query()->create(['waba_account_id' => $this->number->waba_account_id, 'name' => 'review_request',
            'language' => 'en', 'status' => 'APPROVED', 'category' => 'UTILITY',
            'components' => [['type' => 'BODY', 'text' => 'Hi {{1}}, how was order {{2}}? Tell us here.']]]));
        $this->actingAsMember($this->owner);
        $this->putJson('/api/v1/tenant/feedback', ['csat_enabled' => true, 'review_enabled' => true,
            'review_template' => ['name' => 'review_request', 'language' => 'en', 'body' => ['{{first_name|there}}', '{{reference}}']]])->assertOk();
    }

    private function customerWrites(string $waId, string $text = 'Hi, my class booking failed'): Conversation
    {
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue(['text' => ['body' => $text]], $waId, null, 'Sara Ahmed')))->assertOk();

        return $this->tenantContext()->run($this->tenant, fn () => Conversation::query()->whereHas('contact', fn ($q) => $q->where('wa_id', $waId))->firstOrFail());
    }

    private function agentAnswersAndCloses(Conversation $c): void
    {
        $this->actingAsMember($this->owner);
        $this->patchJson("/api/v1/conversations/{$c->id}", ['assigned_membership_id' => $this->agent->id])->assertOk();
        $this->actingAsMember($this->agent);
        $this->postJson("/api/v1/conversations/{$c->id}/messages", ['type' => 'text', 'body' => 'Fixed it, you are booked.'])->assertSuccessful();
        $this->patchJson("/api/v1/conversations/{$c->id}", ['status' => 'closed'])->assertOk();
        $this->actingAsMember($this->owner);
    }

    /** @return list<array<string, mixed>> */
    private function ratings(): array
    {
        return array_values(array_filter($this->sent, fn (array $p) => ($p['type'] ?? null) === 'interactive' && str_starts_with((string) ($p['interactive']['action']['buttons'][0]['reply']['id'] ?? ''), 'fb:')));
    }

    private function taps(string $waId, string $id, string $title): void
    {
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue(['type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => $id, 'title' => $title]]], $waId, null, 'Sara Ahmed')))->assertOk();
    }

    public function test_closing_a_support_chat_sends_one_rating_the_answer_is_saved_and_reported_and_a_second_close_within_7_days_sends_nothing(): void
    {
        $c = $this->customerWrites('971501110001');
        $this->agentAnswersAndCloses($c);

        $ratings = $this->ratings();
        $this->assertCount(1, $ratings);
        $this->assertSame('How was your chat with us today?', $ratings[0]['interactive']['body']['text']);
        $this->assertSame(['Good', 'OK', 'Poor'], array_column(array_column($ratings[0]['interactive']['action']['buttons'], 'reply'), 'title'));
        $this->getJson("/api/v1/conversations/{$c->id}")->assertJsonPath('data.status', 'closed');

        // The customer taps Good: saved, thanked, and the chat stays closed (no reply needed).
        $good = $ratings[0]['interactive']['action']['buttons'][0]['reply']['id'];
        $this->taps('971501110001', $good, 'Good');
        $request = $this->tenantContext()->run($this->tenant, fn () => FeedbackRequest::query()->firstOrFail());
        $this->assertSame(['answered', 'good', $this->agent->id], [$request->status, $request->rating, $request->agent_membership_id]);
        $this->assertSame('Thank you for your feedback!', end($this->sent)['text']['body']);
        $this->getJson("/api/v1/conversations/{$c->id}")->assertJsonPath('data.status', 'closed')->assertJsonPath('data.reply_state', fn ($s) => $s !== 'needs_reply');
        $this->taps('971501110001', $good, 'Good'); // a second tap changes nothing
        $this->assertSame(1, $this->tenantContext()->run($this->tenant, fn () => FeedbackRequest::query()->where('status', 'answered')->count()));

        // In the report, per agent and per week.
        $r = $this->getJson('/api/v1/analytics/feedback');
        $this->assertSame(200, $r->status(), (string) $r->getContent());
        $report = $r->json('data');
        $agent = collect($report['agents'])->firstWhere('membership_id', $this->agent->id);
        $this->assertSame([1, 1, 1, 100.0], [$agent['sent'], $agent['answered'], $agent['good'], (float) $agent['score']]);
        $this->assertSame(now('Asia/Dubai')->startOfWeek()->toDateString(), $report['weeks'][0]['week']);
        $this->assertSame(1, $report['weeks'][0]['answered']);

        // Two days later the customer writes again; the chat is closed again: no second request within 7 days.
        $this->travel(2)->days();
        $this->sent = [];
        $c = $this->customerWrites('971501110001', 'One more question');
        $this->agentAnswersAndCloses($c);
        $this->assertSame([], $this->ratings());
        $this->assertSame('Asked in the last 7 days', $this->tenantContext()->run($this->tenant, fn () => FeedbackRequest::query()->where('status', 'skipped')->value('reason')));
        $this->assertSame([['reason' => 'Asked in the last 7 days', 'count' => 1]], $this->getJson('/api/v1/analytics/feedback')->json('data.skipped'));
    }

    public function test_no_rating_outside_the_24_hour_window_or_for_a_chat_no_person_answered(): void
    {
        $c = $this->customerWrites('971501110002');
        $this->patchJson("/api/v1/conversations/{$c->id}", ['assigned_membership_id' => $this->agent->id])->assertOk();
        $this->actingAsMember($this->agent);
        $this->postJson("/api/v1/conversations/{$c->id}/messages", ['type' => 'text', 'body' => 'Looking into it.'])->assertSuccessful();
        $this->travel(25)->hours();
        $this->patchJson("/api/v1/conversations/{$c->id}", ['status' => 'closed'])->assertOk();
        $this->assertSame([], $this->ratings());
        $this->assertSame('Outside the 24-hour window', $this->tenantContext()->run($this->tenant, fn () => FeedbackRequest::query()->value('reason')));

        $quiet = $this->customerWrites('971501110003');
        $this->actingAsMember($this->owner);
        $this->patchJson("/api/v1/conversations/{$quiet->id}", ['status' => 'closed'])->assertOk();
        $this->assertSame(1, $this->tenantContext()->run($this->tenant, fn () => FeedbackRequest::query()->count()), 'nobody answered: not a support chat');
    }

    /** @param array<string, mixed> $payload */
    private function plugin(string $path, array $payload): TestResponse
    {
        $body = (string) json_encode($payload);
        $ts = time();

        return $this->call('POST', "/api/integrations/woocommerce/{$this->storeId}/plugin/{$path}", [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_ENGAGE_TIMESTAMP' => (string) $ts,
            'HTTP_X_ENGAGE_SIGNATURE' => base64_encode(hash_hmac('sha256', $ts.'.'.$body, $this->secret, true)),
        ], $body);
    }

    public function test_a_delivered_woocommerce_order_sends_the_feedback_template_once(): void
    {
        $this->customerWrites('971501234567', 'Hi'); // the customer is a contact with a chat
        $key = (string) $this->postJson('/api/v1/integrations/woocommerce/plugin-key')->assertCreated()->json('data.key');
        $r = $this->postJson('/api/integrations/woocommerce/plugin/connect', ['key' => $key, 'site_url' => 'https://www.palm.example/', 'name' => 'Palm'])->assertCreated();
        $this->storeId = (string) $r->json('data.store_id');
        $this->secret = (string) $r->json('data.secret');
        $order = ['id' => '7001', 'number' => '7001', 'status' => 'processing', 'currency' => 'AED', 'total' => '300.00', 'total_refunded' => '0.00',
            'date_created' => now()->subHour()->toIso8601String(), 'date_modified' => now()->subHour()->toIso8601String(),
            'billing' => ['first_name' => 'Sara', 'last_name' => 'Ahmed', 'phone' => '050 123 4567', 'country' => 'AE'], 'line_items' => []];
        $this->plugin('events', ['events' => [['event' => 'paid', 'occurred_at' => now()->subHour()->toIso8601String(), 'order' => $order]]])->assertSuccessful();
        $this->assertSame([], array_filter($this->sent, fn ($p) => ($p['type'] ?? null) === 'template'));

        $delivered = ['status' => 'completed', 'date_modified' => now()->toIso8601String()] + $order;
        $this->plugin('events', ['events' => [['event' => 'completed', 'occurred_at' => now()->toIso8601String(), 'order' => $delivered]]])->assertSuccessful();
        $templates = array_values(array_filter($this->sent, fn ($p) => ($p['type'] ?? null) === 'template'));
        $this->assertCount(1, $templates);
        $this->assertSame('review_request', $templates[0]['template']['name']);
        $this->assertSame(['Sara', '7001'], array_column($templates[0]['template']['components'][0]['parameters'], 'text'));

        // The store sends the same order again: still one.
        $this->plugin('events', ['events' => [['event' => 'updated', 'occurred_at' => now()->addMinute()->toIso8601String(),
            'order' => ['date_modified' => now()->addMinute()->toIso8601String()] + $delivered]]])->assertSuccessful();
        $this->assertCount(1, array_filter($this->sent, fn ($p) => ($p['type'] ?? null) === 'template'));
    }

    public function test_a_completed_booking_reported_through_the_api_sends_one_request_per_7_days(): void
    {
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue([], '971501110009', null, 'Omar')))->assertOk();
        $key = (string) $this->postJson('/api/v1/developer/api-keys', ['name' => 'Bookings', 'scopes' => ['messages:send']])->assertCreated()->json('data.key');
        $api = fn () => $this->withHeaders(['Authorization' => 'Bearer '.$key, 'Accept' => 'application/json']);

        $this->app['auth']->forgetGuards();
        $first = $api()->postJson('/api/public/v1/bookings/completed', ['phone' => '+971501110009', 'reference' => 'BK-1']);
        $this->assertSame('sent', $first->json('data.status'), (string) $first->getContent());
        $api()->postJson('/api/public/v1/bookings/completed', ['phone' => '+971501110009', 'reference' => 'BK-2'])->assertStatus(202)
            ->assertJsonPath('data.status', 'skipped')->assertJsonPath('data.reason', 'Asked in the last 7 days');
        $api()->postJson('/api/public/v1/bookings/completed', ['phone' => '+971501110009', 'reference' => 'BK-1'])->assertStatus(202)->assertJsonPath('data.status', 'not_sent');
        $this->assertCount(1, array_filter($this->sent, fn ($p) => ($p['type'] ?? null) === 'template'));

        $this->travel(8)->days();
        $api()->postJson('/api/public/v1/bookings/completed', ['phone' => '+971501110009', 'reference' => 'BK-3'])->assertStatus(202)->assertJsonPath('data.status', 'sent');
    }

    public function test_settings_need_an_approved_utility_template_with_every_value(): void
    {
        $this->putJson('/api/v1/tenant/feedback', ['csat_enabled' => false, 'review_enabled' => true])->assertUnprocessable();
        $this->putJson('/api/v1/tenant/feedback', ['csat_enabled' => false, 'review_enabled' => true, 'review_template' => ['name' => 'review_request', 'language' => 'en', 'body' => ['{{first_name}}']]])
            ->assertUnprocessable();
        $this->putJson('/api/v1/tenant/feedback', ['csat_enabled' => false, 'review_enabled' => true, 'review_template' => ['name' => 'nope', 'language' => 'en', 'body' => []]])
            ->assertUnprocessable();
        $this->getJson('/api/v1/tenant/feedback')->assertOk()->assertJsonPath('data.templates.0.name', 'review_request')->assertJsonPath('data.templates.0.body_variables', 2);
        $this->actingAsMember($this->agent);
        $this->putJson('/api/v1/tenant/feedback', ['csat_enabled' => false, 'review_enabled' => false])->assertForbidden();
    }
}
