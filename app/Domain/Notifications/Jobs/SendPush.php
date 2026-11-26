<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Jobs;

use App\Domain\Messaging\Services\InboxCounts;
use App\Domain\Notifications\Models\PushSubscription;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Infrastructure\Push\WebPush;
use App\Support\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers one notification to each of a member's subscribed phones, with the number of chats
 * waiting for them (the app-icon badge). A subscription the push service says is gone (404/410),
 * or that failed 5 times in a row, is removed.
 */
final class SendPush implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @param array<string, mixed> $payload */
    public function __construct(public readonly string $membershipId, public readonly array $payload, public readonly ?string $subscriptionId = null)
    {
        $this->onQueue(QueueName::Notifications->value);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(InboxCounts $counts): void
    {
        $push = WebPush::fromConfig();
        $member = TenantMembership::query()->with('role')->find($this->membershipId);
        if ($push === null || $member === null) {
            return;
        }
        $subscriptions = PushSubscription::query()->where('membership_id', $member->id)
            ->when($this->subscriptionId, fn ($q, $id) => $q->whereKey($id))->get();
        if ($subscriptions->isEmpty()) {
            return;
        }

        $badge = null;
        try {
            $badge = $counts->counts($member, null)['needs_reply'];
        } catch (Throwable) {
            // the badge is a nicety
        }
        $payload = (string) json_encode($this->payload + ['badge' => $badge], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        foreach ($subscriptions as $subscription) {
            try {
                $status = $push->send($subscription->endpoint, $subscription->p256dh, $subscription->auth, $payload, topic: (string) ($this->payload['tag'] ?? ''));
            } catch (Throwable $e) {
                Log::info('Push send failed', ['subscription' => $subscription->id, 'error' => $e->getMessage()]);
                $status = 0;
            }
            if ($status === 404 || $status === 410) {
                $subscription->delete(); // the browser unsubscribed or the app was removed

                continue;
            }
            if ($status >= 200 && $status < 300) {
                $subscription->forceFill(['last_sent_at' => now(), 'failures' => 0])->save();

                continue;
            }
            $failures = $subscription->failures + 1;
            $failures >= 5 ? $subscription->delete() : $subscription->forceFill(['failures' => $failures])->save();
        }
    }
}
