<?php

declare(strict_types=1);

namespace App\Domain\Crm\Services;

use App\Domain\Crm\Models\ContactField;
use App\Domain\Messaging\Models\Contact;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Compiles a segment rule tree into SQL at query time ("query-on-read"). Every value is bound,
 * and field / operator names come from the whitelist below — never from the request.
 */
final class SegmentQuery
{
    public const TAG_PATTERN = '/^[\p{L}\p{N} _\-.&+]{1,40}$/u';

    private const TEXT = ['contains', 'is_empty', 'is_not_empty'];

    private const DATE = ['within_days', 'older_than_days', 'is_empty'];

    private const FIELDS = [
        'tags' => ['has', 'not_has'],
        'consent_state' => ['is', 'is_not'],
        'source' => ['is', 'is_not'],
        'name' => self::TEXT,
        'email' => self::TEXT,
        'last_inbound_at' => self::DATE,
        'created_at' => self::DATE,
    ];

    /** Custom fields: text / number / yes-no / select values, dates (YYYY-MM-DD) and multi-select lists. */
    private const ATTRIBUTE_OPS = ['equals', 'not_equals', 'contains', 'gt', 'lt', 'before', 'after', 'includes', 'is_empty', 'is_not_empty'];

    private const NO_VALUE = ['is_empty', 'is_not_empty'];

    /**
     * Whitelists and normalises rules coming from the client.
     *
     * @param  array<int, mixed>  $rules
     * @return list<array{field: string, op: string, value?: string|int|float}>
     */
    public function normalize(array $rules): array
    {
        $out = [];
        foreach (array_values($rules) as $i => $rule) {
            $field = is_array($rule) ? (string) ($rule['field'] ?? '') : '';
            $op = is_array($rule) ? (string) ($rule['op'] ?? '') : '';
            $ops = self::FIELDS[$field] ?? (preg_match('/^attr:[a-z0-9_]{1,40}$/', $field) === 1 ? self::ATTRIBUTE_OPS : null);

            if ($ops === null || ! in_array($op, $ops, true)) {
                throw ValidationException::withMessages(["rules.{$i}" => 'This condition is not supported.']);
            }
            if (in_array($op, self::NO_VALUE, true)) {
                $out[] = ['field' => $field, 'op' => $op];

                continue;
            }

            $value = $rule['value'] ?? null;
            if (! is_scalar($value) || trim((string) $value) === '') {
                throw ValidationException::withMessages(["rules.{$i}.value" => 'Enter a value for this condition.']);
            }
            $value = trim((string) $value);

            if (in_array($op, ['before', 'after'], true)) {
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
                    throw ValidationException::withMessages(["rules.{$i}.value" => 'Enter a date (YYYY-MM-DD).']);
                }
            } elseif (in_array($op, ['within_days', 'older_than_days', 'gt', 'lt'], true)) {
                if (! is_numeric($value)) {
                    throw ValidationException::withMessages(["rules.{$i}.value" => 'Enter a number.']);
                }
                $value = in_array($op, ['gt', 'lt'], true) ? (float) $value : max(0, (int) $value);
            } elseif ($field === 'consent_state' && ! in_array($value, ['unknown', 'opted_in', 'opted_out'], true)) {
                throw ValidationException::withMessages(["rules.{$i}.value" => 'Choose a consent status.']);
            } else {
                $value = mb_substr($value, 0, 190);
            }

            $out[] = ['field' => $field, 'op' => $op, 'value' => $value];
        }

