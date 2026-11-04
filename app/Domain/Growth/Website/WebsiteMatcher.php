<?php

declare(strict_types=1);

namespace App\Domain\Growth\Website;

use App\Domain\Growth\Models\GrowthEvidence;
use App\Domain\Growth\Services\EvidenceRecorder;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Message;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Registration-gated matching (Technical Specification v1.2 §1, §8; Blueprint v1.4 §III):
 *  - a code at the end of an inbound message is redeemed only against an accepted click of the same
 *    workspace and receiving number, with Meta's message time inside [issued, issued + 24 h]
 *    (2 minutes early tolerated and logged);
 *  - one redemption per code: a retry of the same message is idempotent, the same sender repeating
 *    it adds nothing, another sender repeating it is never confirmed;
 *  - a message whose click has not arrived waits in the pending queue and is matched when it does;
 *    still unregistered after 24 h + 48 h grace it becomes "Website · details unavailable";
 *  - a Meta ad referral on the same message: both kept and flagged, Meta is the origin.
 * Runs on the queue, in the message's workspace. Never changes the message.
 */
final class WebsiteMatcher
{
    public const SKEW_SECONDS = 120;

    public const GRACE_HOURS = 72; // 24 h validity + 48 h for delayed delivery

    public function __construct(private readonly EvidenceRecorder $recorder) {}

    /** @return ?string what happened: matched, pending, duplicate, same_sender, reused_other_sender, expired, null when there is no code */
    public function redeem(Message $message, Tenant $tenant): ?string
    {
        if ($message->direction !== Message::INBOUND || $message->wamid === null || ! in_array($message->type, ['text', 'button', 'interactive'], true)) {
            return null;
        }
        $found = Reference::extract($message->body);
        if ($found === null) {
            return null;
        }

        $digest = Reference::digest($found['token']);
        $number = (string) $message->getAttribute('phone_number_id');
        $at = Carbon::parse($message->getAttribute('meta_timestamp') ?? $message->created_at ?? now());
        $sender = $this->sender($message);

        return DB::transaction(function () use ($message, $tenant, $digest, $number, $at, $sender, $found) {
            $ref = DB::table('growth_references')->where('tenant_id', $tenant->id)->where('destination_phone_number_id', $number)
                ->whereRaw("token_digest = decode(?, 'hex')", [$digest])->lockForUpdate()->first();

            if ($ref === null) {
                // The click may still be on its way (beacon after the message): wait for it.
                DB::table('growth_pending_references')->insertOrIgnore([
                    'tenant_id' => $tenant->id, 'destination_phone_number_id' => $number, 'wa_message_id' => $message->wamid,
                    'token_digest' => DB::raw("decode('{$digest}', 'hex')"), 'sender_wa_id' => $sender,
                    'provider_message_time' => $at, 'received_at' => now(), 'retry_until' => $at->copy()->addHours(self::GRACE_HOURS), 'status' => 'pending',
                ]);

                return 'pending';
            }

            if ($ref->consumed_at !== null) {
                if ($ref->consumed_message_id === $message->wamid) {
                    return 'duplicate';
                }
                if ($ref->consumed_sender_wa_id === $sender) {
                    return 'same_sender'; // the same person sending the code again: no new click or lead
                }
                $this->evidence($message, $tenant, $ref, 'reused_other_sender', $found['token']);
                Log::info('Growth Hub: a website code was reused by another sender.', ['tenant_id' => $tenant->id, 'message_id' => $message->id]);

                return 'reused_other_sender';
            }

            $issued = Carbon::parse((string) $ref->issued_at);
            $expires = Carbon::parse((string) $ref->expires_at);
            if ($at->lt($issued->copy()->subSeconds(self::SKEW_SECONDS)) || $at->gt($expires)) {
                return 'expired';
            }
            if ($at->lt($issued)) {
                Log::info('Growth Hub: message time before its click (clock skew within tolerance).', ['tenant_id' => $tenant->id, 'skew_seconds' => $issued->diffInSeconds($at, true)]);
            }

            DB::table('growth_references')->where('id', $ref->id)->update(['consumed_at' => now(), 'consumed_message_id' => $message->wamid, 'consumed_sender_wa_id' => $sender]);
            DB::table('growth_click_events')->where('id', $ref->click_id)->update(['status' => 'matched']);
            DB::table('growth_site_health')->where('site_id', $ref->site_id)->update(['last_match_at' => now()]);
            DB::table('growth_pending_references')->where('tenant_id', $tenant->id)->where('destination_phone_number_id', $ref->destination_phone_number_id)
                ->where('wa_message_id', $message->wamid)->update(['status' => 'matched']);
            $this->evidence($message, $tenant, $ref, 'confirmed', $found['token']);

            return 'matched';
        });
    }

