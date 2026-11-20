<?php

declare(strict_types=1);

namespace App\Domain\Crm\Services;

use App\Domain\Access\Permission;
use App\Domain\Audit\AuditLogger;
use App\Domain\Crm\Exceptions\StageConflict;
use App\Domain\Crm\Models\ContactStageEvent;
use App\Domain\Crm\Models\CrmStage;
use App\Domain\Developer\ApiCaller;
use App\Domain\Growth\GrowthAccess;
use App\Domain\Growth\Jobs\RefreshMarketingAnalytics;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Timeline\Enquiries;
use App\Domain\Timeline\Enquiry;
use App\Domain\Timeline\Timeline;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only way a lead's stage changes (batch 6, item 4). A stage belongs to an enquiry: one buying
 * conversation. The contact shows its current enquiry's stage, so lists, segments and filters keep
 * working.
 *
 * Every change, from the inbox, the contact, a flow step, a booking, a verified payment, the lead rule
 * or the API, goes through move():
 *  - it checks the version the screen loaded; if the stage changed since, nothing is overwritten
 *    ("This lead was updated by [name]");
 *  - Lost needs a reason when a person or the API moves it;
 *  - Won needs a verified payment (Stripe, WooCommerce or an authorised person) or a system-confirmed
 *    booking; otherwise anyone who can reply marks it with a reference (3.3, A1);
 *  - it logs the change (from, to, who or what, reason, new version), which the timeline shows.
 */
final class EnquiryStages
{
    /** Sources a person or an API key stands behind: a Lost reason is asked for. */
    private const INTERACTIVE = ['user', 'api'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
        private readonly Enquiries $enquiries,
    ) {}

    /**
     * @param  ?int  $expectedVersion  the stage version the screen (or API caller) loaded; null skips the check (automation)
     * @param  ?string  $reason  lost reason, Won approval reference, or a note
     * @param  array{payment?: string, booking?: string}  $proof  a verified payment or a system-confirmed booking that justifies Won
     */
    public function move(Contact $contact, ?string $stageId, string $source = 'user', ?int $expectedVersion = null, ?string $reason = null, array $proof = [], ?Enquiry $enquiry = null): ?ContactStageEvent
    {
        $stage = null;
        if ($stageId !== null) {
            $stage = CrmStage::query()->active()->find($stageId)
                ?? throw ValidationException::withMessages(['stage_id' => 'Choose one of your active stages.']);
        }
        $reason = $reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 200) : null;

