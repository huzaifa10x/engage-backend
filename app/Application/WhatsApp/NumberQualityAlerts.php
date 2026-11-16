<?php

declare(strict_types=1);

namespace App\Application\WhatsApp;

use App\Application\Campaigns\ManageCampaigns;
use App\Application\Notifications\WorkspaceMailer;
use App\Domain\Access\SystemRole;
use App\Domain\Messaging\Services\InboxTools;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Notifications\NumberQualityNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells a workspace's Owners, in the bell and by email, when Meta lowers a number's standing:
 * quality rating down to Yellow or Red, the number flagged, or its messaging limit lowered.
 * Red or flagged also pauses the number's running campaigns straight away. Each change is
 * announced once (repeated webhooks for the same change within 12 hours send nothing).
 */
final class NumberQualityAlerts
{
    private const RANK = ['GREEN' => 3, 'YELLOW' => 2, 'RED' => 1];

    public function __construct(private readonly InboxTools $inbox, private readonly WorkspaceMailer $mailer) {}

    /** After a re-read from Meta: the rating went down (Green → Yellow, anything → Red). */
    public function ratingChanged(PhoneNumber $number, ?string $old, ?string $new): void
    {
        $old = $old !== null ? strtoupper($old) : null;
        $new = $new !== null ? strtoupper($new) : null;
        $lower = in_array($new, ['YELLOW', 'RED'], true) && $old !== $new && self::RANK[$new] < (self::RANK[$old ?? ''] ?? 4);
        if (! $lower) {
            return;
        }
        $red = $new === 'RED';
        $paused = $red ? app(ManageCampaigns::class)->autoPauseForNumber($number, 'Paused automatically: the number’s quality rating dropped to Red') : 0;

        $this->tell($number, "rating:{$new}",
            $red ? 'Quality rating dropped to Red on '.$this->label($number) : 'Quality rating dropped to Yellow on '.$this->label($number),
            ($red
                ? 'Customers are blocking or reporting messages from this number. Meta may lower its messaging limit or restrict it.'
                : 'More customers than usual are blocking or reporting messages from this number. If it reaches Red, Meta may lower its messaging limit.')
            .($paused > 0 ? " {$paused} running ".($paused === 1 ? 'campaign was' : 'campaigns were').' paused.' : '')
            .' Send fewer marketing messages and only to people who asked for them.');
    }

    /** From the phone_number_quality_update webhook: FLAGGED, or the messaging limit lowered. */
    public function webhookEvent(PhoneNumber $number, string $event, ?string $limit, int $pausedCampaigns = 0): void
    {
        $event = strtoupper($event);
        if ($event === 'FLAGGED') {
            $this->tell($number, 'flagged', 'Meta flagged '.$this->label($number).' for low quality',
                'Customers are blocking or reporting messages from this number.'
                .($pausedCampaigns > 0 ? " {$pausedCampaigns} running ".($pausedCampaigns === 1 ? 'campaign was' : 'campaigns were').' paused.' : '')
                .' Meta lowers the messaging limit if quality doesn’t improve within 7 days. Send fewer marketing messages and only to people who asked for them.');
        } elseif ($event === 'DOWNGRADE') {
            $this->tell($number, 'downgrade:'.($limit ?? ''), 'Meta lowered the messaging limit of '.$this->label($number),
                'Because of low quality, this business can now start fewer conversations a day'.($limit !== null ? ' (new limit: '.str_replace('_', ' ', strtolower($limit)).').' : '.')
                .' Campaigns larger than the limit continue the next day.');
        }
    }

    private function label(PhoneNumber $number): string
    {
        return (string) ($number->display_phone_number ?? $number->verified_name ?? 'your WhatsApp number');
    }

    private function tell(PhoneNumber $number, string $change, string $title, string $body): void
    {
        if (! Cache::add("wa-quality-alert:{$number->id}:{$change}", 1, now()->addHours(12))) {
            return;
        }
        try {
            $owners = TenantMembership::query()->where('tenant_id', $number->tenant_id)->where('status', 'active')
                ->whereHas('role', fn ($q) => $q->where('key', SystemRole::Owner->value)->where('is_system', true))->get();
            foreach ($owners as $owner) {
                $this->inbox->notify($owner, 'number_quality', $title, $body, '/channels'); // the bell
            }
            $tenant = Tenant::query()->find($number->tenant_id);
            if ($tenant !== null) {
                $this->mailer->toOwners($tenant, new NumberQualityNotification($tenant->name, $title, $body));
            }
        } catch (Throwable $e) {
            Log::warning('Quality alert not sent', ['phone_number_id' => $number->id, 'error' => $e->getMessage()]);
        }
    }
}
