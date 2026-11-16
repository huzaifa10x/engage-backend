<?php

declare(strict_types=1);

namespace Tests\Feature\Conversions;

use App\Domain\Conversions\Models\AdClick;
use App\Domain\Conversions\Services\AdClicks;
use App\Domain\Messaging\Models\Message;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

/** Conversions to Meta, 3a: every chat started from a Meta ad keeps its click, exactly as Meta sent it. */
final class AdClicksTest extends TestCase
{
    use InteractsWithWhatsapp;

    private Tenant $tenant;

    /** A real-looking click ID: long, mixed case, with the characters Meta uses. Must survive untouched. */
    private string $clid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
        $this->tenant = $this->createTenant(); // Growth Hub is off: clicks are kept anyway
        $this->subscribe($this->tenant, 'pro');
        $this->connectNumber($this->tenant);
        $this->clid = 'ARAkLkA8rmlFeiCktEJQ-QTwRiyYHAFDLMNDBH0CD3qpjd0HR4irJ6LEkR7JwFF4XvCF_'.str_repeat('xYz09-_', 90);
    }

    /** @param array<string, mixed> $referral */
    private function adMessage(array $referral, string $waId = '971501234567', int $at = 1794800000): void
    {
        $value = $this->inboundValue(['referral' => $referral, 'timestamp' => (string) $at], $waId, 'AE.'.$waId);
        $this->postWebhook($this->webhookBody('messages', $value))->assertOk();
    }

    /** @return array<string, string> */
    private function referral(string $ad = '120210000000000001', ?string $clid = null): array
    {
        return ['source_url' => 'https://fb.me/abc', 'source_id' => $ad, 'source_type' => 'ad', 'headline' => 'Book a free class',
            'body' => 'Tap to chat', 'media_type' => 'image', 'image_url' => 'https://scontent.example/x.jpg', 'ctwa_clid' => $clid ?? $this->clid];
    }

    public function test_a_chat_from_an_ad_saves_one_click_with_the_raw_click_id(): void
    {
        $this->adMessage($this->referral());

        $clicks = $this->tenantContext()->run($this->tenant, fn () => AdClick::query()->get());
        $this->assertCount(1, $clicks);
        $click = $clicks->first();
        $this->assertSame($this->clid, $click->ctwa_clid, 'exactly as Meta sent it: not trimmed, not cut to a length');
        $this->assertGreaterThan(512, strlen((string) $click->ctwa_clid));
        $this->assertSame(['102290129340398', '120210000000000001', 'ad', 'https://fb.me/abc', 'Book a free class'], [$click->waba_id, $click->source_id, $click->source_type, $click->source_url, $click->headline]);
        $this->assertSame(1794800000, $click->clicked_at->getTimestamp());
        $this->assertNotNull($click->conversation_id);

        // Meta delivering the same message again saves nothing new; a message without a referral saves nothing.
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue(['id' => $this->tenantContext()->bypass(fn () => Message::query()->value('wamid')), 'referral' => $this->referral()])))->assertOk();
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue()))->assertOk();
        $this->assertSame(1, $this->tenantContext()->bypass(fn () => DB::table('ad_clicks')->count()));
    }

    public function test_a_conversion_uses_the_most_recent_click_on_the_chat(): void
    {
        $this->adMessage($this->referral('111', 'CLICK-ONE'), at: 1794800000);
        $this->adMessage($this->referral('222', 'CLICK-TWO'), at: 1794803600);
        $this->adMessage($this->referral('333', 'CLICK-OTHER'), '971509999999', 1794807200); // another person

        $this->tenantContext()->run($this->tenant, function () {
            $conversation = (string) AdClick::query()->where('ctwa_clid', 'CLICK-ONE')->value('conversation_id');
            $contact = (string) AdClick::query()->where('ctwa_clid', 'CLICK-ONE')->value('contact_id');
            $clicks = app(AdClicks::class);
            $this->assertSame('CLICK-TWO', $clicks->latestForConversation($conversation)?->ctwa_clid);
            $this->assertSame('CLICK-ONE', $clicks->latestForConversation($conversation, Carbon::createFromTimestamp(1794801000))?->ctwa_clid, 'a conversion before the second click');
            $this->assertSame('CLICK-TWO', $clicks->latestForContact($contact)?->ctwa_clid);
        });
    }

    public function test_the_backfill_saves_clicks_from_stored_referrals_once(): void
    {
        $this->adMessage($this->referral('111', 'CLICK-STORED'));
        $this->adMessage(array_diff_key($this->referral('222'), ['ctwa_clid' => 1]), '971509999999'); // no click ID: not backfilled
        // As if these messages arrived before ad_clicks existed.
        $this->tenantContext()->bypass(fn () => DB::table('ad_clicks')->delete());

        $this->artisan('engage:conversions:backfill-clicks')->expectsOutputToContain('Ad clicks saved: 1.')->assertSuccessful();
        $this->artisan('engage:conversions:backfill-clicks')->expectsOutputToContain('Ad clicks saved: 0.')->assertSuccessful();
        $this->assertSame(['CLICK-STORED'], $this->tenantContext()->bypass(fn () => DB::table('ad_clicks')->pluck('ctwa_clid')->all()));
    }
}
