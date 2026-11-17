<?php

declare(strict_types=1);

namespace App\Domain\Timeline;

use App\Domain\Conversions\Services\AdClicks;
use App\Domain\Growth\Links\LinkCode;
use App\Domain\Growth\Links\WaLink;
use App\Domain\Growth\Website\Reference;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Message;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Enquiries: one buying conversation each.
 *
 * A new enquiry starts when a contact writes for the first time, writes again after the quiet period
 * (default 30 days, per workspace), arrives from a new Click-to-WhatsApp ad click, or when a teammate
 * starts one by hand. Every other message continues the contact's current enquiry.
 *
 * Source, never guessed:
 *  - verified: a Click-to-WhatsApp click ID, or one of our tracked links (wa.me link / QR / website
 *    button code, or a website code we matched to its click);
 *  - partial: a campaign label without a click ID (an ad referral without ctwa_clid, a website code
 *    not matched yet);
 *  - unknown: anything else ("Direct").
 */
final class Enquiries
{
    public const DEFAULT_QUIET_DAYS = 30;

    public function __construct(private readonly TenantContext $context) {}

    public static function quietDays(?Tenant $tenant): int
    {
        $days = (($tenant->settings ?? [])['enquiries']['quiet_days'] ?? null);

        return is_numeric($days) ? max(1, min(365, (int) $days)) : self::DEFAULT_QUIET_DAYS;
    }

    /** The enquiry an incoming customer message belongs to: the current one, or a new one. */
    public function forInbound(Message $message, Contact $contact): Enquiry
    {
        $at = Carbon::parse($message->getAttribute('meta_timestamp') ?? $message->created_at ?? now());
        $source = $this->sourceOf($message);
        /** @var ?Enquiry $current */
        $current = $contact->getAttribute('current_enquiry_id') !== null ? Enquiry::query()->find($contact->getAttribute('current_enquiry_id')) : null;

        $reason = null;
        if ($current === null) {
            $reason = 'first_message';
        } elseif ($source['click_hash'] !== null && ($current->source_detail['click_hash'] ?? null) !== $source['click_hash']) {
            $reason = 'ad_click';
        } else {
            $previous = Message::query()->where('contact_id', $contact->id)->where('direction', Message::INBOUND)->whereKeyNot($message->id)
                ->where('created_at', '<=', $message->created_at ?? now())->max('created_at');
            $last = $current->started_at;
            foreach ([$previous !== null ? Carbon::parse((string) $previous) : null, $current->last_activity_at] as $candidate) {
                if ($candidate !== null && $candidate->gt($last)) {
                    $last = $candidate;
                }
            }
            if ($last->lt($at->copy()->subDays(self::quietDays($this->context->tenantOrNull())))) {
                $reason = 'quiet_period';
            }
        }

        if ($reason === null) {
            $current->forceFill(['last_activity_at' => $at, 'conversation_id' => $current->conversation_id ?? $message->conversation_id])->save();
            // A later message can bring proof the first one did not have (a link code, an ad referral).
            if ($current->source_confidence !== 'verified' && $source['confidence'] === 'verified') {
                $this->confirm($current, $source, $message);
            }

            return $current;
        }

        return $this->start($contact, $reason, Timeline::CUSTOMER, $source, $at, $message->conversation_id, $message->id);
    }

    /** A teammate starts a new enquiry by hand (a new booking request from an existing customer). */
    public function manual(Contact $contact, ?string $note = null): Enquiry
    {
        $conversation = DB::table('conversations')->where('contact_id', $contact->id)->orderByDesc('last_message_at')->value('id');

        return $this->start($contact, 'manual', Timeline::AGENT, ['source' => 'direct', 'confidence' => 'unknown', 'detail' => array_filter(['note' => $note !== null ? mb_substr($note, 0, 200) : null]), 'click_hash' => null],
            now(), is_string($conversation) ? $conversation : null, null);
    }

    /** A website code was matched to its click (Growth Hub): the enquiry's source becomes verified. */
    public function websiteConfirmed(Message $message, ?string $site): void
    {
        $enquiry = Enquiry::query()->where('contact_id', $message->contact_id)->where('source', 'website')->where('source_confidence', 'partial')
            ->where('started_at', '<=', $message->created_at ?? now())->orderByDesc('started_at')->first();
        if ($enquiry !== null) {
            $this->confirm($enquiry, ['source' => 'website', 'confidence' => 'verified', 'detail' => array_filter(['site' => $site]), 'click_hash' => null], $message);
        }
    }

