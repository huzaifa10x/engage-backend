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
    /**
     * @param  (callable(string): string)|null  $escape  applied to every inserted value (JSON bodies, URLs)
     * @param  bool  $human  customer-facing text: a saved date (2026-11-18) is shown as “Wed 18 Nov 2026”
     */
    public static function fill(?string $text, BotRuntime $rt, bool $secrets = false, ?callable $escape = null, bool $human = false): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        $pattern = '/\{\{\s*([A-Za-z0-9_.]+)\s*\}\}/';
        $replace = function (string $line) use ($rt, $secrets, $escape, $pattern, $human): string {
            return (string) preg_replace_callback($pattern, function (array $m) use ($rt, $secrets, $escape, $human): string {
                $value = self::resolve($m[1], $rt, $secrets);
                if ($human) {
                    $value = self::humanDate($value);
                }

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

    /** “2026-11-18” → “Wed 18 Nov 2026”; anything else unchanged. */
    public static function humanDate(string $value): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return $value;
        }

        return date('D j M Y', (int) gmmktime(12, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1]));
    }

    /**
     * Where the customer came from, from verified attribution only: the Meta ad referral on a
     * message in this session, else the contact's paid touch recorded by the Growth Hub.
     *
     * @return array{source: ?string, campaign: ?string}
     */
    public static function attribution(BotRuntime $rt): array
    {
        $s = $rt->state['vars']['_source'] ?? null;
        if (is_array($s) && isset($s['source'])) {
            return ['source' => (string) $s['source'], 'campaign' => isset($s['campaign']) ? (string) $s['campaign'] : null];
        }

        return ['source' => $rt->contact['lead_source'] ?? null, 'campaign' => $rt->contact['campaign'] ?? null];
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
                'lead_source' => self::attribution($rt)['source'],
                'campaign' => self::attribution($rt)['campaign'],
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
