<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Services;

use App\Domain\Campaigns\Enums\CampaignStatus;
use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Messaging\Exceptions\MessagingException;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Webhooks\Jobs\RefreshPhoneNumber;
use App\Domain\WhatsApp\Enums\PhoneNumberStatus;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Domain\WhatsApp\Models\WabaAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Meta's daily messaging limit, enforced by the platform so a business never goes over it.
 *
 * Meta: a business portfolio may start conversations with at most N different people in any
 * moving 24 hours (250, 2K, 10K, 100K or unlimited), shared by all of its numbers. A person
 * counts when they receive a message outside their 24-hour customer service window; people who
 * wrote to the business in the last 24 hours do not count.
 *
 * Here: every template sent while the conversation's window is closed is recorded in
 * business_initiations (once per person per 24 hours) inside the same transaction that queues
 * the message, under a lock per portfolio. When the limit is used up, SendMessage refuses with
 * "daily limit reached" instead of queueing, and campaigns wait until room frees up. A message
 * that never reaches Meta gives its place back.
 *
 * The limit comes from Meta (whatsapp_business_manager_messaging_limit on each number), re-read
 * hourly, on the first visit of the day, and whenever Meta sends a limit webhook. Until Meta
 * has told us anything, the lowest tier (250) applies.
 */
final class MessagingLimits
{
    public const DEFAULT_LIMIT = 250;

    public const UNLIMITED = PHP_INT_MAX;

    public const WINDOW_HOURS = 24;

    /** Meta's tier names (and the numbers its webhooks send) → people per 24 hours; null = not a limit we recognise. */
    public static function parseTier(mixed $tier): ?int
    {
        if (is_int($tier) || (is_string($tier) && ctype_digit($tier))) {
            return (int) $tier > 0 ? (int) $tier : null;
        }
        if (! is_string($tier)) {
            return null;
        }

        return match (strtoupper(trim($tier))) {
            'TIER_50' => 50,
            'TIER_250' => 250,
            'TIER_1K' => 1000,
            'TIER_2K' => 2000,
            'TIER_10K' => 10000,
            'TIER_100K' => 100000,
            'TIER_UNLIMITED', 'UNLIMITED' => self::UNLIMITED,
            default => null,
        };
    }

    /** A limit as Meta's webhooks send it (2000, "TIER_2K", "UNLIMITED") → the tier name we store; null = not a limit. */
    public static function tierName(mixed $value): ?string
    {
        $limit = self::parseTier($value);

        return match ($limit) {
            null => null,
            self::UNLIMITED => 'TIER_UNLIMITED',
            50 => 'TIER_50', 250 => 'TIER_250', 1000 => 'TIER_1K', 2000 => 'TIER_2K', 10000 => 'TIER_10K', 100000 => 'TIER_100K',
            default => (string) $limit,
        };
    }

    /** The portfolio a number belongs to (its WABA when Meta did not tell us the portfolio). */
    public function keyFor(PhoneNumber $number): string
    {
        /** @var ?WabaAccount $waba */
        $waba = $number->relationLoaded('wabaAccount') ? $number->wabaAccount : $number->wabaAccount()->first();
        $business = $waba?->getAttribute('meta_business_id');

        return is_string($business) && $business !== '' ? 'biz:'.$business : 'waba:'.($waba->waba_id ?? $number->waba_account_id);
    }

    /** People this number's business may start conversations with per 24 hours (UNLIMITED = no limit). */
    public function limitFor(PhoneNumber $number): int
    {
        $known = [];
        foreach ($this->numbersSharing($number) as $tier) {
            if (($value = self::parseTier($tier)) !== null) {
                $known[] = $value;
            }
        }

        return $known === [] ? self::DEFAULT_LIMIT : max($known);
    }

    /** @return array{limit: ?int, used: int, remaining: ?int, available_at: ?string, tier: ?string} for the UI (null = unlimited) */
    public function summary(PhoneNumber $number): array
    {
        $limit = $this->limitFor($number);
        $key = $this->keyFor($number);
        $used = $this->used($key);
        $remaining = $limit === self::UNLIMITED ? null : max(0, $limit - $used);

        return [
            'limit' => $limit === self::UNLIMITED ? null : $limit,
            'used' => $used,
            'remaining' => $remaining,
            'available_at' => $remaining === 0 ? $this->availableAt($key)?->toIso8601String() : null,
            'tier' => $number->messaging_limit_tier,
        ];
    }

    /** How many more people can be messaged right now (null = no limit). */
    public function remaining(PhoneNumber $number): ?int
    {
        $limit = $this->limitFor($number);

        return $limit === self::UNLIMITED ? null : max(0, $limit - $this->used($this->keyFor($number)));
    }

