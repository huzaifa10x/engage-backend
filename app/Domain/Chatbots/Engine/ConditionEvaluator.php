<?php

declare(strict_types=1);

namespace App\Domain\Chatbots\Engine;

/**
 * Condition block: compares contact data, tags, segment membership or session values. Read-only.
 */
final class ConditionEvaluator
{
    /** @param list<array{field: string, op: string, value: ?string}> $rules */
    public function passes(string $match, array $rules, BotRuntime $rt): bool
    {
        if ($rules === []) {
            return false;
        }
        foreach ($rules as $rule) {
            $ok = $this->rule($rule, $rt);
            if ($match === 'any' && $ok) {
                return true;
            }
            if ($match !== 'any' && ! $ok) {
                return false;
            }
        }

        return $match !== 'any';
    }

    /** @param array{field: string, op: string, value: ?string} $rule */
    private function rule(array $rule, BotRuntime $rt): bool
    {
        $op = $rule['op'];
        $expected = Placeholders::fill($rule['value'], $rt);

        if ($rule['field'] === 'tag' || in_array($op, ['has_tag', 'not_has_tag'], true)) {
            $has = in_array(mb_strtolower($expected), array_map('mb_strtolower', $rt->contact['tags']), true);

            return $op === 'not_has_tag' || $op === 'not_equals' ? ! $has : $has;
        }
        if ($rule['field'] === 'segment' || in_array($op, ['in_segment', 'not_in_segment'], true)) {
            $in = $expected !== '' && $rt->inSegment($expected);

            return $op === 'not_in_segment' || $op === 'not_equals' ? ! $in : $in;
        }

        $actual = match (true) {
            $rule['field'] === 'name', $rule['field'] === 'email', $rule['field'] === 'phone' => $rt->contact[$rule['field']] ?? null,
            $rule['field'] === 'consent' => $rt->contact['consent'],
            $rule['field'] === 'last_input' => $rt->state['vars']['last_input'] ?? null,
            $rule['field'] === 'language' => $rt->state['vars']['_lang'] ?? null,
            str_starts_with($rule['field'], 'business:') => $rt->business()[substr($rule['field'], 9)] ?? null,
            str_starts_with($rule['field'], 'field:') => $rt->contact['fields'][substr($rule['field'], 6)] ?? null,
            str_starts_with($rule['field'], 'var:') => data_get($rt->state['vars'], substr($rule['field'], 4)),
            default => null,
        };
        $text = mb_strtolower(trim(Placeholders::text($actual)));
        $want = mb_strtolower(trim($expected));
        $number = fn (string $s): ?float => is_numeric(str_replace(',', '', $s)) ? (float) str_replace(',', '', $s) : null;

        return match ($op) {
            'is_set' => $text !== '',
            'is_not_set' => $text === '',
            'equals' => $number($text) !== null && $number($want) !== null ? $number($text) === $number($want) : $text === $want,
            'not_equals' => ! ($number($text) !== null && $number($want) !== null ? $number($text) === $number($want) : $text === $want),
            'contains' => $want !== '' && str_contains($text, $want),
            'not_contains' => $want === '' || ! str_contains($text, $want),
            'gt', 'lt' => $text !== '' && $this->compare($text, $want, $number) * ($op === 'gt' ? 1 : -1) > 0,
            'gte', 'lte' => $text !== '' && $this->compare($text, $want, $number) * ($op === 'gte' ? 1 : -1) >= 0,
            default => false,
        };
    }

    /** Numbers as numbers, dates (2026-10-15) as dates, otherwise text order. */
    private function compare(string $a, string $b, callable $number): int
    {
        if ($a === '') {
            return 0;
        }
        if ($number($a) !== null && $number($b) !== null) {
            return $number($a) <=> $number($b);
        }

        return strcmp($a, $b);
    }
}
