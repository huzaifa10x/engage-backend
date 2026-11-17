<?php

declare(strict_types=1);

namespace App\Domain\Forms;

use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * A simple WhatsApp form: one screen whose questions and options are fixed in the form (no live data
 * endpoint), written as Meta Flow JSON. Limits are Meta's (Flow JSON 7.0 components).
 *
 * Definition: {title, intro, submit_label, fields: [{name, type, label, required, options: [{id, title}], save_to, consent}]}
 *  type: text | textarea | email | phone | number | dropdown | radio | date | optin
 *  save_to: name | email | field:<custom field key> | null — where the answer goes on the contact
 *  consent: an optin question that records marketing consent when ticked
 */
final class FormDefinition
{
    public const FLOW_JSON_VERSION = '7.0';

    public const SCREEN = 'FORM';

    public const TYPES = ['text', 'textarea', 'email', 'phone', 'number', 'dropdown', 'radio', 'date', 'optin'];

    /** Meta's label limits per component. */
    private const LABEL_MAX = ['text' => 20, 'email' => 20, 'phone' => 20, 'number' => 20, 'textarea' => 20, 'dropdown' => 20, 'radio' => 30, 'date' => 40, 'optin' => 120];

    private const MAX_FIELDS = 20;

    /** Dates on the contact and in submissions: day first, in the workspace's time zone (Dubai by default). */
    public const DATE_FORMAT = 'd/m/Y';

    /**
     * The two starting points of the approved design (5a).
     *
     * @return array<string, mixed>
     */
    public static function template(string $kind): array
    {
        return match ($kind) {
            'booking' => [
                'title' => 'Book an appointment',
                'intro' => 'Choose a service, a day and a time. We will confirm on WhatsApp.',
                'submit_label' => 'Book',
                'fields' => [
                    ['name' => 'name', 'type' => 'text', 'label' => 'Your name', 'required' => true, 'options' => [], 'save_to' => 'name', 'consent' => false],
                    ['name' => 'service', 'type' => 'dropdown', 'label' => 'Service', 'required' => true, 'options' => [
                        ['id' => 'consultation', 'title' => 'Consultation'], ['id' => 'follow_up', 'title' => 'Follow-up visit'],
                    ], 'save_to' => null, 'consent' => false],
                    ['name' => 'date', 'type' => 'date', 'label' => 'Date', 'required' => true, 'options' => [], 'save_to' => null, 'consent' => false],
                    ['name' => 'time_slot', 'type' => 'radio', 'label' => 'Time', 'required' => true, 'options' => [
                        ['id' => '10_00', 'title' => '10:00'], ['id' => '12_00', 'title' => '12:00'], ['id' => '16_00', 'title' => '16:00'], ['id' => '18_00', 'title' => '18:00'],
                    ], 'save_to' => null, 'consent' => false],
                    ['name' => 'marketing_consent', 'type' => 'optin', 'label' => 'Send me offers and news on WhatsApp', 'required' => false, 'options' => [], 'save_to' => null, 'consent' => true],
                ],
            ],
            'lead' => [
                'title' => 'Tell us what you need',
                'intro' => 'A few details and we will get back to you.',
                'submit_label' => 'Send',
                'fields' => [
                    ['name' => 'name', 'type' => 'text', 'label' => 'Your name', 'required' => true, 'options' => [], 'save_to' => 'name', 'consent' => false],
                    ['name' => 'budget', 'type' => 'dropdown', 'label' => 'Budget', 'required' => true, 'options' => [
                        ['id' => 'under_5k', 'title' => 'Under AED 5,000'], ['id' => '5k_20k', 'title' => 'AED 5,000 to 20,000'], ['id' => 'over_20k', 'title' => 'Over AED 20,000'],
                    ], 'save_to' => null, 'consent' => false],
                    ['name' => 'location', 'type' => 'text', 'label' => 'Location', 'required' => true, 'options' => [], 'save_to' => null, 'consent' => false],
                    ['name' => 'requirement', 'type' => 'textarea', 'label' => 'Requirement', 'required' => true, 'options' => [], 'save_to' => null, 'consent' => false],
                    ['name' => 'marketing_consent', 'type' => 'optin', 'label' => 'Send me offers and news on WhatsApp', 'required' => false, 'options' => [], 'save_to' => null, 'consent' => true],
                ],
            ],
            default => ['title' => 'Your details', 'intro' => '', 'submit_label' => 'Send', 'fields' => [
                ['name' => 'name', 'type' => 'text', 'label' => 'Your name', 'required' => true, 'options' => [], 'save_to' => 'name', 'consent' => false],
            ]],
        };
    }