    /** A click just registered: match the messages that were waiting for its code. */
    public function reconcile(string $clickId, Tenant $tenant): int
    {
        $ref = DB::table('growth_references')->where('click_id', $clickId)->first(['destination_phone_number_id', DB::raw("encode(token_digest, 'hex') AS digest")]);
        if ($ref === null) {
            return 0;
        }
        $waiting = DB::table('growth_pending_references')->where('tenant_id', $tenant->id)
            ->where('destination_phone_number_id', $ref->destination_phone_number_id)
            ->whereRaw("token_digest = decode(?, 'hex')", [$ref->digest])->where('status', 'pending')
            ->orderBy('provider_message_time')->pluck('wa_message_id');

        $matched = 0;
        foreach ($waiting as $wamid) {
            $message = Message::query()->where('wamid', $wamid)->first();
            $result = $message !== null ? $this->redeem($message, $tenant) : null;
            if ($result === 'matched') {
                $matched++;
            } elseif ($result !== 'pending') {
                DB::table('growth_pending_references')->where('tenant_id', $tenant->id)->where('destination_phone_number_id', $ref->destination_phone_number_id)
                    ->where('wa_message_id', $wamid)->update(['status' => $result ?? 'invalid']);
            }
        }

        return $matched;
    }

    /**
     * Codes whose click never registered, after the grace period: the message did come from the
     * website, but the tap's details were lost (an ad blocker, no network). Never a campaign.
     */
    public function finalizePending(Tenant $tenant): int
    {
        $due = DB::table('growth_pending_references')->where('tenant_id', $tenant->id)->where('status', 'pending')
            ->where('retry_until', '<', now())->limit(500)->get(['destination_phone_number_id', 'wa_message_id']);
        $done = 0;
        foreach ($due as $p) {
            DB::transaction(function () use ($p, $tenant, &$done) {
                DB::table('growth_pending_references')->where('tenant_id', $tenant->id)->where('destination_phone_number_id', $p->destination_phone_number_id)
                    ->where('wa_message_id', $p->wa_message_id)->update(['status' => 'unregistered']);
                $message = Message::query()->where('wamid', $p->wa_message_id)->first();
                if ($message === null) {
                    return;
                }
                $occurred = Carbon::parse($message->getAttribute('meta_timestamp') ?? $message->created_at ?? now());
                $id = (string) Str::uuid7();
                $inserted = DB::table('growth_evidence')->insertOrIgnore([
                    'id' => $id, 'tenant_id' => $tenant->id, 'crm_contact_id' => $message->contact_id,
                    'conversation_id' => $message->conversation_id, 'phone_number_id' => $message->getAttribute('phone_number_id'),
                    'message_id' => $message->id, 'wa_message_id' => $message->wamid, 'click_id' => null,
                    'evidence_type' => GrowthEvidence::TYPE_WEBSITE_UNREGISTERED, 'confidence' => 'unconfirmed', 'source' => 'website',
                    'provider_fields' => json_encode(['note' => 'Valid code, the tap was never registered'], JSON_THROW_ON_ERROR),
                    'conflict' => false, 'occurred_at' => $occurred, 'created_at' => now(),
                ]);
                if ($inserted === 0) {
                    return;
                }
                /** @var GrowthEvidence $evidence */
                $evidence = GrowthEvidence::query()->findOrFail($id);
                $this->recorder->project($evidence, $tenant, [
                    'source' => 'website', 'label' => 'Website · details unavailable', 'platform' => null, 'source_type' => null,
                    'headline' => null, 'campaign' => null, 'landing_path' => null,
                ]);
                $done++;
            });
        }

        return $done;
    }

    private function sender(Message $message): string
    {
        $contact = Contact::query()->withTrashed()->find($message->contact_id);

        return (string) ($contact?->getAttribute('wa_id') ?? $contact?->getAttribute('bsuid') ?? $message->contact_id);
    }

