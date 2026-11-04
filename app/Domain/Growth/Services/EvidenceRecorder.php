<?php

declare(strict_types=1);

namespace App\Domain\Growth\Services;

use App\Domain\Growth\GrowthSettings;
use App\Domain\Growth\Models\GrowthEvidence;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Message;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns proof on an inbound message into evidence, then projects it onto the CRM contact:
 *  - original source: only from the contact's first inbound message, only for contacts created
 *    after tracking started, and never overwritten;
 *  - latest touch: the most recent confirmed evidence;
 *  - first tracked enquiry: the earliest evidence.
 * Idempotent per message (a repeated webhook changes nothing). The raw message is never changed.
 */
class EvidenceRecorder
{
    public function __construct(private readonly MetaAds $ads) {}

    /** Meta click-to-WhatsApp referral on this message → evidence. Returns null when there is none or it was already recorded. */
    public function recordMetaReferral(Message $message, Tenant $tenant): ?GrowthEvidence
    {
        $fields = MetaReferral::parse(((array) ($message->content ?? []))['referral'] ?? null);
        if ($fields === null || $message->wamid === null) {
            return null;
        }

        $occurredAt = Carbon::parse($message->getAttribute('meta_timestamp') ?? $message->created_at ?? now());

        return DB::transaction(function () use ($message, $tenant, $fields, $occurredAt) {
            // Facebook or Instagram only with proof: the ad link itself, or the ad tagged as one
            // platform only in Tracking Setup. Otherwise plain "Meta ad".
            $tagged = $this->ads->seen($tenant, $fields, $occurredAt);
            $proven = MetaReferral::platformFromUrl($fields['source_url'] ?? null)
                ?? ($tagged !== null ? ['platform' => $tagged, 'proof' => MetaAds::TAG_PROOF[$tagged]] : null);

            $id = (string) Str::uuid7();
            $inserted = DB::table('growth_evidence')->insertOrIgnore([
                'id' => $id,
                'tenant_id' => $tenant->id,
                'crm_contact_id' => $message->contact_id,
                'conversation_id' => $message->conversation_id,
                'phone_number_id' => $message->getAttribute('phone_number_id'),
                'message_id' => $message->id,
                'wa_message_id' => $message->wamid,
                'click_id' => null,
                'evidence_type' => GrowthEvidence::TYPE_META_CTWA,
                'confidence' => 'confirmed',
                'source' => 'meta_ad',
                // The referral has no platform field: platform and its proof stay empty until proven.
                'provider_fields' => json_encode(['referral' => $fields, 'platform' => $proven['platform'] ?? null, 'platform_proof' => $proven['proof'] ?? null], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'conflict' => false,
                'occurred_at' => $occurredAt,
                'created_at' => now(),
            ]);
            if ($inserted === 0) {
                return null; // same message already recorded
            }

            /** @var GrowthEvidence $evidence */
            $evidence = GrowthEvidence::query()->findOrFail($id);
            $this->project($evidence, $tenant, [
                'source' => 'meta_ad',
                'label' => MetaReferral::label($proven['platform'] ?? null, $fields['source_type'] ?? null),
                'platform' => $proven['platform'] ?? null,
                'source_type' => $fields['source_type'] ?? null, // ad | post: paid ads vs boosted posts
                'headline' => $fields['headline'] ?? null,
                'ad_id' => $fields['source_id'] ?? null, // groups leads by ad; never shown to agents
                'campaign' => null, // source_id is not a campaign ID: never shown as one
                'landing_path' => null,
            ]);

            return $evidence;
        });
    }

    /** @param array<string, mixed> $touch */
    private function project(GrowthEvidence $evidence, Tenant $tenant, array $touch): void
    {
        /** @var ?Contact $contact */
        $contact = Contact::query()->withTrashed()->lockForUpdate()->find($evidence->crm_contact_id);
        if ($contact === null) {
            return;
        }

        $touch = ['evidence_id' => $evidence->id, 'type' => $evidence->evidence_type, 'occurred_at' => $evidence->occurred_at->toIso8601String()] + $touch;
        $updates = [];

        $latest = $contact->getAttribute('acquisition_latest');
        $latestAt = is_array($latest) && isset($latest['occurred_at']) ? Carbon::parse($latest['occurred_at']) : null;
        if ($latestAt === null || $evidence->occurred_at->gte($latestAt)) {
            $updates['acquisition_latest'] = $touch;
        }

        $first = $contact->getAttribute('first_tracked_enquiry_at');
        if ($first === null || $evidence->occurred_at->lt(Carbon::parse($first))) {
            $updates['first_tracked_enquiry_at'] = $evidence->occurred_at;
        }

        if ($contact->getAttribute('acquisition_original') === null
            && ! Acquisition::beforeTracking($contact, GrowthSettings::for($tenant))
            && $this->isFirstInbound($contact, $evidence)) {
            $updates['acquisition_original'] = $touch;
        }

        if ($updates !== []) {
            $contact->forceFill($updates)->save();
        }
    }

    /** The evidence's message is the first thing this contact ever sent: it may set the original source. */
    private function isFirstInbound(Contact $contact, GrowthEvidence $evidence): bool
    {
        return ! Message::query()->where('contact_id', $contact->id)->where('direction', Message::INBOUND)
            ->where('id', '!=', $evidence->message_id)
            ->where(fn ($q) => $q->where('meta_timestamp', '<', $evidence->occurred_at)
                ->orWhere(fn ($q) => $q->whereNull('meta_timestamp')->where('created_at', '<', $evidence->occurred_at)))
            ->exists();
    }
}
