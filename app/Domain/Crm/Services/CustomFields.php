<?php

declare(strict_types=1);

namespace App\Domain\Crm\Services;

use App\Domain\Crm\Models\ContactField;
use App\Domain\Plans\Entitlements\EntitlementService;
use App\Domain\Plans\FeatureKey;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Custom contact fields: which types and rules a workspace's plan allows, and checking the values
 * people put into them.
 *
 *  • Every plan: text, number and date fields, up to the plan's number of fields.
 *  • Enterprise ("advanced"): also email, URL, phone, yes/no, single and multiple choice, plus
 *    validation rules (required, minimum / maximum, length, pattern).
 *
 * Values are checked for the fields that are defined. Other keys sent through the public API
 * (`attributes`) are stored as they are, as before.
 */
final class CustomFields
{
    public const BASIC_TYPES = ['text', 'number', 'date'];

    public const ADVANCED_TYPES = ['email', 'url', 'phone', 'boolean', 'select', 'multi_select'];

    public const RULES = ['required', 'min', 'max', 'min_length', 'max_length', 'pattern'];

    /** Names that are real contact columns, so they cannot be custom fields. */
    public const RESERVED = ['phone', 'name', 'email', 'tags', 'opted_in', 'consent', 'id'];

    private const MAX_VALUE_LENGTH = 1000;

    public function __construct(private readonly EntitlementService $entitlements, private readonly TenantContext $context) {}

    /** @return array{limit: ?int, fair_use: ?int, advanced: bool, types: list<string>} */
    public function allowance(Tenant $tenant): array
    {
        $entitlement = $this->entitlements->for($tenant)->get(FeatureKey::CustomFields);
        $advanced = (bool) ($entitlement->config['advanced'] ?? false);

        return [
            'limit' => $entitlement->enabled ? $entitlement->limit : 0,
            'fair_use' => $entitlement->limit === null && isset($entitlement->config['fair_use']) ? (int) $entitlement->config['fair_use'] : null,
            'advanced' => $advanced,
            'types' => $advanced ? [...self::BASIC_TYPES, ...self::ADVANCED_TYPES] : self::BASIC_TYPES,
        ];
    }

    /**
     * Checks a field definition (new or edited) against the plan. Returns the cleaned options and rules.
     *
     * @param  array<string, mixed>  $input  type, options, rules
     * @return array{options: ?list<string>, rules: ?array<string, mixed>}
     */
    public function definition(Tenant $tenant, string $type, array $input): array
    {
        $allowance = $this->allowance($tenant);
        if (! in_array($type, $allowance['types'], true)) {
            throw ValidationException::withMessages(['type' => in_array($type, self::ADVANCED_TYPES, true)
                ? 'This field type is available on the Enterprise plan.' : 'Choose a field type.']);
        }

        $options = null;
        if (in_array($type, ['select', 'multi_select'], true)) {
            $options = array_values(array_unique(array_filter(array_map(fn ($o) => mb_substr(trim((string) $o), 0, 80), (array) ($input['options'] ?? [])), fn (string $o) => $o !== '')));
            if (count($options) < 2 || count($options) > 50) {
                throw ValidationException::withMessages(['options' => 'Give between 2 and 50 choices.']);
            }
        }

        $rules = array_filter(array_intersect_key((array) ($input['rules'] ?? []), array_flip(self::RULES)), fn ($v) => $v !== null && $v !== '' && $v !== false);
        if ($rules !== [] && ! $allowance['advanced']) {
            throw ValidationException::withMessages(['rules' => 'Validation rules are available on the Enterprise plan.']);
        }
        foreach (['min', 'max', 'min_length', 'max_length'] as $numeric) {
            if (isset($rules[$numeric]) && ! is_numeric($rules[$numeric])) {
                throw ValidationException::withMessages(["rules.{$numeric}" => 'Enter a number.']);
            }
        }
        if (isset($rules['min'], $rules['max']) && (float) $rules['min'] > (float) $rules['max']) {
            throw ValidationException::withMessages(['rules.max' => 'The maximum must not be below the minimum.']);
        }
        if (isset($rules['pattern']) && (mb_strlen((string) $rules['pattern']) > 200 || @preg_match($this->regex((string) $rules['pattern']), '') === false)) {
            throw ValidationException::withMessages(['rules.pattern' => 'This pattern is not a valid regular expression.']);
        }
        // Keep only the rules that mean something for the type.
        $applies = match ($type) {
            'number' => ['required', 'min', 'max'],
            'date' => ['required'],
            'text' => ['required', 'min_length', 'max_length', 'pattern'],
            default => ['required'],
        };
        $rules = array_intersect_key($rules, array_flip($applies));
        if (isset($rules['required'])) {
            $rules['required'] = true;
        }

        return ['options' => $options, 'rules' => $rules === [] ? null : $rules];
    }

