<?php

declare(strict_types=1);

namespace Tests\Feature\WhatsApp;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

/**
 * One webhook endpoint serves every client account (Meta's partner rules). Events for an account
 * no workspace has connected are logged and dropped: Meta gets 200, nothing reaches any workspace.
 */
final class UnknownAccountWebhookTest extends TestCase
{
    use InteractsWithWhatsapp;

    private const UNKNOWN_WABA = '424242424242';

    private const TABLES = ['contacts', 'conversations', 'messages', 'quality_events', 'message_templates', 'consent_events', 'member_notifications'];

    /** @return array<string, int> */
    private function counts(): array
    {
        return $this->tenantContext()->bypass(fn () => array_combine(self::TABLES, array_map(fn (string $t) => DB::table($t)->count(), self::TABLES)));
    }

    public function test_events_for_an_unknown_account_are_logged_and_dropped_even_when_the_number_id_is_known(): void
    {
        $this->configureMeta();
        // A real workspace whose number ID the unknown account's events also carry: they must not leak into it.
        $tenant = $this->createTenant();
        $this->subscribe($tenant, 'pro');
        $number = $this->connectNumber($tenant);
        $before = $this->counts();
        $quality = $number->quality_rating;
        Log::spy();

        $events = [
            ['messages', $this->inboundValue()],
            ['messages', ['messaging_product' => 'whatsapp', 'metadata' => ['display_phone_number' => '971585496310', 'phone_number_id' => '106540352242922'],
                'statuses' => [['id' => 'wamid.X', 'status' => 'delivered', 'timestamp' => (string) now()->timestamp, 'recipient_id' => '971501234567']]]],
            ['phone_number_quality_update', ['display_phone_number' => '971585496310', 'event' => 'FLAGGED', 'current_limit' => 'TIER_250']],
            ['message_template_status_update', ['event' => 'APPROVED', 'message_template_id' => 900001, 'message_template_name' => 'x', 'message_template_language' => 'en']],
            ['user_preferences', ['messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => '106540352242922'], 'user_preferences' => [['wa_id' => '971501234567', 'category' => 'marketing_messages', 'value' => 'stop']]]],
        ];
        foreach ($events as [$field, $value]) {
            $this->postWebhook($this->webhookBody($field, $value, self::UNKNOWN_WABA))->assertOk(); // Meta always gets 200
        }
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue(), self::UNKNOWN_WABA))->assertOk(); // again

        // Logged: every raw event is kept, marked unknown_account, with no workspace.
        $rows = $this->tenantContext()->bypass(fn () => DB::table('webhook_inbound_log')->where('waba_id', self::UNKNOWN_WABA)->get());
        $this->assertCount(6, $rows);
        foreach ($rows as $row) {
            $this->assertSame('ignored', $row->process_status, $row->field);
            $this->assertSame('unknown_account', $row->last_error, $row->field);
            $this->assertNull($row->tenant_id);
        }
        Log::shouldHaveReceived('warning')->withArgs(fn (string $m, array $c) => $m === 'Webhook for an unknown WhatsApp account dropped'
            && $c['waba_id'] === self::UNKNOWN_WABA && $c['field'] === 'messages')->once();

        // Dropped: nothing changed in any workspace.
        $this->assertSame($before, $this->counts());
        $this->assertSame($quality, $this->tenantContext()->bypass(fn () => $number->fresh()->quality_rating));
        $this->assertSame('TIER_1K', $this->tenantContext()->bypass(fn () => $number->fresh()->messaging_limit_tier));
    }
}
