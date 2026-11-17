<?php

declare(strict_types=1);

namespace App\Application\WhatsApp;

use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Domain\WhatsApp\Models\WabaAccount;
use App\Infrastructure\Meta\GraphClient;
use App\Infrastructure\Meta\MetaApiException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Channels › a number's business profile checklist: what Meta looks at before granting the blue
 * tick (Official Business Account), each item Done or Missing with a link to fix it in WhatsApp
 * Manager. Read live from Meta (cached for 10 minutes), so it matches WhatsApp Manager.
 *
 * "Ready to apply for the blue tick" only when everything is done; approval is Meta's decision.
 * Numbers shared with the WhatsApp Business app (coexistence): eligibility is not confirmed by Meta.
 */
final class ProfileChecklist
{
    public const MIN_DAYS_ON_API = 30;

    public const CACHE_MINUTES = 10;

    public function __construct(private readonly GraphClient $graph, private readonly WhatsappCredentials $credentials) {}

    /** @return array<string, mixed> */
    public function for(PhoneNumber $number, bool $fresh = false): array
    {
        /** @var WabaAccount $waba */
        $waba = $number->wabaAccount()->firstOrFail();
        $key = "wa-profile-checklist:{$number->id}";
        if ($fresh) {
            Cache::forget($key);
        }
        /** @var array{profile: ?array<string, mixed>, verification: ?string, error: ?string, checked_at: string} $meta */
        $meta = Cache::remember($key, now()->addMinutes(self::CACHE_MINUTES), function () use ($number, $waba): array {
            $profile = null;
            $verification = null;
            $error = null;
            try {
                $token = $this->credentials->tokenFor($waba);
                $profile = $this->graph->getBusinessProfile($number->phone_number_id, $token);
                $verification = $this->graph->getBusinessVerification($waba->waba_id, $token);
            } catch (MetaApiException $e) {
                $error = $e->getMessage();
            } catch (Throwable $e) {
                $error = 'Meta could not be reached.';
            }

            return ['profile' => $profile, 'verification' => $verification, 'error' => $error, 'checked_at' => now()->toIso8601String()];
        });

        $manager = 'https://business.facebook.com/wa/manage/phone-numbers/?waba_id='.$waba->waba_id
            .(($biz = $waba->getAttribute('meta_business_id')) ? '&business_id='.$biz : '');
        $security = 'https://business.facebook.com/settings/security'.($biz ? '?business_id='.$biz : '');
        $profile = $meta['profile'];
        $since = $number->getAttribute('registered_at') ?? $waba->connected_at ?? $number->created_at;
        $days = $since instanceof Carbon ? (int) floor($since->diffInDays(now())) : 0;
        $coexistence = $number->isCoexistence();
        $verified = $meta['verification'];

        $items = [
            $this->item('display_name', 'Display name approved', strtoupper((string) $number->name_status) === 'APPROVED' ? 'done' : 'missing',
                $number->verified_name !== null ? "“{$number->verified_name}” · ".$this->words((string) ($number->name_status ?? 'not reviewed')) : 'No display name yet', $manager),
            $this->item('photo', 'Profile photo', $profile === null ? 'unknown' : (! empty($profile['profile_picture_url']) ? 'done' : 'missing'), null, $manager),
            $this->item('description', 'Description', $profile === null ? 'unknown' : (trim((string) ($profile['description'] ?? $profile['about'] ?? '')) !== '' ? 'done' : 'missing'),
                $profile !== null && trim((string) ($profile['description'] ?? '')) !== '' ? mb_strimwidth((string) $profile['description'], 0, 80, '…') : null, $manager),
            $this->item('website', 'Website', $profile === null ? 'unknown' : (array_filter((array) ($profile['websites'] ?? [])) !== [] ? 'done' : 'missing'),
                $profile !== null ? (implode(', ', array_filter((array) ($profile['websites'] ?? []))) ?: null) : null, $manager),
            // A Cloud API number is registered with a 6-digit PIN, which is its two-step verification PIN.
            $this->item('two_step', 'Two-step verification', $coexistence ? 'unknown'
                : ($number->getAttribute('registered_at') !== null || $number->getAttribute('platform_type') === 'CLOUD_API' ? 'done' : 'missing'),
                $coexistence ? 'Set in the WhatsApp Business app on the phone.' : 'The PIN set when the number was registered on the Cloud API.', $manager),
            $this->item('business_verification', 'Business verification', match ($verified) {
                'VERIFIED' => 'done', null => 'unknown', default => 'missing',
            }, $verified !== null ? $this->words($verified) : null, $security),
            $this->item('days_on_api', 'At least '.self::MIN_DAYS_ON_API.' days on the API', $days >= self::MIN_DAYS_ON_API ? 'done' : 'missing',
                $days >= self::MIN_DAYS_ON_API ? "{$days} days" : "{$days} of ".self::MIN_DAYS_ON_API.' days', null),
        ];
        $ready = collect($items)->every(fn (array $i) => $i['state'] === 'done');

        return [
            'official' => (bool) $number->getAttribute('is_official_business_account'), // already has the blue tick
            'coexistence' => $coexistence,
            'ready' => $ready && ! $coexistence,
            'eligibility_note' => $coexistence ? 'Eligibility not confirmed by Meta' : null,
            'request_url' => $manager,
            'items' => $items,
            'error' => $meta['error'],
            'checked_at' => $meta['checked_at'],
        ];
    }

    /** @return array{key: string, label: string, state: string, detail: ?string, fix_url: ?string} */
    private function item(string $key, string $label, string $state, ?string $detail, ?string $fix): array
    {
        return ['key' => $key, 'label' => $label, 'state' => $state, 'detail' => $detail, 'fix_url' => $state === 'done' ? null : $fix];
    }

    private function words(string $value): string
    {
        return ucfirst(strtolower(str_replace('_', ' ', $value)));
    }
}
