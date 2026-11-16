<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Workspaces that saved Consent & Privacy kept the texts that were the defaults then. Two of them are
 * now wrong: the opt-out reply promised "no further messages" (order and booking updates still go),
 * and the consent question no longer matches the Subscribe / Unsubscribe buttons. A text is replaced
 * only when it is still exactly the old default; anything a workspace wrote itself is left alone.
 */
return new class extends Migration
{
    private const OLD_OPT_OUT = 'You have been unsubscribed and will not receive further messages from us. Reply START to subscribe again.';

    private const NEW_OPT_OUT = 'You have been unsubscribed from our promotional messages. You will still get updates about your orders and bookings. Reply START to subscribe again.';

    public function up(): void
    {
        DB::statement("SELECT set_config('app.rls_bypass', 'on', true)");
        foreach (DB::table('tenants')->whereNotNull('settings')->get(['id', 'name', 'settings']) as $tenant) {
            $settings = json_decode((string) $tenant->settings, true);
            if (! is_array($settings) || ! is_array($settings['compliance'] ?? null)) {
                continue;
            }
            $compliance = $settings['compliance'];
            $name = (string) $tenant->name;
            $changed = false;

            if (($compliance['opt_out_reply'] ?? null) === self::OLD_OPT_OUT) {
                $compliance['opt_out_reply'] = self::NEW_OPT_OUT;
                $changed = true;
            }
            $oldQuestion = "Would you like to receive offers and updates from {$name} on WhatsApp? You can unsubscribe at any time by replying STOP.";
            if (($compliance['consent_request_text'] ?? null) === $oldQuestion) {
                $compliance['consent_request_text'] = "We'd love to send you updates, offers and promotional messages on WhatsApp.\n\nWould you like to receive marketing messages from {$name}?";
                $changed = true;
            }

            if ($changed) {
                $settings['compliance'] = $compliance;
                DB::table('tenants')->where('id', $tenant->id)->update(['settings' => json_encode($settings, JSON_UNESCAPED_UNICODE)]);
            }
        }
        DB::statement("SELECT set_config('app.rls_bypass', 'off', true)");
    }

    public function down(): void
    {
        // Texts are not put back: the old opt-out reply was inaccurate.
    }
};
