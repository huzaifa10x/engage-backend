<?php

declare(strict_types=1);

namespace App\Domain\Crm\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\Crm\Models\ContactStageEvent;
use App\Domain\Crm\Models\CrmStage;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only way a contact's stage changes: updates the contact and appends one stage event in
 * the same transaction (CN-01 §II.1). Moving to the stage the contact already has records nothing.
 */
final class ContactStages
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function move(Contact $contact, ?string $stageId, string $source = 'user'): ?ContactStageEvent
    {
        $stage = null;
        if ($stageId !== null) {
            $stage = CrmStage::query()->active()->find($stageId)
                ?? throw ValidationException::withMessages(['stage_id' => 'Choose one of your active stages.']);
        }

        return DB::transaction(function () use ($contact, $stage, $source) {
            /** @var Contact $locked */
            $locked = Contact::query()->lockForUpdate()->findOrFail($contact->id);
            $from = $locked->getAttribute('stage_id');
            $to = $stage?->id;
            if ($from === $to) {
                return null;
            }

            $now = now();
            $locked->forceFill(['stage_id' => $to, 'stage_changed_at' => $now])->save();
            $contact->setRawAttributes($locked->getAttributes(), true);

            $event = ContactStageEvent::query()->create([
                'contact_id' => $locked->id,
                'from_stage_id' => $from,
                'to_stage_id' => $to,
                'changed_by_membership_id' => $this->context->membership()?->id,
                'source' => $source,
                'changed_at' => $now,
            ]);

            $this->audit->record('contact.stage_changed', $locked, before: ['stage_id' => $from], after: ['stage_id' => $to]);

            return $event;
        });
    }
}