    /**
     * Checks and normalises the values for defined fields. Keys that are not defined fields pass
     * through unchanged. Throws one validation error per field, keyed "attributes.<key>".
     *
     * @param  array<string, mixed>  $values
     * @param  bool  $checkRequired  false when only some values are being changed elsewhere (imports)
     * @return array<string, mixed>
     */
    public function values(array $values, bool $checkRequired = true): array
    {
        $errors = [];
        $out = $values;
        foreach ($this->fields() as $field) {
            $present = array_key_exists($field->key, $values) && ! $this->blank($values[$field->key]);
            if (! $present) {
                unset($out[$field->key]);
                if ($checkRequired && ($field->rules['required'] ?? false)) {
                    $errors["attributes.{$field->key}"] = "{$field->label} is required.";
                }

                continue;
            }
            $result = $this->value($field, $values[$field->key]);
            if (is_string($result) && str_starts_with($result, "\0")) {
                $errors["attributes.{$field->key}"] = substr($result, 1);
            } else {
                $out[$field->key] = $result;
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }

    /**
     * For imports: values that do not fit their field are left out instead of failing the row.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function lenient(array $values): array
    {
        $out = [];
        foreach ($this->fields()->keyBy('key') as $key => $field) {
            if (array_key_exists($key, $values) && ! $this->blank($values[$key])) {
                $result = $this->value($field, $values[$key]);
                if (! (is_string($result) && str_starts_with($result, "\0"))) {
                    $out[$key] = $result;
                }
            }
        }

        return $out + array_diff_key($values, $out, $this->fields()->keyBy('key')->all());
    }

    /** @var array<string, Collection<int, ContactField>> per workspace, for the life of this object (an import reads them once) */
    private array $loaded = [];

    /** @return Collection<int, ContactField> */
    private function fields(): Collection
    {
        return $this->loaded[$this->context->id()] ??= ContactField::query()->get();
    }

    /** The normalised value, or "\0" + the reason it is not valid. */
    private function value(ContactField $field, mixed $raw): mixed
    {
        $rules = (array) ($field->rules ?? []);
        $fail = fn (string $why) => "\0{$field->label} {$why}";
        $text = is_scalar($raw) ? trim((string) $raw) : '';

        switch ($field->type) {
            case 'number':
                if (! is_numeric($text)) {
                    return $fail('must be a number.');
                }
                $number = $text + 0;
                if (isset($rules['min']) && $number < (float) $rules['min']) {
                    return $fail("must be at least {$rules['min']}.");
                }
                if (isset($rules['max']) && $number > (float) $rules['max']) {
                    return $fail("must be at most {$rules['max']}.");
                }

                return $number;
            case 'date':
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $text);

                return $date !== false && $date->format('Y-m-d') === $text ? $text : $fail('must be a date (YYYY-MM-DD).');
            case 'email':
                return filter_var($text, FILTER_VALIDATE_EMAIL) !== false ? mb_strtolower($text) : $fail('must be an email address.');
            case 'url':
                return filter_var($text, FILTER_VALIDATE_URL) !== false && preg_match('#^https?://#i', $text) === 1 ? $text : $fail('must be a web address starting with http:// or https://.');
            case 'phone':
                $digits = preg_replace('/\D+/', '', $text) ?? '';

                return strlen($digits) >= 7 && strlen($digits) <= 15 && preg_match('/^[+\d\s().\-]+$/', $text) === 1 ? $text : $fail('must be a phone number.');
            case 'boolean':
                $bool = is_bool($raw) ? $raw : filter_var($text, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

                return $bool ?? $fail('must be yes or no.');
            case 'select':
                return in_array($text, (array) $field->options, true) ? $text : $fail('must be one of: '.implode(', ', (array) $field->options).'.');
            case 'multi_select':
                $picked = array_values(array_unique(array_map(fn ($v) => trim((string) $v), is_array($raw) ? $raw : explode(',', $text))));
                $picked = array_values(array_filter($picked, fn (string $v) => $v !== ''));

                return array_diff($picked, (array) $field->options) === [] ? $picked : $fail('must be chosen from: '.implode(', ', (array) $field->options).'.');
            default: // text
                if (! is_scalar($raw)) {
                    return $fail('must be text.');
                }
                $length = mb_strlen($text);
                if ($length > self::MAX_VALUE_LENGTH) {
                    return $fail('is too long (at most '.self::MAX_VALUE_LENGTH.' characters).');
                }
                if (isset($rules['min_length']) && $length < (int) $rules['min_length']) {
                    return $fail("must be at least {$rules['min_length']} characters.");
                }
                if (isset($rules['max_length']) && $length > (int) $rules['max_length']) {
                    return $fail("must be at most {$rules['max_length']} characters.");
                }
                if (isset($rules['pattern']) && preg_match($this->regex((string) $rules['pattern']), $text) !== 1) {
                    return $fail('is not in the expected format.');
                }

                return $text;
        }
    }

    private function blank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '') || $value === [];
    }

    /** A user's pattern, anchored to the whole value and safe to embed. */
    private function regex(string $pattern): string
    {
        return '/^(?:'.str_replace('/', '\/', $pattern).')$/u';
    }
}
