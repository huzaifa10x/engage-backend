<?php

declare(strict_types=1);

namespace Tests\Feature\Growth;

use App\Domain\Access\SystemRole;
use App\Domain\Growth\GrowthSettings;
use App\Domain\Growth\Links\LinkCode;
use App\Domain\Messaging\Models\ConsentEvent;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\WhatsApp\Models\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

/** Batch 5, item 2: wa.me links and QR codes with a source, and the website button (Channels). */
final class WaLinksTest extends TestCase
{
    use InteractsWithWhatsapp;

    private Tenant $tenant;

    private PhoneNumber $number;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
        $this->tenant = $this->createTenant(['timezone' => 'Asia/Dubai']);
        $this->subscribe($this->tenant, 'growth');
        $this->number = $this->connectNumber($this->tenant);
        $this->tenantContext()->bypass(function () {
            $this->tenant->forceFill(['settings' => ['growth' => ['enabled' => true, 'started_at' => now()->subDay()->toIso8601String()]]])->save();
        });
        GrowthSettings::forget();
        $this->actingAsMember($this->addMember($this->tenant, SystemRole::Owner));
    }

    /** @return array<string, mixed> */
    private function link(array $data): array
    {
        return (array) $this->postJson('/api/v1/wa-links', ['phone_number_id' => $this->number->id] + $data)->assertCreated()->json('data');
    }

    private function chat(string $text, string $waId): void
    {
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue(['text' => ['body' => $text]], $waId, null, 'Guest')))->assertOk();
    }

    private function contact(string $waId): ?Contact
    {
        return $this->tenantContext()->run($this->tenant, fn () => Contact::query()->where('wa_id', $waId)->first());
    }

    public function test_a_chat_from_a_qr_code_is_tagged_with_its_source_counted_on_the_link_and_in_growth_hub(): void
    {
        $shop = $this->link(['name' => 'Shop window', 'message' => 'Hi! I saw your shop window.']);
        $flyer = $this->link(['name' => 'Flyer Nov', 'message' => 'Hi, about the November flyer']);

        $this->assertMatchesRegularExpression('/^EX-[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{6}$/', $shop['code']);
        $this->assertSame("Hi! I saw your shop window. (Ref: {$shop['code']})", $shop['prefilled_text']);
        $this->assertSame('https://wa.me/'.preg_replace('/\D/', '', (string) ($this->number->e164 ?? $this->number->display_phone_number)).'?text='.rawurlencode($shop['prefilled_text']), $shop['url']);

        // Two new customers scan the shop-window code; one comes back later with the flyer.
        $this->chat($shop['prefilled_text'], '971501110001');
        $this->chat($shop['prefilled_text'], '971501110002');
        $this->chat($flyer['prefilled_text'], '971501110001');
        $this->chat('Hi, no code here', '971501110003');

        $first = $this->contact('971501110001');
        $this->assertSame('wa_link', $first?->source);
        $this->assertSame('Shop window', $first?->source_detail, 'the link that brought them in, never replaced');
        $this->assertSame('inbound', $this->contact('971501110003')?->source);

        $links = collect($this->getJson("/api/v1/wa-links?phone_number_id={$this->number->id}")->assertOk()->json('data'))->keyBy('name');
        $this->assertSame([2, 2], [$links['Shop window']['chats'], $links['Shop window']['new_contacts']]);
        $this->assertSame([1, 0], [$links['Flyer Nov']['chats'], $links['Flyer Nov']['new_contacts']]);

        // Agents see the message without the code.
        $conversation = $this->getJson('/api/v1/conversations')->json('data');
        $id = collect($conversation)->first(fn ($c) => ($c['contact']['phone'] ?? null) === '+971501110002')['id'];
        $body = collect($this->getJson("/api/v1/conversations/{$id}/messages")->json('data'))->firstWhere('direction', 'inbound')['body'];
        $this->assertSame('Hi! I saw your shop window.', $body);

        // Growth Hub: the source of the two new leads is the link.
        $this->assertSame('wa_link', $first->getAttribute('acquisition_original')['source']);
        $q = http_build_query(['from' => now('Asia/Dubai')->subDay()->toDateString(), 'to' => now('Asia/Dubai')->toDateString()]);
        $sources = collect($this->getJson("/api/v1/analytics/marketing/sources?{$q}")->assertOk()->json('data.rows'))->keyBy('bucket');
        $this->assertSame(2, $sources['wa_link']['new_leads']);
        $this->assertSame('WhatsApp link or QR code', $sources['wa_link']['label']);
        $campaigns = collect($this->getJson("/api/v1/analytics/marketing/campaigns?{$q}")->json('data.rows'));
        $this->assertSame(2, $campaigns->firstWhere('label', 'Shop window (link or QR code)')['new_leads']);
    }

    public function test_the_links_consent_wording_sent_unchanged_is_an_opt_in_with_the_link_as_source(): void
    {
        $link = $this->link(['name' => 'Instagram bio', 'message' => 'Yes, send me offers on WhatsApp', 'asks_consent' => true]);
        $this->chat($link['prefilled_text'], '971501110010');
        $this->chat(LinkCode::append('Just a question', $link['code']), '971501110011'); // the customer changed the words

        $this->assertSame('opted_in', $this->contact('971501110010')?->consent_state->value);
        $this->assertSame('unknown', $this->contact('971501110011')?->consent_state->value);
        $event = $this->tenantContext()->run($this->tenant, fn () => ConsentEvent::query()->where('action', 'opted_in')->firstOrFail());
        $this->assertSame(['qr_link', 'Instagram bio (link or QR code)', 'Yes, send me offers on WhatsApp'], [$event->source, $event->detail, $event->getAttribute('wording')]);
        $history = $this->getJson('/api/v1/contacts/'.$this->contact('971501110010')?->id.'/consent-history')->json('data.0');
        $this->assertSame('QR code or link', $history['source_label']);
    }

    public function test_the_website_button_snippet_opens_the_link_and_its_chats_are_tagged_website_button(): void
    {
        $button = $this->link(['kind' => 'website_button', 'message' => 'Hi, I have a question', 'button' => ['position' => 'bottom_left', 'label' => 'Chat <now>']]);
        $this->assertSame('Website button', $button['name']);
        $this->assertStringContainsString('href="'.htmlspecialchars($button['url'], ENT_QUOTES | ENT_HTML5).'"', $button['snippet']);
        $this->assertStringContainsString('left:20px', $button['snippet']);
        $this->assertStringContainsString('Chat &lt;now&gt;', $button['snippet']);
        $this->assertStringNotContainsString('<script', $button['snippet']);

        $this->chat($button['prefilled_text'], '971501110020');
        $contact = $this->contact('971501110020');
        $this->assertSame(['wa_link', 'Website button'], [$contact?->source, $contact?->source_detail]);
        $this->assertSame('Website button', $contact?->getAttribute('acquisition_original')['label']);
        $q = http_build_query(['from' => now('Asia/Dubai')->subDay()->toDateString(), 'to' => now('Asia/Dubai')->toDateString()]);
        $campaigns = collect($this->getJson("/api/v1/analytics/marketing/campaigns?{$q}")->json('data.rows'));
        $this->assertSame(1, $campaigns->firstWhere('label', 'Website button (website button)')['new_leads']);

        // Moving it to the right changes the snippet; the code stays.
        $moved = $this->patchJson("/api/v1/wa-links/{$button['id']}", ['button' => ['position' => 'bottom_right', 'label' => 'Chat now']])->assertOk()->json('data');
        $this->assertStringContainsString('right:20px', $moved['snippet']);
        $this->assertSame($button['code'], $moved['code']);
    }

    public function test_a_code_of_another_number_or_in_the_middle_of_a_message_is_not_a_link_chat(): void
    {
        $link = $this->link(['name' => 'Shop window', 'message' => 'Hi']);
        $this->chat("Hi (Ref: {$link['code']}) and more text", '971501110030');
        $this->chat('Hi (Ref: EX-ABCDEF)', '971501110031');
        $this->assertSame(0, collect($this->getJson('/api/v1/wa-links')->json('data'))->firstWhere('id', $link['id'])['chats']);

        $this->postJson('/api/v1/wa-links', ['phone_number_id' => $this->number->id, 'name' => 'X', 'message' => "Hi (Ref: {$link['code']})"])->assertUnprocessable();
        $this->postJson('/api/v1/wa-links', ['phone_number_id' => $this->number->id, 'name' => '', 'message' => 'Hi'])->assertUnprocessable();

        $this->deleteJson("/api/v1/wa-links/{$link['id']}")->assertNoContent();
        $this->assertSame([], $this->getJson('/api/v1/wa-links')->json('data'));
        // An archived link (a printed QR code) still counts.
        $this->chat("Hi (Ref: {$link['code']})", '971501110032');
        $this->assertSame(1, $this->tenantContext()->run($this->tenant, fn () => DB::table('wa_link_chats')->count()));
    }
}