    /**
     * @param  array{source: string, confidence: string, detail: array<string, mixed>, click_hash: ?string}  $source
     */
    private function start(Contact $contact, string $reason, string $by, array $source, Carbon $at, ?string $conversationId, ?string $messageId): Enquiry
    {
        $number = (int) Enquiry::query()->where('contact_id', $contact->id)->max('number') + 1;
        $owner = $conversationId !== null ? DB::table('conversations')->where('id', $conversationId)->value('assigned_membership_id') : null;
        $enquiry = Enquiry::query()->create([
            'contact_id' => $contact->id, 'number' => $number, 'conversation_id' => $conversationId, 'started_at' => $at, 'started_by' => $by,
            'start_reason' => $reason, 'source' => $source['source'], 'source_confidence' => $source['confidence'],
            'source_detail' => $source['detail'] + array_filter(['click_hash' => $source['click_hash']]),
            'owner_membership_id' => is_string($owner) ? $owner : null, 'stage_id' => $contact->getAttribute('stage_id'), 'last_activity_at' => $at,
        ]);
        DB::table('contacts')->where('id', $contact->id)->update(['current_enquiry_id' => $enquiry->id]);
        $contact->setAttribute('current_enquiry_id', $enquiry->id);

        Timeline::record($contact->id, 'enquiry.started', $by, 'enquiry', $enquiry->id, [
            'number' => $number, 'reason' => $reason, 'source' => $source['source'], 'confidence' => $source['confidence'],
            'source_label' => self::label($source), 'message_id' => $messageId,
        ], $at, $enquiry->id);

        return $enquiry;
    }

    /** @param array{source: string, confidence: string, detail: array<string, mixed>, click_hash: ?string} $source */
    private function confirm(Enquiry $enquiry, array $source, Message $message): void
    {
        $enquiry->forceFill(['source' => $source['source'], 'source_confidence' => 'verified',
            'source_detail' => array_merge((array) $enquiry->source_detail, $source['detail'], array_filter(['click_hash' => $source['click_hash']]))])->save();
        Timeline::record($enquiry->contact_id, 'enquiry.source_confirmed', Timeline::SYSTEM, 'message', $message->id,
            ['source' => $source['source'], 'confidence' => 'verified', 'source_label' => self::label($source)], null, $enquiry->id);
    }

    /**
     * Where a message says it came from, and how sure that is.
     *
     * @return array{source: string, confidence: string, detail: array<string, mixed>, click_hash: ?string}
     */
    public function sourceOf(Message $message): array
    {
        $referral = AdClicks::referral($message);
        if ($referral !== null) {
            $clid = isset($referral['ctwa_clid']) && is_scalar($referral['ctwa_clid']) && (string) $referral['ctwa_clid'] !== '' ? (string) $referral['ctwa_clid'] : null;
            $detail = array_filter(['ad_id' => isset($referral['source_id']) ? (string) $referral['source_id'] : null,
                'headline' => isset($referral['headline']) ? mb_substr((string) $referral['headline'], 0, 120) : null,
                'source_type' => isset($referral['source_type']) ? (string) $referral['source_type'] : null]);

            return ['source' => 'ad', 'confidence' => $clid !== null ? 'verified' : 'partial', 'detail' => $detail,
                // Only a hash: the click ID itself stays with the ad click record (Conversions).
                'click_hash' => $clid !== null ? substr(hash('sha256', $clid), 0, 32) : null];
        }
        $code = LinkCode::extract($message->body);
        if ($code !== null) {
            $link = WaLink::query()->where('code', $code['code'])->where('phone_number_id', $message->getAttribute('phone_number_id'))->first(['id', 'name', 'kind']);
            if ($link !== null) {
                return ['source' => 'link', 'confidence' => 'verified', 'detail' => ['link_id' => $link->id, 'link_name' => $link->name, 'kind' => $link->kind], 'click_hash' => null];
            }
        }
        if (Reference::extract($message->body) !== null) {
            // A website code is matched to its click in the background: verified once it is.
            return ['source' => 'website', 'confidence' => 'partial', 'detail' => [], 'click_hash' => null];
        }

        return ['source' => 'direct', 'confidence' => 'unknown', 'detail' => [], 'click_hash' => null];
    }

    /** @param array{source: string, confidence?: string, detail: array<string, mixed>} $source */
    public static function label(array $source): string
    {
        $detail = $source['detail'];

        return match ($source['source']) {
            'ad' => isset($detail['headline']) ? 'Ad: “'.$detail['headline'].'”' : 'Click-to-WhatsApp ad',
            'link' => 'Link: '.($detail['link_name'] ?? 'WhatsApp link'),
            'website' => 'Website'.(isset($detail['site']) ? ' ('.$detail['site'].')' : ''),
            default => 'Direct',
        };
    }
}
