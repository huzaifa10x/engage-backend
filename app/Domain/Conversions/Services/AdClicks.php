<?php

declare(strict_types=1);

namespace App\Domain\Conversions\Services;

use App\Domain\Conversions\Models\AdClick;
use App\Domain\Messaging\Models\Message;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Click-to-WhatsApp ad clicks, taken from the referral Meta puts on the first message from an ad.
 * The click ID (ctwa_clid) is stored exactly as Meta sent it. Recording is idempotent per message,
 * and a conversion uses the most recent click on its chat.
 */
final class AdClicks
{
    /** @return ?array<string, mixed> the raw referral on an incoming message, or null */
    public static function referral(Message $message): ?array
    {
        $referral = ((array) ($message->content ?? []))['referral'] ?? null;

        return is_array($referral) && (isset($referral['ctwa_clid']) || isset($referral['source_id']) || isset($referral['source_url'])) ? $referral : null;
    }

    /** Saves the click from this message's referral. Returns false when there is none or it is already saved. */
    public function record(Message $message): bool
    {
        $referral = self::referral($message);
        if ($referral === null || $message->direction !== Message::INBOUND) {
            return false;
        }
        $wabaId = DB::table('phone_numbers as p')->join('waba_accounts as w', 'w.id', '=', 'p.waba_account_id')
            ->where('p.id', $message->getAttribute('phone_number_id'))->value('w.waba_id');
        if (! is_string($wabaId)) {
            return false;
        }
        $text = fn (string $key, ?int $max = null) => is_scalar($referral[$key] ?? null) && (string) $referral[$key] !== ''
            ? ($max !== null ? mb_substr((string) $referral[$key], 0, $max) : (string) $referral[$key]) : null;

        return DB::table('ad_clicks')->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $message->tenant_id,
            'conversation_id' => $message->conversation_id,
            'contact_id' => $message->contact_id,
            'message_id' => $message->id,
            'waba_id' => $wabaId,
            // Exactly as Meta sent it: no trimming, no case change, no length cut.
            'ctwa_clid' => is_scalar($referral['ctwa_clid'] ?? null) && (string) $referral['ctwa_clid'] !== '' ? (string) $referral['ctwa_clid'] : null,
            'source_id' => $text('source_id', 64),
            'source_type' => $text('source_type', 20),
            'source_url' => $text('source_url'),
            'headline' => $text('headline'),
            'clicked_at' => Carbon::parse($message->getAttribute('meta_timestamp') ?? $message->created_at ?? now()),
            'created_at' => now(),
        ]) > 0;
    }

    /** The most recent click on a chat, up to a moment (a conversion uses it). */
    public function latestForConversation(string $conversationId, ?Carbon $before = null): ?AdClick
    {
        return AdClick::query()->where('conversation_id', $conversationId)
            ->when($before, fn ($q, Carbon $at) => $q->where('clicked_at', '<=', $at))
            ->orderByDesc('clicked_at')->first();
    }

    /** The most recent click of a contact on any chat, up to a moment (an order matched by phone only). */
    public function latestForContact(string $contactId, ?Carbon $before = null): ?AdClick
    {
        return AdClick::query()->where('contact_id', $contactId)
            ->when($before, fn ($q, Carbon $at) => $q->where('clicked_at', '<=', $at))
            ->orderByDesc('clicked_at')->first();
    }
}
