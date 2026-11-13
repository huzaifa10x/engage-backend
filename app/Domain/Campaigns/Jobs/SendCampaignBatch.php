<?php

declare(strict_types=1);

namespace App\Domain\Campaigns\Jobs;

use App\Application\Campaigns\ManageCampaigns;
use App\Application\Messaging\SendMessage;
use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Campaigns\Models\CampaignRecipient;
use App\Domain\Campaigns\Services\Personalizer;
use App\Domain\Compliance\ComplianceSettings;
use App\Domain\Messaging\Enums\MessageOrigin;
use App\Domain\Messaging\Exceptions\MessagingException;
use App\Domain\Messaging\Services\MessagingLimits;
use App\Domain\Tenancy\TenantContext;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Support\Api\ErrorCode;
use App\Support\Exceptions\DomainException;
use App\Support\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Validation\ValidationException;

/**
 * Step 2 of a campaign: turns the next batch of pending recipients into queued messages, then
 * queues itself again until none are left. Each message goes through SendMessage, so it uses
 * the same send queue, per-number rate limit and retries as an inbox message — a campaign can
 * never outrun Meta's limits. Stops as soon as the campaign is no longer "sending".
 *
 * Meta's daily messaging limit: the campaign sends only as many as the business has room for in
 * the moving 24 hours, then waits (wait_reason "daily_limit") until room frees up — or until Meta
 * raises the limit — and carries on by itself.
 */
