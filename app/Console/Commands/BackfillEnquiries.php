<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Messaging\Enums\MessageOrigin;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Message;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Timeline\Enquiries;
use App\Domain\Timeline\Enquiry;
use App\Domain\Timeline\Timeline;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds enquiries from the messages already received (batch 6a, item 0), with the same rules as live
 * ones: a new enquiry after the workspace's quiet period or a new ad click. Their source comes from
 * the first message of each enquiry, with the same proof rules. Only contacts with no enquiry yet are
 * touched, so it is safe to run again. Store orders are attached to the enquiry they fall in.
 *
 * Enquiries that start with history imported from the WhatsApp Business app are "imported": labelled so,
 * and left out of reply-time reports and the audit baseline. It also runs by itself for a workspace when
 * its history import finishes.
 */
final class BackfillEnquiries extends Command
{
    protected $signature = 'engage:enquiries:backfill {--workspace= : Only this workspace (ID)}';

    protected $description = 'Build enquiries from earlier messages, for contacts that have none yet.';

    public function handle(TenantContext $context, Enquiries $enquiries): int
    {
        $tenants = $context->bypass(fn () => Tenant::query()->when($this->option('workspace'), fn ($q, $id) => $q->whereKey($id))->get());
        $made = 0;
        foreach ($tenants as $tenant) {
            $context->run($tenant, function () use ($tenant, $enquiries, &$made): void {
                $quiet = Enquiries::quietDays($tenant);
                Contact::query()->whereNull('current_enquiry_id')->chunkById(200, function ($contacts) use ($enquiries, $quiet, &$made): void {
                    foreach ($contacts as $contact) {
                        $made += $this->contact($contact, $enquiries, $quiet);
                    }
                });
            });
        }
        $this->info("Enquiries created: {$made}.");

        return self::SUCCESS;
    }

    private function contact(Contact $contact, Enquiries $enquiries, int $quiet): int
    {
        $current = null;
        $last = null;
        $made = 0;
        $messages = Message::query()->where('contact_id', $contact->id)->where('direction', Message::INBOUND)
            ->whereIn('origin', [MessageOrigin::Customer->value, MessageOrigin::History->value])->orderBy('created_at')->orderBy('id')->get();
        foreach ($messages as $message) {
            $at = Carbon::parse($message->getAttribute('meta_timestamp') ?? $message->created_at);
            $source = $enquiries->sourceOf($message);
            $newClick = $current !== null && $source['click_hash'] !== null && ($current->source_detail['click_hash'] ?? null) !== $source['click_hash'];
            $imported = $message->origin === MessageOrigin::History;
            // The first live message after imported history starts its own enquiry: live work is never labelled "Imported".
            $live = $current !== null && ! $imported && $current->start_reason === Enquiry::IMPORTED;
            if ($current === null || $newClick || $live || $last->lt($at->copy()->subDays($quiet))) {
                $reason = $imported ? Enquiry::IMPORTED : ($newClick ? 'ad_click' : 'backfill');
                $current = Enquiry::query()->create([
                    'contact_id' => $contact->id, 'number' => $made + 1, 'conversation_id' => $message->conversation_id, 'started_at' => $at,
                    'started_by' => Timeline::CUSTOMER, 'start_reason' => $reason, 'source' => $source['source'],
                    'source_confidence' => $source['confidence'], 'source_detail' => $source['detail'] + array_filter(['click_hash' => $source['click_hash']]),
                    'stage_id' => null, 'last_activity_at' => $at,
                ]);
                Timeline::record($contact->id, 'enquiry.started', Timeline::SYSTEM, 'enquiry', $current->id, ['number' => $current->number, 'reason' => $reason,
                    'source' => $source['source'], 'confidence' => $source['confidence'], 'source_label' => Enquiries::label($source)], $at, $current->id);
                $made++;
            }
            $current->forceFill(['last_activity_at' => $at])->save();
            $last = $at;
        }
        if ($current !== null) {
            $current->forceFill(['stage_id' => $contact->getAttribute('stage_id'), 'owner_membership_id' => DB::table('conversations')->where('id', $current->conversation_id)->value('assigned_membership_id')])->save();
            DB::table('contacts')->where('id', $contact->id)->update(['current_enquiry_id' => $current->id]);
            // Store orders: the enquiry that had started when the order was placed.
            foreach (DB::table('commerce_orders')->where('contact_id', $contact->id)->whereNull('enquiry_id')->get(['id', 'placed_at']) as $order) {
                $enquiry = Enquiry::query()->where('contact_id', $contact->id)->where('started_at', '<=', $order->placed_at)->orderByDesc('started_at')->value('id')
                    ?? Enquiry::query()->where('contact_id', $contact->id)->orderBy('started_at')->value('id');
                DB::table('commerce_orders')->where('id', $order->id)->update(['enquiry_id' => $enquiry]);
            }
        }

        return $made;
    }
}