    private function evidence(Message $message, Tenant $tenant, object $ref, string $confidence, string $token): void
    {
        $click = DB::table('growth_click_events')->where('id', $ref->click_id)->first();
        $site = DB::table('growth_sites')->where('id', $ref->site_id)->first(['domain']);
        if ($click === null) {
            return;
        }
        $campaign = (array) json_decode((string) $click->campaign_json, true);
        $src = WebsiteSource::classify($campaign, $click->referrer_host, $site->domain ?? null);
        $clickIds = array_filter(array_intersect_key($campaign, array_flip(WebsiteSource::CLICK_ID_KEYS)));
        $utm = array_filter(array_intersect_key($campaign, array_flip(['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'])));
        $landing = $click->landing_path ?: $click->page_path;

        // Meta ad referral on the same message: both kept, flagged; Meta is the message's origin.
        $conflict = GrowthEvidence::query()->where('wa_message_id', $message->wamid)->where('evidence_type', GrowthEvidence::TYPE_META_CTWA)->exists();
        $occurred = Carbon::parse($message->getAttribute('meta_timestamp') ?? $message->created_at ?? now());
        $id = (string) Str::uuid7();
        $inserted = DB::table('growth_evidence')->insertOrIgnore([
            'id' => $id, 'tenant_id' => $tenant->id, 'crm_contact_id' => $message->contact_id,
            'conversation_id' => $message->conversation_id, 'phone_number_id' => $message->getAttribute('phone_number_id'),
            'message_id' => $message->id, 'wa_message_id' => $message->wamid, 'click_id' => $ref->click_id,
            'evidence_type' => GrowthEvidence::TYPE_WEBSITE, 'confidence' => $confidence,
            'source' => $confidence === 'confirmed' ? $src['source'] : 'unattributed',
            'provider_fields' => json_encode([
                'site' => $site->domain ?? null,
                'page_path' => $click->page_path, 'landing_path' => $landing,
                'campaign' => $utm,
                // Kept for later conversion uploads to Google / Meta; never shown to agents.
                'click_ids' => $clickIds,
                'referrer_host' => $click->referrer_host,
                'campaign_params_origin' => $click->campaign_params_origin,
                'platform' => $src['platform'], 'platform_proof' => $src['platform_proof'],
                'reference_masked' => substr($token, 0, 4).'•••'.substr($token, -3),
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'conflict' => $conflict, 'occurred_at' => $occurred, 'created_at' => now(),
        ]);
        if ($inserted === 0 || $confidence !== 'confirmed') {
            return;
        }
        if ($conflict) {
            // Both proofs on one message: Meta stays the origin; the contact's touch says "Multiple sources".
            GrowthEvidence::query()->where('wa_message_id', $message->wamid)->where('evidence_type', GrowthEvidence::TYPE_META_CTWA)->update(['conflict' => true]);
            $this->flagConflict($message);

            return;
        }

        /** @var GrowthEvidence $evidence */
        $evidence = GrowthEvidence::query()->findOrFail($id);
        $this->recorder->project($evidence, $tenant, [
            'source' => $src['source'],
            'label' => $src['label'],
            'platform' => $src['platform'],
            'source_type' => null,
            'headline' => null,
            'campaign' => $src['campaign'],
            'landing_path' => $landing,
            'site' => $site->domain ?? null,
            'click_ids' => $clickIds, // permanent on the lead; never sent to the browser
            'paid' => $src['paid'],
        ]);
    }

    /** Mark the contact's Meta touch from this message as "Multiple sources". */
    private function flagConflict(Message $message): void
    {
        /** @var ?Contact $contact */
        $contact = Contact::query()->withTrashed()->lockForUpdate()->find($message->contact_id);
        if ($contact === null) {
            return;
        }
        $ids = GrowthEvidence::query()->where('wa_message_id', $message->wamid)->pluck('id')->all();
        $updates = [];
        foreach (['acquisition_original', 'acquisition_latest'] as $col) {
            $touch = $contact->getAttribute($col);
            if (is_array($touch) && in_array($touch['evidence_id'] ?? null, $ids, true)) {
                $updates[$col] = ['conflict' => true] + $touch;
            }
        }
        if ($updates !== []) {
            $contact->forceFill($updates)->save();
        }
    }
}
