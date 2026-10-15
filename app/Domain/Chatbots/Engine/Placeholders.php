<?php

declare(strict_types=1);

namespace App\Domain\Chatbots\Engine;

/**
 * {{contact.name}}, {{contact.first_name}}, {{contact.phone}}, {{contact.email}},
 * {{contact.<custom field key>}}, {{var.<name>}} (answers, API results, the chosen product:
 * {{var.product.name}}), {{last_input}} and — in API-call blocks only — {{secret.NAME}}.
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

        return (string) preg_replace_callback('/\{\{\s*([A-Za-z0-9_.]+)\s*\}\}/', function (array $m) use ($rt, $secrets, $escape): string {
            $value = self::resolve($m[1], $rt, $secrets);

            return $escape !== null ? $escape($value) : $value;
        }, $text);
    }

    public static function resolve(string $path, BotRuntime $rt, bool $secrets = false): string
    {
        $parts = explode('.', $path);
        $head = array_shift($parts);
        $rest = implode('.', $parts);

        $value = match ($head) {
            'contact' => match ($rest) {
                'name', 'first_name', 'email', 'phone' => $rt->contact[$rest] ?? null,
                default => $rt->contact['fields'][$rest] ?? null,
            },
            'var' => data_get($rt->state['vars'], $rest),
            'last_input' => $rt->state['vars']['last_input'] ?? null,
            'secret' => $secrets ? $rt->secret($rest) : null,
            default => null,
        };

        return self::text($value);
    }

    public static function text(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'yes' : 'no',
            is_scalar($value) => (string) $value,
            is_array($value) && array_is_list($value) && array_filter($value, 'is_scalar') === $value => implode(', ', $value),
            default => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        };
    }
}
