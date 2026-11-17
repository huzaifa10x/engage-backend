<?php

declare(strict_types=1);

namespace Tests\Feature\Chatbots;

use App\Domain\Crm\Models\ContactField;
use App\Domain\Forms\FormSubmission;
use App\Domain\Messaging\Models\ConsentEvent;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsChatbots;
use Tests\TestCase;

/** Batch 5, item 5b: simple WhatsApp forms made here, published through Meta's Flows API, sent by a flow step. */
final class WhatsappFormsTest extends TestCase
{
    use BuildsChatbots;

    /** @var list<array{url: string, data: array<string, mixed>}> */
    private array $meta = [];

    /** @var list<array<string, mixed>> */
    private array $metaErrors = [];

    private int $flowIds = 900000000000001;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootChatbots();
        Http::fake([
            'graph.facebook.com/*/flows*' => function (Request $r) {
                $this->meta[] = ['url' => $r->url(), 'data' => $r->data()];

                return Http::response(['id' => (string) $this->flowIds++, 'success' => true, 'validation_errors' => $this->metaErrors]);
            },
            'graph.facebook.com/*/assets*' => function (Request $r) {
                $this->meta[] = ['url' => $r->url(), 'data' => ['flow_json' => (string) collect($r->data())->firstWhere('name', 'file')['contents']]];

                return Http::response(['success' => true, 'validation_errors' => $this->metaErrors]);
            },
            'graph.facebook.com/*/publish*' => function (Request $r) {
                $this->meta[] = ['url' => $r->url(), 'data' => []];

                return Http::response(['success' => true]);
            },
        ]);
        $this->actingAsMember($this->owner);
        $this->inTenant(function () {
            ContactField::query()->create(['key' => 'budget', 'label' => 'Budget', 'type' => 'text']);
            ContactField::query()->create(['key' => 'area', 'label' => 'Area', 'type' => 'text']);
            ContactField::query()->create(['key' => 'requirement', 'label' => 'Requirement', 'type' => 'text']);
            ContactField::query()->create(['key' => 'visit_date', 'label' => 'Visit date', 'type' => 'date']);
            ContactField::query()->create(['key' => 'visit_text', 'label' => 'Visit (text)', 'type' => 'text']);
        });
    }

    /** @return array<string, mixed> */
    private function leadForm(): array
    {
        $form = $this->postJson('/api/v1/chatbots/forms', ['waba_account_id' => $this->number->waba_account_id, 'name' => 'Property lead', 'kind' => 'lead'])
            ->assertCreated()->json('data');
        $definition = $form['definition'];
        $definition['fields'][1]['save_to'] = 'field:budget';
        $definition['fields'][2]['save_to'] = 'field:area';
        $definition['fields'][3]['save_to'] = 'field:requirement';

        return $this->patchJson("/api/v1/chatbots/forms/{$form['id']}", ['definition' => $definition])->assertOk()->json('data');
    }

    private function flowWith(string $formId): void
    {
        $this->liveFlow(['nodes' => [
            $this->node('start', 'start', ['any_message' => false, 'keywords' => ['lead'], 'keyword_match' => 'exact'], ['next' => 'form']),
            $this->node('form', 'form', ['form_id' => $formId, 'text' => 'Tell us what you need', 'cta' => 'Open form'], ['next' => 'thanks']),
            $this->node('thanks', 'handoff', ['text' => 'Thanks {{contact.name}}, an agent will call you.']),
        ]], name: 'Lead capture');
    }

    /** @param array<string, mixed> $answers */
    private function submits(array $answers, string $waId = '971501234567'): void
    {
        $this->customer(['type' => 'interactive', 'interactive' => ['type' => 'nfm_reply', 'nfm_reply' => [
            'name' => 'flow', 'body' => 'Sent', 'response_json' => (string) json_encode(['flow_token' => 'bot:form'] + $answers),
        ]]], $waId);
    }

    /** @return array<string, mixed> */
    private function lastFlowMessage(): array
    {
        return collect($this->sent)->last(fn (array $p) => ($p['interactive']['type'] ?? null) === 'flow');
    }

    public function test_a_lead_form_is_published_sent_by_a_flow_and_its_answers_and_consent_land_on_the_contact(): void
    {
        $form = $this->leadForm();
        $this->assertSame('NOT_CREATED', $form['status']);
        $this->assertSame(['name', 'budget', 'location', 'requirement', 'marketing_consent'], array_column($form['definition']['fields'], 'name'));

        $published = $this->postJson("/api/v1/chatbots/forms/{$form['id']}/publish")->assertOk()->json('data');
        $this->assertSame(['PUBLISHED', 'Published', 1], [$published['status'], $published['status_text'], $published['live_version']]);
        $create = $this->meta[0];
        $this->assertStringContainsString('/102290129340398/flows', $create['url']);
        $this->assertSame(['LEAD_GENERATION'], $create['data']['categories']);
        $json = json_decode($create['data']['flow_json'], true);
        $this->assertSame('7.0', $json['version']);
        $types = array_column($json['screens'][0]['layout']['children'], 'type');
        $this->assertSame(['TextBody', 'TextInput', 'Dropdown', 'TextInput', 'TextArea', 'OptIn', 'Footer'], $types);
        $this->assertSame('complete', end($json['screens'][0]['layout']['children'])['on-click-action']['name']);
        $this->assertStringContainsString('/900000000000001/publish', end($this->meta)['url']);

        $this->flowWith($form['id']);
        $this->says('lead');
        $sent = $this->lastFlowMessage();
        $this->assertSame('900000000000001', $sent['interactive']['action']['parameters']['flow_id']);
        $this->assertSame(['screen' => 'FORM'], $sent['interactive']['action']['parameters']['flow_action_payload']);

        $this->submits(['name' => 'Layla Haddad', 'budget' => '5k_20k', 'location' => 'Dubai Marina', 'requirement' => '2-bedroom flat', 'marketing_consent' => true]);

        $contact = $this->contact();
        $this->assertSame('Layla Haddad', $contact->name);
        $this->assertEquals(['budget' => 'AED 5,000 to 20,000', 'area' => 'Dubai Marina', 'requirement' => '2-bedroom flat'], array_intersect_key((array) $contact->custom_fields, ['budget' => 1, 'area' => 1, 'requirement' => 1]));
        $this->assertSame('opted_in', $contact->consent_state->value);
        $event = $this->inTenant(fn () => ConsentEvent::query()->where('action', 'opted_in')->firstOrFail());
        $this->assertSame(['whatsapp_form', 'Property lead (version 1)', 'Send me offers and news on WhatsApp'], [$event->source, $event->detail, $event->getAttribute('wording')]);
        $this->assertSame('WhatsApp form', $this->getJson("/api/v1/contacts/{$contact->id}/consent-history")->json('data.0.source_label'));
        $this->assertContains('Thanks Layla Haddad, an agent will call you.', $this->texts());

        $subs = $this->getJson("/api/v1/chatbots/forms/{$form['id']}/submissions")->assertOk()->json('data');
        $this->assertCount(1, $subs);
        $this->assertSame(['Your name', 'Layla Haddad'], [$subs[0]['answers'][0]['label'], $subs[0]['answers'][0]['value']]);
        $this->assertTrue($subs[0]['consent']);
        $this->assertSame(1, $subs[0]['version']);
    }

    public function test_an_unticked_consent_box_records_nothing_and_dates_are_day_first_or_iso_in_a_date_field(): void
    {
        $form = $this->postJson('/api/v1/chatbots/forms', ['waba_account_id' => $this->number->waba_account_id, 'name' => 'Visit', 'kind' => 'booking'])->assertCreated()->json('data');
        $definition = $form['definition'];
        $definition['fields'][2]['save_to'] = 'field:visit_date';
        $definition['fields'][] = ['type' => 'date', 'label' => 'Another date', 'save_to' => 'field:visit_text'];
        $this->patchJson("/api/v1/chatbots/forms/{$form['id']}", ['definition' => $definition])->assertOk();
        $this->postJson("/api/v1/chatbots/forms/{$form['id']}/publish")->assertOk();
        $this->flowWith($form['id']);
        $this->says('lead');

        $this->submits(['name' => 'Omar', 'service' => 'follow_up', 'date' => '2026-11-20', 'time_slot' => '16_00', 'marketing_consent' => false, 'another_date' => '2026-12-01']);
        $contact = $this->contact();
        $this->assertSame('2026-11-20', $contact->custom_fields['visit_date']);
        $this->assertSame('01/12/2026', $contact->custom_fields['visit_text']);
        $this->assertSame('unknown', $contact->consent_state->value);
        $answers = collect($this->inTenant(fn () => FormSubmission::query()->firstOrFail())->saved)->pluck('value', 'label');
        $this->assertSame(['Follow-up visit', '20/11/2026', '16:00', 'No'], [$answers['Service'], $answers['Date'], $answers['Time'], $answers['Send me offers and news on WhatsApp']]);
    }

    public function test_editing_a_published_form_makes_a_new_version_and_a_conversation_keeps_the_version_it_got(): void
    {
        $form = $this->leadForm();
        $this->postJson("/api/v1/chatbots/forms/{$form['id']}/publish")->assertOk();
        $this->flowWith($form['id']);
        $this->says('lead', '971500000001'); // this customer gets version 1

        // Version 2: "Budget" now saves to another field.
        $definition = $form['definition'];
        $definition['fields'][1]['save_to'] = 'field:requirement';
        $v2 = $this->patchJson("/api/v1/chatbots/forms/{$form['id']}", ['definition' => $definition])->assertOk()->json('data');
        $this->assertSame([2, 'NOT_CREATED', 1, false], [$v2['version'], $v2['status'], $v2['live_version'], $v2['editing_creates_version']]);
        $this->assertSame([2, 1], array_column($v2['versions'], 'version'));
        $this->postJson("/api/v1/chatbots/forms/{$form['id']}/publish")->assertOk()->assertJsonPath('data.live_version', 2);
        $this->assertSame('900000000000002', $this->getJson("/api/v1/chatbots/forms/{$form['id']}")->json('data.versions.0.meta_flow_id'));

        // The first customer answers the version-1 form: version 1's mapping applies.
        $this->submits(['name' => 'Sara', 'budget' => 'under_5k', 'location' => 'JLT', 'requirement' => 'Studio', 'marketing_consent' => false], '971500000001');
        $first = $this->contact('971500000001');
        $this->assertSame('Under AED 5,000', $first->custom_fields['budget']);
        $this->assertSame(1, $this->getJson("/api/v1/chatbots/forms/{$form['id']}/submissions")->json('data.0.version'));

        // A new customer now gets version 2.
        $this->says('lead', '971500000002');
        $this->assertSame('900000000000002', $this->lastFlowMessage()['interactive']['action']['parameters']['flow_id']);
    }

    public function test_whatsapps_limits_and_errors_come_back_in_plain_words(): void
    {
        $base = ['waba_account_id' => $this->number->waba_account_id, 'name' => 'Too long'];
        $this->postJson('/api/v1/chatbots/forms', $base + ['definition' => ['title' => 'Form', 'fields' => [['type' => 'text', 'label' => 'What is your full legal name please']]]])
            ->assertUnprocessable()->assertJsonFragment(['definition.fields.0.label' => ['Question 1: the question can have up to 20 characters for this kind of answer (WhatsApp’s limit).']]);
        $this->postJson('/api/v1/chatbots/forms', $base + ['definition' => ['title' => 'Form', 'fields' => [['type' => 'dropdown', 'label' => 'Pick', 'options' => []]]]])
            ->assertUnprocessable();

        $form = $this->postJson('/api/v1/chatbots/forms', $base + ['name' => 'Lead', 'kind' => 'lead'])->assertCreated()->json('data');
        $this->metaErrors = [['error' => 'INVALID_PROPERTY', 'message' => 'The label is too long.', 'line_start' => 12]];
        $this->postJson("/api/v1/chatbots/forms/{$form['id']}/publish")->assertUnprocessable()
            ->assertJsonFragment(['form' => ['WhatsApp did not accept the form: The label is too long. (line 12)']]);
        $this->getJson("/api/v1/chatbots/forms/{$form['id']}")->assertJsonPath('data.status_text', 'Draft on WhatsApp (not live)')
            ->assertJsonPath('data.errors.0', 'The label is too long.');

        // A flow step with an unpublished form cannot go live.
        $this->postJson('/api/v1/chatbots', ['name' => 'Lead capture', 'phone_number_id' => $this->number->id, 'template' => 'blank'])->assertCreated();
        $id = $this->postJson('/api/v1/chatbots', ['name' => 'X', 'phone_number_id' => $this->number->id, 'template' => 'blank'])->json('data.id');
        $this->patchJson("/api/v1/chatbots/{$id}", ['draft' => ['nodes' => [
            $this->node('start', 'start', ['any_message' => false, 'keywords' => ['x'], 'keyword_match' => 'exact'], ['next' => 'form']),
            $this->node('form', 'form', ['form_id' => $form['id'], 'text' => 'Fill this in'], ['next' => 'end']),
            $this->node('end', 'handoff', ['text' => 'Thanks']),
        ]]])->assertOk();
        $issues = (string) json_encode($this->postJson("/api/v1/chatbots/{$id}/publish")->assertUnprocessable()->json());
        $this->assertStringContainsString('is not published yet', $issues);
    }
}