    /**
     * Cleans and checks a definition; the messages say which question and what to change.
     *
     * @return array<string, mixed>
     */
    public static function normalize(mixed $input): array
    {
        $in = is_array($input) ? $input : [];
        $fail = fn (string $key, string $message) => throw ValidationException::withMessages(["definition.{$key}" => $message]);
        $title = trim((string) ($in['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 30) {
            $fail('title', 'Give the form a title of up to 30 characters (shown at the top of the form).');
        }
        $submit = trim((string) ($in['submit_label'] ?? 'Send')) ?: 'Send';
        if (mb_strlen($submit) > 30) {
            $fail('submit_label', 'The button can have up to 30 characters.');
        }
        $intro = trim((string) ($in['intro'] ?? ''));
        if (mb_strlen($intro) > 1000) {
            $fail('intro', 'The introduction can have up to 1,000 characters.');
        }

        $fields = [];
        $names = [];
        $consents = 0;
        foreach (array_values((array) ($in['fields'] ?? [])) as $i => $raw) {
            $f = (array) $raw;
            $type = (string) ($f['type'] ?? '');
            $label = trim((string) ($f['label'] ?? ''));
            $where = 'Question '.($i + 1);
            if (! in_array($type, self::TYPES, true)) {
                $fail("fields.{$i}.type", "{$where}: choose what kind of answer it takes.");
            }
            $max = self::LABEL_MAX[$type];
            if ($label === '' || mb_strlen($label) > $max) {
                $fail("fields.{$i}.label", "{$where}: the question can have up to {$max} characters for this kind of answer (WhatsApp’s limit).");
            }
            $name = (string) ($f['name'] ?? '');
            $name = $name !== '' ? $name : trim((string) preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($label)), '_');
            $name = substr((string) preg_replace('/^[^a-z]+/', '', $name), 0, 30) ?: 'q'.($i + 1);
            if (isset($names[$name])) {
                $name = substr($name, 0, 26).'_'.($i + 1);
            }
            $names[$name] = true;

            $options = [];
            if (in_array($type, ['dropdown', 'radio'], true)) {
                $seen = [];
                foreach (array_values((array) ($f['options'] ?? [])) as $k => $o) {
                    $o = (array) $o;
                    $ot = trim((string) ($o['title'] ?? ''));
                    if ($ot === '') {
                        continue;
                    }
                    if (mb_strlen($ot) > 30) {
                        $fail("fields.{$i}.options", "{$where}: an option can have up to 30 characters (“{$ot}”).");
                    }
                    $id = trim((string) preg_replace('/[^a-z0-9]+/', '_', mb_strtolower((string) ($o['id'] ?? '') ?: $ot)), '_') ?: 'o'.($k + 1);
                    $id = isset($seen[$id]) ? $id.'_'.($k + 1) : $id;
                    $seen[$id] = true;
                    $options[] = ['id' => substr($id, 0, 40), 'title' => $ot];
                }
                $limit = $type === 'radio' ? 20 : 200;
                if (count($options) < 1 || count($options) > $limit) {
                    $fail("fields.{$i}.options", "{$where}: give between 1 and {$limit} options.");
                }
            }

            $saveTo = $f['save_to'] ?? null;
            $saveTo = is_string($saveTo) && (in_array($saveTo, ['name', 'email'], true) || preg_match('/^field:[a-z0-9][a-z0-9_]{0,39}$/', $saveTo) === 1) ? $saveTo : null;
            $consent = $type === 'optin' && (bool) ($f['consent'] ?? false);
            $consents += $consent ? 1 : 0;
            $fields[] = ['name' => $name, 'type' => $type, 'label' => $label, 'required' => $type !== 'optin' && (bool) ($f['required'] ?? false),
                'options' => $options, 'save_to' => $type === 'optin' ? null : $saveTo, 'consent' => $consent];
        }
        if ($fields === [] || count($fields) > self::MAX_FIELDS) {
            $fail('fields', 'A form has between 1 and 20 questions.');
        }
        if ($consents > 1) {
            $fail('fields', 'Only one question can be the marketing consent question.');
        }

        return ['title' => $title, 'intro' => $intro, 'submit_label' => $submit, 'fields' => $fields];
    }

    /**
     * Meta Flow JSON: one terminal screen; the button completes the form with every answer.
     *
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>
     */
    public static function flowJson(array $d): array
    {
        $children = [];
        if (($d['intro'] ?? '') !== '') {
            $children[] = ['type' => 'TextBody', 'text' => (string) $d['intro']];
        }
        $payload = [];
        foreach ($d['fields'] as $f) {
            $base = ['name' => $f['name'], 'label' => $f['label']];
            $children[] = match ($f['type']) {
                'text', 'email', 'phone', 'number' => ['type' => 'TextInput'] + $base + ['input-type' => $f['type'] === 'text' ? 'text' : $f['type'], 'required' => $f['required']],
                'textarea' => ['type' => 'TextArea'] + $base + ['required' => $f['required']],
                'dropdown' => ['type' => 'Dropdown'] + $base + ['required' => $f['required'], 'data-source' => $f['options']],
                'radio' => ['type' => 'RadioButtonsGroup'] + $base + ['required' => $f['required'], 'data-source' => $f['options']],
                'date' => ['type' => 'DatePicker'] + $base + ['required' => $f['required']],
                default => ['type' => 'OptIn'] + $base + ['required' => false],
            };
            $payload[$f['name']] = '${form.'.$f['name'].'}';
        }
        $children[] = ['type' => 'Footer', 'label' => $d['submit_label'], 'on-click-action' => ['name' => 'complete', 'payload' => $payload]];

        return [
            'version' => self::FLOW_JSON_VERSION,
            'screens' => [[
                'id' => self::SCREEN,
                'title' => $d['title'],
                'terminal' => true,
                'success' => true,
                'data' => (object) [],
                'layout' => ['type' => 'SingleColumnLayout', 'children' => $children],
            ]],
        ];
    }

    /**
     * The answers as people read them, per question: option titles instead of ids, dates day-first,
     * the consent box as Yes / No. `iso` keeps dates as YYYY-MM-DD for date custom fields.
     *
     * @param  array<string, mixed>  $d
     * @param  array<string, mixed>  $answers
     * @return list<array{name: string, label: string, type: string, value: ?string, iso: ?string, save_to: ?string, consent: bool, raw: mixed}>
     */
    public static function read(array $d, array $answers): array
    {
        $out = [];
        foreach ($d['fields'] as $f) {
            $raw = $answers[$f['name']] ?? null;
            $iso = null;
            $value = match ($f['type']) {
                'dropdown', 'radio' => self::optionTitle((array) $f['options'], $raw),
                'optin' => filter_var($raw, FILTER_VALIDATE_BOOLEAN) ? 'Yes' : 'No',
                'date' => self::date($raw, $iso),
                default => is_scalar($raw) && trim((string) $raw) !== '' ? mb_substr(trim((string) $raw), 0, 1000) : null,
            };
            $out[] = ['name' => $f['name'], 'label' => $f['label'], 'type' => $f['type'], 'value' => $value, 'iso' => $iso,
                'save_to' => $f['save_to'], 'consent' => (bool) $f['consent'], 'raw' => $raw];
        }

        return $out;
    }

    /** @param array<int, mixed> $options */
    private static function optionTitle(array $options, mixed $raw): ?string
    {
        foreach ($options as $o) {
            if (is_array($o) && (string) ($o['id'] ?? '') === (string) $raw) {
                return (string) $o['title'];
            }
        }

        return is_scalar($raw) && (string) $raw !== '' ? (string) $raw : null;
    }

    /** "2026-11-20" (Flow JSON 5.0+) or a millisecond timestamp (older) → "20/11/2026". */
    private static function date(mixed $raw, ?string &$iso): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $tz = 'Asia/Dubai';
        try {
            $date = is_numeric($raw) ? Carbon::createFromTimestampMs((int) $raw)->setTimezone($tz) : Carbon::createFromFormat('!Y-m-d', (string) $raw, $tz);
        } catch (\Throwable) {
            return (string) $raw;
        }
        if (! $date instanceof Carbon) {
            return (string) $raw;
        }
        $iso = $date->format('Y-m-d');

        return $date->format(self::DATE_FORMAT);
    }
}
