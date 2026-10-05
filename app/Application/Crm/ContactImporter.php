<?php

declare(strict_types=1);

namespace App\Application\Crm;

use App\Domain\Audit\AuditLogger;
use App\Domain\Crm\Models\ContactField;
use App\Domain\Messaging\Enums\ConsentState;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Services\ConsentService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use SplFileObject;

/**
 * CSV import. Columns are recognised by their header: phone (required), name, email, tags,
 * opted_in, plus one column per custom field key. Existing contacts are matched by number and
 * updated, never duplicated; an opt-out is never overridden by an import.
 */
final class ContactImporter
{
    public const MAX_ROWS = 10000;

    private const ALIASES = [
        'phone' => ['phone', 'phone number', 'phone_number', 'mobile', 'mobile number', 'number', 'whatsapp', 'whatsapp number', 'wa_id', 'msisdn'],
        'name' => ['name', 'full name', 'full_name', 'contact name', 'customer name'],
        'email' => ['email', 'e-mail', 'email address'],
        'tags' => ['tags', 'tag', 'labels'],
        'opted_in' => ['opted_in', 'opt_in', 'opt-in', 'opted in', 'consent', 'marketing consent'],
    ];

    public function __construct(
        private readonly TagCatalog $tags,
        private readonly ConsentService $consent,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<string>  $extraTags  added to every imported contact
     * @param  bool  $optedIn  the uploader confirms every contact in the file gave marketing consent
     * @return array{created: int, updated: int, skipped: int, total: int, errors: list<array{row: int, message: string}>}
     */
    public function import(UploadedFile $file, array $extraTags = [], bool $optedIn = false): array
    {
        $csv = new SplFileObject($file->getRealPath());
        $csv->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD | SplFileObject::DROP_NEW_LINE);
        $csv->setCsvControl($this->delimiter($file), '"', '');

        $columns = null;
        $rows = [];
        foreach ($csv as $line) {
            if (! is_array($line) || $line === [null]) {
                continue;
            }
            if ($columns === null) {
                $columns = $this->columns($line);

                continue;
            }
            if (count($rows) >= self::MAX_ROWS) {
                throw ValidationException::withMessages(['file' => 'A file can contain up to '.number_format(self::MAX_ROWS).' contacts. Split it into smaller files.']);
            }
            $rows[] = $line;
        }
        if ($columns === null || ! isset($columns['phone'])) {
            throw ValidationException::withMessages(['file' => 'The file needs a header row with a "phone" column (international format, e.g. +971501234567).']);
        }

        // Create every tag first, so the plan's tag limit is checked before anything is written.
        $fileTags = [];
        if (isset($columns['tags'])) {
            foreach ($rows as $line) {
                foreach ($this->splitTags((string) ($line[$columns['tags']] ?? '')) as $tag) {
                    $fileTags[mb_strtolower($tag)] = $tag;
                }
            }
        }
        $canonical = [];
        foreach ($this->tags->ensure(array_merge($extraTags, array_values($fileTags)), 'file') as $tag) {
            $canonical[mb_strtolower($tag)] = $tag;
        }
        $extra = array_values(array_filter(array_map(fn (string $t) => $canonical[mb_strtolower(trim($t))] ?? null, $extraTags)));

        $fields = array_intersect_key($columns, array_flip(ContactField::query()->pluck('key')->all()));
        $result = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'total' => count($rows), 'errors' => []];
        $membershipId = $this->context->membership()?->id;
        $seen = [];

        foreach (array_chunk($rows, 500, true) as $chunk) {
            $numbers = [];
            foreach ($chunk as $i => $line) {
                $numbers[$i] = Contact::normalizePhone(trim((string) ($line[$columns['phone']] ?? '')));
            }
            $existing = Contact::query()->withTrashed()->whereIn('wa_id', array_values(array_filter($numbers)))->get()->keyBy('wa_id');

            foreach ($chunk as $i => $line) {
                $waId = $numbers[$i];
                if ($waId === null || isset($seen[$waId])) {
                    $result['skipped']++;
                    if (count($result['errors']) < 25) {
                        $result['errors'][] = ['row' => $i + 2, 'message' => $waId === null ? 'Not a valid international phone number' : 'Duplicate number in this file'];
                    }

                    continue;
                }
                $seen[$waId] = true;

                $name = isset($columns['name']) ? trim((string) ($line[$columns['name']] ?? '')) : '';
                $email = isset($columns['email']) ? trim((string) ($line[$columns['email']] ?? '')) : '';
                $email = filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '';
                $rowTags = [];
                foreach (isset($columns['tags']) ? $this->splitTags((string) ($line[$columns['tags']] ?? '')) : [] as $tag) {
                    $rowTags[] = $canonical[mb_strtolower($tag)] ?? $tag;
                }
                $attributes = [];
                foreach ($fields as $key => $index) {
                    $value = trim((string) ($line[$index] ?? ''));
                    if ($value !== '') {
                        $attributes[$key] = mb_substr($value, 0, 500);
                    }
                }

                /** @var ?Contact $contact */
                $contact = $existing->get($waId);
                $isNew = $contact === null;
                $contact ??= new Contact(['wa_id' => $waId, 'source' => 'import']);
                if ($contact->trashed()) {
                    $contact->restore();
                }

                // Fill in what the file knows; never blank out data the workspace already has.
                $contact->fill(array_filter([
                    'name' => $name !== '' ? mb_substr($name, 0, 190) : null,
                    'email' => $email !== '' ? $email : null,
                ], fn ($v) => $v !== null));
                $contact->tags = array_values(array_unique(array_merge($contact->tags ?? [], $rowTags, $extra)));
                if ($attributes !== []) {
                    $contact->custom_fields = array_merge($contact->custom_fields ?? [], $attributes);
                }
                $contact->save();

                $rowConsent = isset($columns['opted_in']) && in_array(mb_strtolower(trim((string) ($line[$columns['opted_in']] ?? ''))), ['1', 'yes', 'y', 'true', 'opted_in', 'opted in'], true);
                if (($optedIn || $rowConsent) && $contact->consent_state === ConsentState::Unknown) {
                    $this->consent->optIn($contact, 'import', 'Consent confirmed by the uploader at import', membershipId: $membershipId);
                }

                $isNew ? $result['created']++ : $result['updated']++;
            }
        }

        $this->audit->record('contacts.imported', null, meta: ['created' => $result['created'], 'updated' => $result['updated'], 'skipped' => $result['skipped'], 'file' => $file->getClientOriginalName()]);

        return $result;
    }

    /**
     * @param  array<int, mixed>  $header
     * @return array<string, int> canonical or custom-field column name → index
     */
    private function columns(array $header): array
    {
        $columns = [];
        foreach ($header as $index => $label) {
            $label = mb_strtolower(trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $label)));
            if ($label === '') {
                continue;
            }
            foreach (self::ALIASES as $canonical => $aliases) {
                if (in_array($label, $aliases, true)) {
                    $columns[$canonical] ??= $index;

                    continue 2;
                }
            }
            $columns[(string) preg_replace('/[^a-z0-9]+/', '_', $label)] ??= $index;
        }

        return $columns;
    }

    /** @return list<string> */
    private function splitTags(string $cell): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[;|,]/', $cell) ?: []), fn (string $t) => $t !== ''));
    }

    private function delimiter(UploadedFile $file): string
    {
        $first = (string) strtok((string) file_get_contents($file->getRealPath(), false, null, 0, 4096), "\n");

        return substr_count($first, ';') > substr_count($first, ',') ? ';' : (substr_count($first, "\t") > substr_count($first, ',') ? "\t" : ',');
    }
}
