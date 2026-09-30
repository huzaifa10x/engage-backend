<?php

declare(strict_types=1);

namespace App\Application\WhatsApp;

use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Domain\WhatsApp\Models\WabaAccount;

/** Maps Graph API node payloads onto our rows. Pure mapping — no HTTP. */
final class PhoneNumberSync
{
    /** @param array<string, mixed> $node GET /<WABA_ID> */
    public function applyWaba(WabaAccount $waba, array $node): void
    {
        $owner = is_array($node['owner_business_info'] ?? null) ? $node['owner_business_info'] : [];

        $waba->forceFill(array_filter([
            'name' => $node['name'] ?? null,
            'currency' => $node['currency'] ?? null,
            'timezone_id' => isset($node['timezone_id']) ? (string) $node['timezone_id'] : null,
            'message_template_namespace' => $node['message_template_namespace'] ?? null,
            'account_review_status' => $node['account_review_status'] ?? null,
            'meta_business_id' => isset($owner['id']) ? (string) $owner['id'] : null,
            'business_name' => $owner['name'] ?? null,
            'health_status' => is_array($node['health_status'] ?? null) ? $node['health_status'] : null,
        ], fn ($v) => $v !== null) + ['last_synced_at' => now()])->save();
    }

    /** @param array<string, mixed> $node GET /<PHONE_NUMBER_ID> */
    public function applyPhoneNumber(PhoneNumber $number, array $node): void
    {
        $display = isset($node['display_phone_number']) ? (string) $node['display_phone_number'] : null;
        $digits = $display !== null ? preg_replace('/\D+/', '', $display) : null;

        $number->forceFill(array_filter([
            'display_phone_number' => $display,
            'e164' => $digits ? '+'.$digits : null,
            'verified_name' => $node['verified_name'] ?? null,
            'name_status' => $node['name_status'] ?? null,
            'quality_rating' => $node['quality_rating'] ?? null,
            'messaging_limit_tier' => $node['messaging_limit_tier'] ?? null,
            'throughput_level' => is_array($node['throughput'] ?? null) ? ($node['throughput']['level'] ?? null) : null,
            'code_verification_status' => $node['code_verification_status'] ?? null,
            'platform_type' => $node['platform_type'] ?? null,
            'is_official_business_account' => isset($node['is_official_business_account']) ? (bool) $node['is_official_business_account'] : null,
            'is_on_biz_app' => isset($node['is_on_biz_app']) ? (bool) $node['is_on_biz_app'] : null,
        ], fn ($v) => $v !== null) + ['last_synced_at' => now()])->save();
    }
}
