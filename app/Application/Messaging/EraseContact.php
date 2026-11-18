<?php

declare(strict_types=1);

namespace App\Application\Messaging;

use App\Domain\Audit\AuditLogger;
use App\Domain\Messaging\Enums\ConsentState;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Media;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Deleting one contact (batch 6, item 14) removes their data: chats and messages (with the files
 * they sent), the timeline, enquiries and stage history, form answers, flow runs, feedback and
 * scheduled follow-ups. Payment records stay for the business's books, with the person's details
 * removed. Ad clicks and conversion and store events stay as counts, no longer linked to anyone.
 *
 * A contact who opted out keeps a bare record (the number and the consent history, nothing else),
 * so they are never messaged again if the number comes back in an import. Everyone else is removed
 * completely. The Audit log keeps that it happened and how much was removed, not who it was.
 */
final class EraseContact
{
    /** Rows that belong to the contact alone. */
    private const OWN_TABLES = ['form_submissions', 'feedback_requests', 'scheduled_actions', 'commerce_link_tokens', 'wa_link_chats'];

    /** Rows kept as counts, unlinked from the person. */
    private const UNLINK_TABLES = ['ad_clicks', 'conversion_events', 'campaign_recipients'];

    public function __construct(private readonly AuditLogger $audit) {}

    public function erase(Contact $contact): void
    {
        $id = $contact->id;
        $files = Media::query()->whereIn('id', DB::table('messages')->where('contact_id', $id)->where('direction', 'inbound')->whereNotNull('media_id')->select('media_id'))->get();

        $removed = DB::transaction(function () use ($contact, $id): array {
            $counts = [
                'messages' => DB::table('messages')->where('contact_id', $id)->count(),
                'enquiries' => DB::table('enquiries')->where('contact_id', $id)->count(),
            ];
            // Conversations take their messages, notes and flow runs with them.
            DB::table('conversations')->where('contact_id', $id)->delete();
            DB::table('messages')->where('contact_id', $id)->delete();
            DB::table('timeline_events')->where('contact_id', $id)->delete();
            DB::table('contact_stage_events')->where('contact_id', $id)->delete();
            DB::table('enquiries')->where('contact_id', $id)->delete();
            foreach (self::OWN_TABLES as $table) {
                DB::table($table)->where('contact_id', $id)->delete();
            }
            DB::table('chatbot_sessions')->where('contact_id', $id)->delete();
            foreach (self::UNLINK_TABLES as $table) {
                $counts[$table] = DB::table($table)->where('contact_id', $id)->count();
            }
            DB::table('campaign_recipients')->where('contact_id', $id)->delete();
            DB::table('ad_clicks')->where('contact_id', $id)->update(['contact_id' => null]);
            DB::table('conversion_events')->where('contact_id', $id)->update(['contact_id' => null]);
            DB::table('integration_events')->where('contact_id', $id)->update(['contact_id' => null, 'phone' => null]);
            // Payments: kept for the books, without who paid.
            $counts['payments_kept'] = DB::table('commerce_orders')->where('contact_id', $id)
                ->update(['contact_id' => null, 'conversation_id' => null, 'enquiry_id' => null, 'billing_phone' => null, 'billing_name' => null]);

            if ($contact->consent_state === ConsentState::OptedOut) {
                // The opt-out must outlive the contact: only the number and the consent history stay.
                $contact->forceFill([
                    'name' => null, 'profile_name' => null, 'username' => null, 'email' => null, 'custom_fields' => null, 'tags' => [],
                    'stage_id' => null, 'current_enquiry_id' => null, 'source_detail' => null, 'acquisition_original' => null, 'acquisition_latest' => null,
                ])->save();
                $contact->delete();
                $counts['kept_opt_out'] = true;
            } else {
                $contact->forceDelete();
            }

            return $counts;
        });

        foreach ($files as $file) {
            if ($file->path !== null) {
                Storage::disk((string) $file->disk)->delete((string) $file->path);
            }
            $file->delete();
        }

        $this->audit->record('contact.erased', null, meta: ['contact_id' => $id] + $removed);
    }
}
