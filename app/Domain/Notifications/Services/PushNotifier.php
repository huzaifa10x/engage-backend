<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Services;

use App\Domain\Notifications\Jobs\SendPush;
use App\Domain\Notifications\Models\PushSubscription;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Infrastructure\Push\WebPush;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends a phone notification to one member's phones (every browser or installed app where they
 * turned notifications on in this workspace), if they want this kind. Queued: never slows the
 * request or webhook that caused it.
 */
final class PushNotifier
{
    public function toMember(TenantMembership $member, string $type, string $title, ?string $body, string $url, ?string $tag = null): void
    {
        try {
            if (WebPush::fromConfig() === null || ! $member->isActive()) {
                return;
            }
            $user = $member->user()->first();
            if ($user === null || ! PushPreferences::allows($user, PushPreferences::categoryFor($type))) {
                return;
            }
            if (! PushSubscription::query()->where('membership_id', $member->id)->exists()) {
                return;
            }
            if ($type === 'message' && ! PushPreferences::messageText($user)) {
                $body = 'New message'; // the person chose not to show message text on the lock screen
            }

            SendPush::dispatch($member->id, [
                'title' => mb_substr($title, 0, 120),
                'body' => $body !== null ? mb_substr($body, 0, 240) : null,
                'url' => $url,
                'tag' => $tag ?? $type,
                'kind' => $type,
            ]);
        } catch (Throwable $e) {
            Log::warning('Push notification not queued', ['member' => $member->id, 'error' => $e->getMessage()]);
        }
    }
}