        return DB::transaction(function () use ($contact, $stage, $source, $expectedVersion, $reason, $proof, $enquiry) {
            /** @var Contact $locked */
            $locked = Contact::query()->lockForUpdate()->findOrFail($contact->id);
            $enquiry ??= $this->enquiries->current($locked, $source === 'user' ? Timeline::AGENT : Timeline::SYSTEM);
            /** @var Enquiry $lead */
            $lead = Enquiry::query()->lockForUpdate()->findOrFail($enquiry->id);

            if ($expectedVersion !== null && $lead->stage_version !== $expectedVersion) {
                throw StageConflict::by($this->lastChangedBy($lead), $lead->stage_version);
            }
            $from = $lead->stage_id;
            $to = $stage?->id;
            if ($from === $to) {
                return null;
            }

            if ($stage?->group === CrmStage::LOST && $reason === null && in_array($source, self::INTERACTIVE, true)) {
                throw ValidationException::withMessages(['reason' => 'Say why this lead was lost.']);
            }
            if ($stage?->group === CrmStage::WON) {
                $this->ensureWonAllowed($lead, $proof, $reason);
            }

            $now = now();
            $version = $lead->stage_version + 1;
            $lead->forceFill([
                'stage_id' => $to, 'stage_version' => $version, 'stage_changed_at' => $now,
                'lost_reason' => $stage?->group === CrmStage::LOST ? $reason : null,
            ])->save();
            // The contact shows its current enquiry's stage.
            if ($locked->getAttribute('current_enquiry_id') === null || $locked->getAttribute('current_enquiry_id') === $lead->id) {
                $locked->forceFill(['stage_id' => $to, 'stage_changed_at' => $now])->save();
                $contact->setRawAttributes($locked->getAttributes(), true);
            }

            $event = ContactStageEvent::query()->create([
                'contact_id' => $locked->id,
                'enquiry_id' => $lead->id,
                'from_stage_id' => $from,
                'to_stage_id' => $to,
                'changed_by_membership_id' => $source === 'user' || $source === 'api' ? $this->context->membership()?->id : null,
                'source' => $source,
                'version' => $version,
                'reason' => $reason ?? $this->proofNote($proof),
                'changed_at' => $now,
            ]);

            $this->audit->record('enquiry.stage_changed', $lead, before: ['stage_id' => $from, 'version' => $version - 1],
                after: array_filter(['stage_id' => $to, 'version' => $version, 'reason' => $reason, 'source' => $source]));

            // Analytics → Marketing counts stage changes within 30 days of the first enquiry.
            $tenant = $this->context->tenantOrNull();
            if ($tenant !== null && GrowthAccess::full($tenant)) {
                RefreshMarketingAnalytics::dispatch($tenant->id)->afterCommit();
            }
            app(ContactRecord::class)->refresh($locked);   // lifecycle moves forward (3.5)

            return $event;
        });
    }

    /** The current stage version of the contact's current enquiry (what a screen sends back with a change). */
    public static function versionOf(?string $enquiryId): int
    {
        return $enquiryId === null ? 0 : (int) (Enquiry::query()->whereKey($enquiryId)->value('stage_version') ?? 0);
    }

    /**
     * Won needs proof: a verified payment on this enquiry or a system-confirmed booking. Without one,
     * anyone who can reply to customers may mark Won with a reference (an invoice, receipt or contract
     * number, at least 3 characters); an API key needs the approve-Won permission. A staff-marked
     * booking or "I've paid" in a chat is never treated as a verified payment.
     *
     * @param  array{payment?: string, booking?: string}  $proof
     */
    private function ensureWonAllowed(Enquiry $lead, array $proof, ?string $reference): void
    {
        if (($proof['payment'] ?? null) !== null || ($proof['booking'] ?? null) !== null || $this->hasVerifiedPayment($lead)) {
            return;
        }
        $person = $this->context->membership() !== null && ApiCaller::current() === null;
        $allowed = $person
            ? ($this->context->can(Permission::InboxReply) || $this->context->can(Permission::StagesApproveWon))
            : ApiCaller::allows('stages:approve_won');
        if (! $allowed) {
            throw ValidationException::withMessages(['stage_id' => $person
                ? 'Only teammates who reply to customers can mark Won without a verified payment.'
                : 'Won needs a verified payment. An API key with the approve Won permission can mark it with a reference.']);
        }
        if ($reference === null || mb_strlen($reference) < 3) {
            throw ValidationException::withMessages(['reason' => 'Add a reference for marking Won without a verified payment (an invoice, receipt or contract number).']);
        }
    }

    /** A paid store order on this enquiry (or the contact's, placed since it started). */
    private function hasVerifiedPayment(Enquiry $lead): bool
    {
        return DB::table('commerce_orders')->where('contact_id', $lead->contact_id)->where('paid', true)
            ->where(fn ($q) => $q->where('enquiry_id', $lead->id)->orWhere(fn ($w) => $w->whereNull('enquiry_id')->where('placed_at', '>=', $lead->started_at)))
            ->exists();
    }

    private function lastChangedBy(Enquiry $lead): ?string
    {
        $last = ContactStageEvent::query()->where('enquiry_id', $lead->id)->orderByDesc('version')->first(['changed_by_membership_id', 'source']);
        if ($last === null) {
            return null;
        }
        if ($last->changed_by_membership_id === null) {
            return match ($last->source) {
                'flow' => 'a chatbot flow', 'booking' => 'a booking', 'payment' => 'a payment', 'lead_rule' => 'the lead rule', default => 'an automation',
            };
        }
        $name = DB::table('tenant_memberships')->join('users', 'users.id', '=', 'tenant_memberships.user_id')
            ->where('tenant_memberships.id', $last->changed_by_membership_id)->value('users.name');

        return is_string($name) ? $name : null;
    }

    /** @param array{payment?: string, booking?: string} $proof */
    private function proofNote(array $proof): ?string
    {
        return match (true) {
            isset($proof['payment']) => 'Verified payment',
            isset($proof['booking']) => 'System-confirmed booking',
            default => null,
        };
    }
}
