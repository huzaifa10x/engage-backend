<?php

declare(strict_types=1);

namespace App\Domain\Data;

use App\Domain\Data\Models\DataExport;
use App\Domain\Tenancy\TenantContext;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Settings › Data (batch 6, item 14): the workspace's data as CSV files. Each kind is one table of
 * rows, written in chunks so a large workspace never loads everything at once. Dates are ISO 8601
 * in the workspace's time zone, with its offset (2026-11-18T16:00:00+04:00). The file starts with
 * a byte-order mark so Excel reads it as UTF-8, and cells that a spreadsheet would run as a
 * formula start with an apostrophe. Runs inside the workspace's tenant context.
 */
final class DataExports
{
    /** What can be exported, as the Owner sees it. */
    public const KINDS = [
        'contacts' => 'Contacts',
        'enquiries' => 'Enquiries and stages',
        'timeline' => 'Timeline (everything that happened to each contact)',
        'messages' => 'Conversations and messages',
        'payments' => 'Payments (store orders)',
        'audit_log' => 'Audit log',
    ];

    public const DISK = 'local';

    public function __construct(private readonly TenantContext $context) {}

    /** Writes the file and records where it is. */
    public function build(DataExport $export): void
    {
        [$header, $query, $row] = $this->definition($export->kind);
        $path = "exports/{$export->tenant_id}/{$export->id}.csv";
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new InvalidArgumentException('Could not open a temporary file.');
        }
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, $header, escape: '');
        $rows = 0;
        $query()->chunk(1000, function ($chunk) use ($stream, $row, &$rows): void {
            foreach ($chunk as $record) {
                fputcsv($stream, array_map(fn ($v) => $this->cell($v), $row($record)), escape: '');
                $rows++;
            }
        });
        rewind($stream);
        Storage::disk(self::DISK)->put($path, $stream);
        fclose($stream);

        $export->forceFill(['status' => 'ready', 'rows' => $rows, 'disk' => self::DISK, 'path' => $path, 'finished_at' => now(), 'expires_at' => now()->addDays(DataExport::DAYS_KEPT)])->save();
    }

    /**
     * The columns, the rows (oldest first) and how one row becomes cells.
     *
     * @return array{0: list<string>, 1: Closure(): Builder, 2: Closure(object): list<mixed>}
     */
    private function definition(string $kind): array
    {
        $at = fn (mixed $v) => $this->date($v);
        $phone = fn (?string $waId) => $waId !== null ? '+'.$waId : null;

        return match ($kind) {
            'contacts' => [
                ['id', 'phone', 'name', 'whatsapp_name', 'email', 'tags', 'consent', 'marketing_opted_out', 'stage', 'source', 'attributes', 'created_at', 'last_message_at'],
                fn () => DB::table('contacts')->leftJoin('crm_stages', 'crm_stages.id', '=', 'contacts.stage_id')->whereNull('contacts.deleted_at')
                    ->select(['contacts.*', 'crm_stages.name as stage_name'])->orderBy('contacts.created_at')->orderBy('contacts.id'),
                fn (object $c) => [$c->id, $phone($c->wa_id), $c->name, $c->profile_name, $c->email, trim((string) $c->tags, '{}'), $c->consent_state,
                    $c->marketing_opted_out ? 'yes' : 'no', $c->stage_name, $c->source, $c->custom_fields, $at($c->created_at), $at($c->last_inbound_at)],
            ],
            'enquiries' => [
                ['id', 'number', 'contact_id', 'contact_phone', 'contact_name', 'started_at', 'started_by', 'start_reason', 'source', 'source_confidence', 'stage', 'stage_group', 'stage_version', 'lost_reason', 'stage_changed_at', 'last_activity_at'],
                fn () => DB::table('enquiries')->join('contacts', 'contacts.id', '=', 'enquiries.contact_id')->leftJoin('crm_stages', 'crm_stages.id', '=', 'enquiries.stage_id')
                    ->select(['enquiries.*', 'contacts.wa_id', DB::raw('coalesce(contacts.name, contacts.profile_name) as contact_name'), 'crm_stages.name as stage_name', 'crm_stages.group as stage_group'])
                    ->orderBy('enquiries.started_at')->orderBy('enquiries.id'),
                fn (object $e) => [$e->id, $e->number, $e->contact_id, $phone($e->wa_id), $e->contact_name, $at($e->started_at), $e->started_by, $e->start_reason, $e->source,
                    $e->source_confidence, $e->stage_name, $e->stage_group, $e->stage_version, $e->lost_reason, $at($e->stage_changed_at), $at($e->last_activity_at)],
            ],
            'timeline' => [
                ['id', 'occurred_at', 'contact_id', 'enquiry_id', 'type', 'actor', 'actor_name', 'details'],
                fn () => DB::table('timeline_events')->orderBy('occurred_at')->orderBy('id'),
                fn (object $t) => [$t->id, $at($t->occurred_at), $t->contact_id, $t->enquiry_id, $t->type, $t->actor, $t->actor_name, $t->data],
            ],
            'messages' => [
                ['id', 'occurred_at', 'conversation_id', 'contact_id', 'contact_phone', 'direction', 'type', 'status', 'text', 'template', 'origin', 'text_removed_at'],
                fn () => DB::table('messages')->leftJoin('contacts', 'contacts.id', '=', 'messages.contact_id')->select(['messages.*', 'contacts.wa_id'])
                    ->orderBy('messages.created_at')->orderBy('messages.id'),
                fn (object $m) => [$m->id, $at($m->occurred_at ?? $m->created_at), $m->conversation_id, $m->contact_id, $phone($m->wa_id), $m->direction, $m->type, $m->status,
                    $m->body, $m->template !== null ? (json_decode((string) $m->template, true)['name'] ?? null) : null, $m->origin, $at($m->redacted_at)],
            ],
            'payments' => [
                ['id', 'store', 'order', 'contact_id', 'enquiry_id', 'status', 'paid', 'currency', 'total', 'refunded', 'net', 'placed_at', 'paid_at', 'refunded_at'],
                fn () => DB::table('commerce_orders')->orderBy('placed_at')->orderBy('id'),
                fn (object $o) => [$o->id, $o->store_name, $o->number, $o->contact_id, $o->enquiry_id, $o->status, $o->paid ? 'yes' : 'no', $o->currency, $o->total, $o->refunded, $o->net,
                    $at($o->placed_at), $at($o->paid_at), $at($o->refunded_at)],
            ],
            'audit_log' => [
                ['id', 'at', 'actor_type', 'actor_id', 'action', 'entity_type', 'entity_id', 'before', 'after', 'details', 'ip'],
                fn () => DB::table('audit_log')->where('tenant_id', $this->context->id())->orderBy('created_at')->orderBy('id'),
                fn (object $a) => [$a->id, $at($a->created_at), $a->actor_type, $a->actor_id, $a->action, $a->entity_type, $a->entity_id, $a->before, $a->after, $a->meta, $a->ip],
            ],
            default => throw new InvalidArgumentException("Unknown export \"{$kind}\"."),
        };
    }

    private function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $at = $value instanceof DateTimeInterface ? Carbon::instance($value) : Carbon::parse((string) $value);

        return $at->setTimezone($this->context->tenant()->timezone)->toIso8601String();
    }

    /** Text a spreadsheet would run as a formula starts with an apostrophe (a phone number stays as it is). */
    private function cell(mixed $value): string
    {
        $text = is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $text) === 1 && preg_match('/^\+\d+$/', $text) !== 1 ? "'".$text : $text;
    }
}
