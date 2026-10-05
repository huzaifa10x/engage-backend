<?php

declare(strict_types=1);

namespace App\Domain\Campaigns\Jobs;

use App\Application\Messaging\SendMessage;
use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Campaigns\Models\CampaignRecipient;
use App\Domain\Campaigns\Services\Personalizer;
use App\Domain\Messaging\Enums\MessageOrigin;
use App\Domain\WhatsApp\Models\PhoneNumber;
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

    public function handle(SendMessage $send, Personalizer $personalizer): void
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

        $recipients = CampaignRecipient::query()->with('contact')->where('campaign_id', $campaign->id)
            ->where('status', 'pending')->orderBy('id')->limit(self::BATCH)->get();

        foreach ($recipients as $recipient) {
            $contact = $recipient->contact;
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
            } catch (DomainException|ValidationException $e) {
                $recipient->forceFill(['status' => 'failed', 'reason' => mb_substr($e->getMessage(), 0, 190)])->save();
            }
        }

        if ($recipients->count() === self::BATCH) {
            self::dispatch($campaign->id);

            return;
        }

        // Everything is handed to the send queue; delivery numbers keep updating from webhooks.
        Campaign::query()->whereKey($campaign->id)->where('status', CampaignStatus::Sending->value)
            ->update(['status' => CampaignStatus::Completed->value, 'completed_at' => now()]);
    }
}
