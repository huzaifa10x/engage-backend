<?php

declare(strict_types=1);

namespace App\Domain\Campaigns\Jobs;

use App\Application\Campaigns\ManageCampaigns;
use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Messaging\Models\Contact;
use App\Support\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Step 1 of a campaign: evaluate the audience rule now, apply the consent gate to every
 * matching contact and write one recipient row each (pending, or skipped with the reason).
 * Step 2 (SendCampaignBatch) then works through the pending rows.
 */
final class LaunchCampaign implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly string $campaignId)
    {
        $this->onQueue(QueueName::Default->value);
    }

    public function handle(ManageCampaigns $campaigns): void
    {
        $campaign = Campaign::query()->find($this->campaignId);
        if ($campaign === null || $campaign->status !== CampaignStatus::Sending) {
            return;
        }

        $matched = 0;
        $eligible = 0;
        $marketing = strtoupper((string) $campaign->template_category) === 'MARKETING';
        $campaigns->audienceQuery($campaign->audience)->chunkById(1000, function ($contacts) use ($campaign, $campaigns, $marketing, &$matched, &$eligible): void {
            $rows = [];
            $now = now();
            $capped = $marketing ? $campaigns->cappedContactIds($contacts->modelKeys(), $campaign->id) : [];
            /** @var Contact $contact */
            foreach ($contacts as $contact) {
                $blocker = $contact->campaignBlocker($campaign->template_category) ?? (isset($capped[$contact->id]) ? 'Frequency cap reached' : null);
                $matched++;
                $eligible += $blocker === null ? 1 : 0;
                $rows[] = [
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $campaign->tenant_id,
                    'campaign_id' => $campaign->id,
                    'contact_id' => $contact->id,
                    'status' => $blocker === null ? 'pending' : 'skipped',
                    'reason' => $blocker,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            // insertOrIgnore: a re-run never adds a contact twice (unique campaign + contact).
            DB::table('campaign_recipients')->insertOrIgnore($rows);
        });

        $campaign->forceFill(['matched_count' => $matched, 'eligible_count' => $eligible, 'started_at' => $campaign->started_at ?? now()])->save();

        SendCampaignBatch::dispatch($campaign->id);
    }

    public function failed(?Throwable $e): void
    {
        Campaign::query()->whereKey($this->campaignId)->where('status', CampaignStatus::Sending->value)->update([
            'status' => CampaignStatus::Failed->value,
            'failure_reason' => mb_substr('The campaign could not be prepared: '.($e?->getMessage() ?? 'unknown error'), 0, 300),
            'completed_at' => now(),
        ]);
    }
}