final class SendCampaignBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    private const BATCH = 200;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(public readonly string $campaignId)
    {
        $this->onQueue(QueueName::Default->value);
    }

    public function handle(SendMessage $send, Personalizer $personalizer, ManageCampaigns $campaigns, TenantContext $context, MessagingLimits $limits): void
    {
        $campaign = Campaign::query()->find($this->campaignId);
        if ($campaign === null || $campaign->status !== CampaignStatus::Sending) {
            return;
        }
        $number = PhoneNumber::query()->find($campaign->phone_number_id);
        if ($number === null) {
            $campaign->forceFill(['status' => CampaignStatus::Failed, 'failure_reason' => 'The WhatsApp number was removed.', 'completed_at' => now()])->save();

            return;
        }

        $marketing = strtoupper((string) $campaign->template_category) === 'MARKETING';

        // Quality protection: never keep pushing marketing through a number Meta rates Red.
        if ($marketing && strtoupper((string) $number->quality_rating) === 'RED') {
            $campaigns->pause($campaign, 'Paused automatically: the number\'s quality rating dropped to Red');

            return;
        }

        // Quiet hours (workspace time zone): wait, then carry on by itself.
        $tenant = $context->tenant();
        $quietUntil = $marketing ? ComplianceSettings::for($tenant)->quietUntil(now(), $tenant->timezone ?: 'UTC') : null;
        if ($quietUntil !== null) {
            // The every-minute scheduler (engage:campaigns:dispatch) continues it at that time.
            $campaign->forceFill(['next_batch_at' => $quietUntil, 'wait_reason' => 'quiet_hours'])->save();

            return;
        }

        // Meta's daily messaging limit is used up: wait for room instead of sending into a refusal.
        if ($limits->remaining($number) === 0) {
            $this->waitForDailyLimit($campaign, $limits, $number);

            return;
        }

        // Drip: at most batch_per_hour messages per run, then wait an hour.
        // Pace: `batch_per_hour` messages per hour (set from the plan at launch), in slices of at most
        // 1,000 so no single job runs long; the wait after a slice is proportional to its size.
        $rate = $campaign->batch_per_hour !== null ? max(1, $campaign->batch_per_hour) : null;
        $limit = $rate !== null ? min($rate, 1000) : self::BATCH;
        $recipients = CampaignRecipient::query()->with('contact')->where('campaign_id', $campaign->id)
            ->where('status', 'pending')->orderBy('id')->limit($limit)->get();
        $capped = $marketing ? $campaigns->cappedContactIds($recipients->pluck('contact_id')->all(), $campaign->id) : [];
        if ($campaign->next_batch_at !== null || $campaign->getAttribute('wait_reason') !== null) {
            $campaign->forceFill(['next_batch_at' => null, 'wait_reason' => null])->save();
        }

        $limitReached = false;
        $handled = 0;
        foreach ($recipients as $recipient) {
            $handled++;
            $contact = $recipient->contact;
            if (isset($capped[$recipient->contact_id])) {
                $recipient->forceFill(['status' => 'skipped', 'reason' => 'Frequency cap reached'])->save();

                continue;
            }
            // Consent can change between preparation and sending (a STOP reply): check again.
            $blocker = $contact === null || $contact->trashed() ? 'Contact was deleted' : $contact->campaignBlocker($campaign->template_category);
            if ($blocker !== null || $contact === null) {
                $recipient->forceFill(['status' => 'skipped', 'reason' => $blocker])->save();

                continue;
            }

            $variables = $campaign->variables ?? [];
            $resolved = [
                'header' => array_map(fn (string $v) => $personalizer->resolve($v, $contact), ($variables['header'] ?? [])),
                'body' => array_map(fn (string $v) => $personalizer->resolve($v, $contact), ($variables['body'] ?? [])),
                'buttons' => array_map(fn (string $v) => $personalizer->resolve($v, $contact), ($variables['buttons'] ?? [])),
            ];
            if (in_array('', array_merge($resolved['header'], $resolved['body']), true)) {
                $recipient->forceFill(['status' => 'skipped', 'reason' => 'A personalised value is empty for this contact'])->save();

                continue;
            }

            try {
                $message = $send->toContact($number, $contact, [
                    'type' => 'template',
                    'media_id' => $campaign->media_id,
                    'template' => ['name' => $campaign->template_name, 'language' => $campaign->template_language, 'variables' => $resolved],
                ], MessageOrigin::Campaign, null, "campaign:{$campaign->id}:{$contact->id}");

                $message->forceFill(['campaign_id' => $campaign->id])->save();
                $recipient->forceFill(['status' => 'queued', 'message_id' => $message->id, 'reason' => null])->save();
            } catch (MessagingException $e) {
                if ($e->errorCode() === ErrorCode::DailyLimitReached) {
                    $limitReached = true; // this recipient stays pending for the next round

                    break;
                }
                $recipient->forceFill(['status' => 'failed', 'reason' => mb_substr($e->getMessage(), 0, 190)])->save();
            } catch (DomainException|ValidationException $e) {
                $recipient->forceFill(['status' => 'failed', 'reason' => mb_substr($e->getMessage(), 0, 190)])->save();
            }
        }

        $remaining = CampaignRecipient::query()->where('campaign_id', $campaign->id)->where('status', 'pending')->exists();
        if ($remaining) {
            if ($limitReached) {
                $this->waitForDailyLimit($campaign, $limits, $number);
            } elseif ($rate !== null) {
                $wait = (int) ceil(3600 * $handled / $rate); // seconds until the next slice
                Campaign::query()->whereKey($campaign->id)->where('status', CampaignStatus::Sending->value)
                    ->update(['next_batch_at' => now()->addSeconds(max(60, $wait)), 'wait_reason' => 'drip']);
            } else {
                self::dispatch($campaign->id);
            }

            return;
        }

        // Everything is handed to the send queue; delivery numbers keep updating from webhooks.
        Campaign::query()->whereKey($campaign->id)->where('status', CampaignStatus::Sending->value)
            ->update(['status' => CampaignStatus::Completed->value, 'completed_at' => now()]);
    }

    /** Continue when the oldest counted conversation leaves the 24-hour window (or sooner, when Meta raises the limit). */
    private function waitForDailyLimit(Campaign $campaign, MessagingLimits $limits, PhoneNumber $number): void
    {
        $at = $limits->availableAt($limits->keyFor($number));
        Campaign::query()->whereKey($campaign->id)->where('status', CampaignStatus::Sending->value)->update([
            'next_batch_at' => ($at !== null && $at->isFuture() ? $at : now())->addMinute(),
            'wait_reason' => 'daily_limit',
        ]);
    }
}
