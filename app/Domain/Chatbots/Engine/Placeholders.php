<?php

declare(strict_types=1);

namespace App\Domain\Chatbots\Engine;

/**
 * {{contact.name}}, {{contact.first_name}}, {{contact.phone}}, {{contact.email}},
 * {{contact.language}}, {{contact.<custom field key>}}, {{var.<name>}} (answers, API results, the
 * chosen product: {{var.product.name}}), {{last_input}}, {{business.<key>}} (the workspace's
 * business variables: name, address, hours, support_phone, store_url …; also written
 * {{business_name}}), {{bot.name}} and — in API-call blocks only — {{secret.NAME}}.
 * Unknown placeholders become empty text, never the raw braces.
 */
final class Placeholders
{
    /** @param (callable(string): string)|null $escape applied to every inserted value (JSON bodies, URLs) */
    public static function fill(?string $text, BotRuntime $rt, bool $secrets = false, ?callable $escape = null): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        $pattern = '/\{\{\s*([A-Za-z0-9_.]+)\s*\}\}/';
        $replace = function (string $line) use ($rt, $secrets, $escape, $pattern): string {
            return (string) preg_replace_callback($pattern, function (array $m) use ($rt, $secrets, $escape): string {
                $value = self::resolve($m[1], $rt, $secrets);

                return $escape !== null ? $escape($value) : $value;
            }, $line);
        };
        if ($escape !== null || ! str_contains($text, "\n")) {
            return $replace($text);
        }

        // In messages, a line whose placeholders all came out empty is left out ("📍 {{business.address}}"
        // when no address is set), instead of sending a stray emoji or label.
        $out = [];
        foreach (explode("\n", $text) as $line) {
            if (preg_match_all($pattern, $line, $m) > 0 && array_filter($m[1], fn (string $p) => self::resolve($p, $rt, $secrets) !== '') === []) {
                continue;
            }
            $out[] = $replace($line);
        }

        return implode("\n", $out);
    }

    public static function resolve(string $path, BotRuntime $rt, bool $secrets = false): string
    {
        return self::text(self::raw($path, $rt, $secrets));
    }

    /** The value behind a placeholder, unformatted (a number stays a number). */
    public static function raw(string $path, BotRuntime $rt, bool $secrets = false): mixed
    {
        $parts = explode('.', $path);
        $head = array_shift($parts);
        $rest = implode('.', $parts);

        return match ($head) {
            'contact' => match ($rest) {
                'name', 'first_name', 'email', 'phone' => $rt->contact[$rest] ?? null,
                'language' => $rt->state['vars']['_lang'] ?? null,
                default => $rt->contact['fields'][$rest] ?? null,
            },
            'var' => data_get($rt->state['vars'], $rest),
            'last_input' => $rt->state['vars']['last_input'] ?? null,
            'secret' => $secrets ? $rt->secret($rest) : null,
            'business' => $rt->business()[$rest] ?? null,
            'bot' => $rest === 'name' ? $rt->botName() : null,
            // {{business_name}}, {{store_url}}: business variables written without the prefix.
            default => $rest === '' ? ($rt->business()[$head] ?? $rt->business()[(string) preg_replace('/^business_/', '', $head)] ?? null) : null,
        };
    }

    public static function text(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'yes' : 'no',
            is_float($value) && floor($value) === $value && abs($value) < 1e15 => (string) (int) $value,
            is_scalar($value) => (string) $value,
            is_array($value) && array_is_list($value) && array_filter($value, 'is_scalar') === $value => implode(', ', $value),
            default => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        };
    }
}
