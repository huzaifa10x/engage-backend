<?php

declare(strict_types=1);

namespace App\Domain\Growth\Links;

use App\Domain\Growth\GrowthAccess;
use App\Domain\Growth\Jobs\RefreshMarketingAnalytics;
use App\Domain\Growth\Models\GrowthEvidence;
use App\Domain\Growth\Services\EvidenceRecorder;
use App\Domain\Messaging\Enums\ConsentState;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Services\ConsentService;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A chat that starts with a link's code ("… (Ref: EX-7PK9M2)"): counted on the link, the link's name
 * becomes the source of a contact it brought in, the link's consent wording records an opt-in when the
 * customer sent it unchanged, and Growth Hub gets the touch ("WhatsApp link or QR code").
 * Idempotent per message; never changes the message.
 */
final class LinkChats
{
    public const SOURCE = 'wa_link';

    public function __construct(private readonly EvidenceRecorder $recorder) {}

    public function record(Message $message, Tenant $tenant): ?WaLink
    {
        $found = LinkCode::extract($message->body);
        if ($found === null || $message->direction !== Message::INBOUND) {
            return null;
        }
        /** @var ?WaLink $link */
        $link = WaLink::query()->where('code', $found['code'])->where('phone_number_id', $message->getAttribute('phone_number_id'))->first();
        if ($link === null) {
            return null;
        }

        return DB::transaction(function () use ($message, $tenant, $link, $found) {
            /** @var ?Contact $contact */
            $contact = Contact::query()->lockForUpdate()->find($message->contact_id);
            if ($contact === null) {
                return null;
            }
            $first = ! Message::query()->where('contact_id', $contact->id)->where('direction', Message::INBOUND)->where('id', '!=', $message->id)
                ->where('created_at', '<=', $message->created_at ?? now())->exists();

            $inserted = DB::table('wa_link_chats')->insertOrIgnore([
                'id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id, 'wa_link_id' => $link->id, 'contact_id' => $contact->id,
                'conversation_id' => $message->conversation_id, 'message_id' => $message->id, 'new_contact' => $first, 'created_at' => now(),
            ]);
            if ($inserted === 0) {
                return null; // the same message again (a repeated webhook)
            }

            // The contact's source: only for a contact this link brought in.
            if ($first && in_array($contact->source, [null, 'inbound'], true)) {
                $contact->forceFill(['source' => self::SOURCE, 'source_detail' => $link->name])->save();
            }

            // The pre-filled text asked for marketing messages and the customer sent it as it was: an opt-in.
            if ($link->asks_consent && self::same($found['clean'], $link->message)
                && $contact->consent_state !== ConsentState::OptedOut && ! $contact->marketing_opted_out) {
                app(ConsentService::class)->optIn($contact, ConsentService::SOURCE_QR_LINK, self::label($link), $message->id, wording: $link->message);
            }

            if (GrowthAccess::recording($tenant)) {
                $this->evidence($message, $tenant, $link);
            }

            return $link;
        });
    }

    public static function label(WaLink $link): string
    {
        return $link->name.' ('.($link->kind === WaLink::KIND_WEBSITE_BUTTON ? 'website button' : 'link or QR code').')';
    }

    private static function same(string $a, string $b): bool
    {
        $n = fn (string $s) => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $s)));

        return $n($a) === $n($b);
    }

    private function evidence(Message $message, Tenant $tenant, WaLink $link): void
    {
        $id = (string) Str::uuid7();
        $occurred = Carbon::parse($message->getAttribute('meta_timestamp') ?? $message->created_at ?? now());
        $inserted = DB::table('growth_evidence')->insertOrIgnore([
            'id' => $id, 'tenant_id' => $tenant->id, 'crm_contact_id' => $message->contact_id,
            'conversation_id' => $message->conversation_id, 'phone_number_id' => $message->getAttribute('phone_number_id'),
            'message_id' => $message->id, 'wa_message_id' => (string) ($message->wamid ?? $message->id), 'click_id' => null,
            'evidence_type' => GrowthEvidence::TYPE_WA_LINK, 'confidence' => 'confirmed', 'source' => self::SOURCE,
            'provider_fields' => json_encode(['link_id' => $link->id, 'name' => $link->name, 'kind' => $link->kind], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'conflict' => false, 'occurred_at' => $occurred, 'created_at' => now(),
        ]);
        if ($inserted === 0) {
            return;
        }
        /** @var GrowthEvidence $evidence */
        $evidence = GrowthEvidence::query()->findOrFail($id);
        $this->recorder->project($evidence, $tenant, [
            'source' => self::SOURCE,
            'label' => $link->kind === WaLink::KIND_WEBSITE_BUTTON ? 'Website button' : 'WhatsApp link or QR code',
            'platform' => null,
            'source_type' => $link->kind,
            'headline' => null,
            'campaign' => $link->name,
            'link_id' => $link->id,
            'landing_path' => null,
        ]);
        if (GrowthAccess::full($tenant)) {
            RefreshMarketingAnalytics::dispatch($tenant->id, 2)->afterCommit();
        }
    }
}