    /** When the oldest counted conversation leaves the 24-hour window (room for one more). */
    public function availableAt(string $key): ?Carbon
    {
        $oldest = DB::table('business_initiations')->where('limit_key', $key)->where('created_at', '>=', $this->cutoff())->min('created_at');

        return $oldest !== null ? Carbon::parse($oldest)->addHours(self::WINDOW_HOURS) : null;
    }

    /**
     * Counts this message against the limit, or refuses it. Call inside the transaction that
     * stores the message: the lock serialises every send of the same portfolio until commit.
     */
    public function reserve(Message $message, Conversation $conversation, Contact $contact, PhoneNumber $number): void
    {
        if ($message->type !== 'template' || $conversation->isWindowOpen()) {
            return; // inside the customer service window: free of the limit
        }
        $limit = $this->limitFor($number);
        if ($limit === self::UNLIMITED) {
            return;
        }
        $recipient = $contact->wa_id ?? ($contact->bsuid !== null ? 'bsuid:'.$contact->bsuid : null);
        if ($recipient === null) {
            return;
        }
        $key = $this->keyFor($number);
        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['wa-limit:'.$key]);

        $cutoff = $this->cutoff();
        $counted = DB::table('business_initiations')->where('limit_key', $key)->where('recipient', $recipient)->where('created_at', '>=', $cutoff)->exists();
        if ($counted) {
            return; // the same person again within 24 hours counts once
        }
        if ($this->used($key) >= $limit) {
            throw MessagingException::dailyLimitReached($limit, $this->availableAt($key) ?? now()->addHour());
        }
        DB::table('business_initiations')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $number->tenant_id,
            'limit_key' => $key,
            'recipient' => $recipient,
            'phone_number_id' => $number->id,
            'message_id' => $message->id,
            'created_at' => now(),
        ]);
    }

    /** The message never reached Meta: its place goes back to the pool. */
    public function release(Message $message): void
    {
        DB::table('business_initiations')->where('message_id', $message->id)->delete();
    }

    /**
     * The number's limit just changed (refresh or webhook). Every number of the same business
     * gets the new tier, and campaigns waiting for room continue right away when it went up.
     */
    public function limitChanged(PhoneNumber $number, ?string $old, ?string $new): void
    {
        if ($new === null || $old === $new) {
            return;
        }
        $key = $this->keyFor($number);
        foreach ($this->numberQuery($key)->where('phone_numbers.id', '!=', $number->id)->get(['phone_numbers.*']) as $other) {
            $other->forceFill(['messaging_limit_tier' => $new])->save();
        }
        $before = self::parseTier($old) ?? self::DEFAULT_LIMIT;
        if ((self::parseTier($new) ?? 0) > $before) {
            Campaign::query()->where('status', CampaignStatus::Sending->value)->where('wait_reason', 'daily_limit')
                ->update(['next_batch_at' => now(), 'wait_reason' => null]);
        }
    }

    /** Re-read every connected number's limit from Meta, at most once a day per workspace (first visit). */
    public function refreshDaily(string $tenantId): void
    {
        if (! Cache::add("wa-limit:refreshed:{$tenantId}:".now()->toDateString(), true, now()->addDay())) {
            return;
        }
        foreach (PhoneNumber::query()->where('status', PhoneNumberStatus::Connected)->pluck('id') as $id) {
            try {
                RefreshPhoneNumber::dispatch((string) $id);
            } catch (Throwable $e) {
                report($e); // never in the way of the page that asked
            }
        }
    }

    /** Rows that left the 24-hour window are kept a day longer, then removed. */
    public static function prune(): int
    {
        return DB::table('business_initiations')->where('created_at', '<', now()->subHours(self::WINDOW_HOURS * 2))->limit(50_000)->delete();
    }

    private function used(string $key): int
    {
        return DB::table('business_initiations')->where('limit_key', $key)->where('created_at', '>=', $this->cutoff())->count();
    }

    private function cutoff(): Carbon
    {
        return now()->subHours(self::WINDOW_HOURS);
    }

    /** @return list<?string> the limit tiers of every number of the same business */
    private function numbersSharing(PhoneNumber $number): array
    {
        $tiers = $this->numberQuery($this->keyFor($number))->pluck('phone_numbers.messaging_limit_tier')->all();
        $tiers[] = $number->messaging_limit_tier;

        return array_values($tiers);
    }

    /** @return Builder<PhoneNumber> */
    private function numberQuery(string $key): Builder
    {
        [$type, $id] = explode(':', $key, 2);

        return PhoneNumber::query()->join('waba_accounts', 'waba_accounts.id', '=', 'phone_numbers.waba_account_id')
            ->where($type === 'biz' ? 'waba_accounts.meta_business_id' : 'waba_accounts.waba_id', $id)
            ->where('phone_numbers.status', '!=', PhoneNumberStatus::Disconnected->value);
    }
}
