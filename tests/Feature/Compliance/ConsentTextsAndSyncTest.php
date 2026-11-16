<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Application\Templates\TemplateSync;
use App\Domain\Compliance\ComplianceSettings;
use App\Domain\WhatsApp\Models\WabaAccount;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

/** Data repairs found in the production consent audit. */
final class ConsentTextsAndSyncTest extends TestCase
{
    use InteractsWithWhatsapp;

    public function test_saved_texts_still_equal_to_the_old_defaults_are_replaced_and_custom_ones_kept(): void
    {
        $old = $this->createTenant(['name' => 'Team Dubai']);
        $custom = $this->createTenant(['name' => 'Reem Jewels']);
        $this->tenantContext()->bypass(function () use ($old, $custom): void {
            $old->forceFill(['settings' => ['compliance' => [
                'opt_out_reply' => 'You have been unsubscribed and will not receive further messages from us. Reply START to subscribe again.',
                'consent_request_text' => 'Would you like to receive offers and updates from Team Dubai on WhatsApp? You can unsubscribe at any time by replying STOP.',
                'opt_in_reply' => 'You are subscribed. Reply STOP at any time to unsubscribe.',
            ]]])->save();
            $custom->forceFill(['settings' => ['compliance' => ['opt_out_reply' => 'Done, no more offers from us.']]])->save();
        });

        (require database_path('migrations/2026_11_16_000100_refresh_default_consent_texts.php'))->up();

        $this->tenantContext()->bypass(function () use ($old, $custom): void {
            $a = ComplianceSettings::for($old->refresh());
            $this->assertStringContainsString('You will still get updates about your orders', $a->optOutReply);
            $this->assertStringContainsString('Would you like to receive marketing messages from Team Dubai?', $a->consentRequestText);
            $this->assertSame('You are subscribed. Reply STOP at any time to unsubscribe.', $a->optInReply);
            $this->assertSame('Done, no more offers from us.', ComplianceSettings::for($custom->refresh())->optOutReply);
        });
    }

    public function test_the_last_template_sync_is_read_whichever_way_it_was_written(): void
    {
        $tenant = $this->createTenant();
        $this->subscribe($tenant, 'pro');
        $this->connectNumber($tenant);
        $waba = $this->tenantContext()->run($tenant, fn () => WabaAccount::query()->firstOrFail());

        Cache::put("templates:synced:{$waba->id}", 1794806116, 60); // as the scheduled sync used to write it
        $this->assertSame('2026-11-16T05:15:16+00:00', app(TemplateSync::class)->lastSyncedAt($waba));

        Cache::put("templates:synced:{$waba->id}", '2026-11-16T05:20:00+00:00', 60);
        $this->assertSame('2026-11-16T05:20:00+00:00', app(TemplateSync::class)->lastSyncedAt($waba));
    }
}