        return $out;
    }

    /**
     * An ad-hoc filter from a request (contacts list, campaign audience): {match, rules} as an array
     * or a JSON string. Custom fields must exist in this workspace. Null = no filter.
     *
     * @return ?array{match: 'all'|'any', rules: list<array{field: string, op: string, value?: string|int|float}>}
     */
    public function filter(mixed $raw, string $attribute = 'filter'): ?array
    {
        if (is_string($raw) && $raw !== '') {
            $raw = json_decode($raw, true);
            if (! is_array($raw)) {
                throw ValidationException::withMessages([$attribute => 'The filter is not valid.']);
            }
        }
        if (! is_array($raw) || ($raw['rules'] ?? []) === []) {
            return null;
        }
        if (! is_array($raw['rules']) || count($raw['rules']) > 20) {
            throw ValidationException::withMessages([$attribute => 'Use at most 20 conditions.']);
        }
        $rules = $this->normalize($raw['rules']);
        $keys = array_values(array_unique(array_map(fn (array $r) => substr($r['field'], 5), array_filter($rules, fn (array $r) => str_starts_with($r['field'], 'attr:')))));
        if ($keys !== []) {
            $known = ContactField::query()->whereIn('key', $keys)->pluck('key')->all(); // this workspace's fields only
            foreach (array_diff($keys, $known) as $missing) {
                throw ValidationException::withMessages([$attribute => "The custom field \"{$missing}\" does not exist."]);
            }
        }

        return ['match' => ($raw['match'] ?? 'all') === 'any' ? 'any' : 'all', 'rules' => $rules];
    }

    /**
     * @param  Builder<Contact>  $query
     * @param  array<int, array<string, mixed>>  $rules
     * @return Builder<Contact>
     */
    public function apply(Builder $query, string $match, array $rules): Builder
    {
        if ($rules === []) {
            return $query; // no conditions = every contact
        }
        $boolean = $match === 'any' ? 'or' : 'and';

        return $query->where(function (Builder $group) use ($rules, $boolean): void {
            foreach ($rules as $rule) {
                $group->where(function (Builder $q) use ($rule): void {
                    $this->condition($q, (string) $rule['field'], (string) $rule['op'], $rule['value'] ?? null);
                }, null, null, $boolean);
            }
        });
    }

    /** @param Builder<Contact> $q */
    private function condition(Builder $q, string $field, string $op, mixed $value): void
    {
        if (str_starts_with($field, 'attr:')) {
            $this->attribute($q, substr($field, 5), $op, $value);

            return;
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], (string) $value).'%';
        $isDate = in_array($field, ['last_inbound_at', 'created_at'], true);

        match ($op) {
            'has' => $q->whereRaw('tags @> ARRAY[?]::text[]', [(string) $value]),
            'not_has' => $q->whereRaw('NOT (tags @> ARRAY[?]::text[])', [(string) $value]),
            'is' => $q->where($field, (string) $value),
            'is_not' => $q->where($field, '!=', (string) $value),
            'contains' => $q->where($field, 'ilike', $like),
            'is_empty' => $isDate ? $q->whereNull($field) : $q->whereRaw("coalesce({$field}, '') = ''"),
            'is_not_empty' => $q->whereRaw("coalesce({$field}, '') <> ''"),
            'within_days' => $q->where($field, '>=', now()->subDays((int) $value)),
            'older_than_days' => $q->where($field, '<', now()->subDays((int) $value)),
            default => $q->whereRaw('false'),
        };
    }

    /** @param Builder<Contact> $q */
    private function attribute(Builder $q, string $key, string $op, mixed $value): void
    {
        $text = 'custom_fields->>?';
        $numeric = "(custom_fields->>?) ~ '^-?[0-9]+(\\.[0-9]+)?$' AND (custom_fields->>?)::numeric";
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], (string) $value).'%';

        match ($op) {
            'equals' => $q->whereRaw("lower({$text}) = lower(?)", [$key, (string) $value]),
            'not_equals' => $q->whereRaw("({$text} IS NULL OR lower({$text}) <> lower(?))", [$key, $key, (string) $value]),
            'contains' => $q->whereRaw("{$text} ILIKE ?", [$key, $like]),
            'gt' => $q->whereRaw("{$numeric} > ?", [$key, $key, (float) $value]),
            // Dates are stored as YYYY-MM-DD (optionally with a time): compared on the date part.
            'before' => $q->whereRaw("({$text}) ~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}' AND left({$text}, 10) < ?", [$key, $key, (string) $value]),
            'after' => $q->whereRaw("({$text}) ~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}' AND left({$text}, 10) > ?", [$key, $key, (string) $value]),
            // A multi-select list holds the option (or a single value equals it).
            'includes' => $q->whereRaw("((jsonb_typeof(custom_fields->?) = 'array' AND jsonb_exists(custom_fields->?, ?)) OR lower({$text}) = lower(?))", [$key, $key, (string) $value, $key, (string) $value]),
            'lt' => $q->whereRaw("{$numeric} < ?", [$key, $key, (float) $value]),
            'is_empty' => $q->whereRaw("coalesce({$text}, '') = ''", [$key]),
            'is_not_empty' => $q->whereRaw("coalesce({$text}, '') <> ''", [$key]),
            default => $q->whereRaw('false'),
        };
    }
}
