<?php

declare(strict_types=1);

namespace App\Domain\Chatbots\Engine;

/**
 * Cleans the author's settings of one block (whatever the builder sends) into the exact shape the
 * engine reads, enforcing WhatsApp's limits: header ≤60, body ≤1024 (text message 4096), footer ≤60,
 * button ≤20, list row title ≤24, row description ≤72, ≤3 buttons, ≤10 rows.
 */
final class NodeConfig
{
    public const LIMITS = ['header' => 60, 'body' => 1024, 'text' => 4096, 'footer' => 60, 'button' => 20, 'row_title' => 24, 'row_description' => 72, 'buttons' => 3, 'rows' => 10];

    public const VALIDATIONS = ['any', 'text', 'number', 'email', 'date', 'future_date', 'workday', 'phone', 'regex'];

    public const OPERATORS = ['equals', 'not_equals', 'contains', 'not_contains', 'gt', 'gte', 'lt', 'lte', 'is_set', 'is_not_set', 'has_tag', 'not_has_tag', 'in_segment', 'not_in_segment'];

    /** What happens to an answer that matches no option: ask again then hand to a person (default), ask again then follow the "No match" branch, or stay silent. */
    public const NO_MATCH = ['handoff', 'branch', 'off'];

    /** Fields of a node that can be translated (per-language variants). */
    public const TRANSLATABLE = ['text', 'header', 'footer', 'caption', 'button_label', 'section_title', 'error_text', 'offline_text', 'order_label', 'cta', 'all_label'];

    /**
     * @param  array<string, mixed>  $c
     * @return array<string, mixed>
     */
    public static function clean(string $type, array $c): array
    {
        $text = fn (int $max = 1024) => self::str($c['text'] ?? null, $max);
        $assign = fn () => [
            'assign' => in_array($c['assign'] ?? null, ['none', 'auto', 'member'], true) ? $c['assign'] : 'none',
            'member_id' => self::uuid($c['member_id'] ?? null),
        ];
        // Input blocks: unrecognised answers, retries and the per-step "no reply" timer.
        $input = fn () => [
            'no_match' => in_array($c['no_match'] ?? null, self::NO_MATCH, true) ? $c['no_match'] : 'handoff',
            'retries' => isset($c['retries']) && $c['retries'] !== '' ? max(0, min(5, (int) $c['retries'])) : null,
            'timeout_minutes' => isset($c['timeout_minutes']) && (int) $c['timeout_minutes'] > 0 ? max(1, min(10080, (int) $c['timeout_minutes'])) : null,
        ];

        $config = match ($type) {
            'start' => [
                'any_message' => (bool) ($c['any_message'] ?? true),
                'greeting' => (bool) ($c['greeting'] ?? false),
                'keywords' => self::words($c['keywords'] ?? [], 30, 60),
                'keyword_match' => in_array($c['keyword_match'] ?? null, ['exact', 'starts', 'contains'], true) ? $c['keyword_match'] : 'starts',
                'regex' => self::regex($c['regex'] ?? null),
                'ad_referral' => (bool) ($c['ad_referral'] ?? true),
                'qr_code' => self::str($c['qr_code'] ?? null, 40),
                'campaign_buttons' => self::words($c['campaign_buttons'] ?? [], 20, 40),
                'tags_added' => self::words($c['tags_added'] ?? [], 10, 40, false),
                'fields_changed' => array_values(array_filter(self::words($c['fields_changed'] ?? [], 10, 40), fn ($k) => preg_match('/^[a-z0-9_]{1,40}$/', $k) === 1)),
                'webhook' => (bool) ($c['webhook'] ?? false),
                'reentry' => in_array($c['reentry'] ?? null, ['always', 'once', 'cooldown'], true) ? $c['reentry'] : 'always',
                'cooldown_hours' => max(1, min(720, (int) ($c['cooldown_hours'] ?? 24))),
            ],
            'message' => ['text' => $text(NodeConfig::LIMITS['text'])],
            'media' => [
                'kind' => in_array($c['kind'] ?? null, ['image', 'video', 'document', 'audio'], true) ? $c['kind'] : 'image',
                'media_id' => self::uuid($c['media_id'] ?? null),
                'media_name' => self::str($c['media_name'] ?? null, 120),
                'link' => self::https($c['link'] ?? null),
                'caption' => self::str($c['caption'] ?? null, 1024),
                'filename' => self::str($c['filename'] ?? null, 120),
            ],
            'buttons' => [
                'header' => self::str($c['header'] ?? null, 60),
                'text' => $text(1024),
                'footer' => self::str($c['footer'] ?? null, 60),
                'buttons' => self::choices($c['buttons'] ?? [], 3, 20, false),
            ] + $input(),
            'list' => [
                'header' => self::str($c['header'] ?? null, 60),
                'text' => $text(1024),
                'footer' => self::str($c['footer'] ?? null, 60),
                'button_label' => self::str($c['button_label'] ?? null, 20) ?: 'Choose',
                'sections' => self::sections($c),
            ] + $input(),
            'product' => [
                'source' => 'manual',
                'text' => $text(1024),
                'mode' => in_array($c['mode'] ?? null, ['cards', 'images', 'list'], true) ? $c['mode'] : 'cards',
                'button_label' => self::str($c['button_label'] ?? null, 20) ?: 'View products',
                'order_label' => self::str($c['order_label'] ?? null, 20) ?: 'View & order',
                'items' => self::products($c['items'] ?? []),
                'all_label' => self::str($c['all_label'] ?? null, 20),
                'all_url' => self::link($c['all_url'] ?? null),
                'save_to' => self::varName($c['save_to'] ?? 'product') ?? 'product',
            ] + $input(),
            'template' => [
                'name' => self::str($c['name'] ?? null, 512),
                'language' => self::str($c['language'] ?? null, 15),
                'header' => self::values($c['header'] ?? []),
                'body' => self::values($c['body'] ?? []),
                'on_failure' => (bool) ($c['on_failure'] ?? false),
            ],
            'url' => [
                'header' => self::str($c['header'] ?? null, 60),
                'text' => $text(1024),
                'footer' => self::str($c['footer'] ?? null, 60),
                'cta' => self::str($c['cta'] ?? null, 20) ?: 'Open',
                'url' => self::str($c['url'] ?? null, 1000),
            ],
            'form' => [
                'header' => self::str($c['header'] ?? null, 60),
                'text' => $text(1024),
                'footer' => self::str($c['footer'] ?? null, 60),
                'flow_id' => preg_match('/^\d{5,25}$/', (string) ($c['flow_id'] ?? '')) === 1 ? (string) $c['flow_id'] : null,
                'cta' => self::str($c['cta'] ?? null, 20) ?: 'Open form',
                'screen' => self::str($c['screen'] ?? null, 60),
                'draft' => (bool) ($c['draft'] ?? false),
                'fields' => array_values(array_filter(self::pairs($c['fields'] ?? [], 'key', 'to', 20, 80, 80), fn (array $p) => self::saveTarget($p['to']) !== null)),
            ] + $input(),
            'question' => [
                'text' => $text(1024),
                'save_to' => self::saveTarget($c['save_to'] ?? null),
                'validate' => in_array($c['validate'] ?? null, self::VALIDATIONS, true) ? $c['validate'] : 'any',
                'pattern' => self::regex($c['pattern'] ?? null),
                'error_text' => self::str($c['error_text'] ?? null, 300),
            ] + $input(),
            'ask_media' => [
                'text' => $text(1024),
                'accept' => in_array($c['accept'] ?? null, ['image', 'document', 'any'], true) ? $c['accept'] : 'image',
                'optional' => (bool) ($c['optional'] ?? false),
                'skip_label' => self::str($c['skip_label'] ?? null, 20) ?: 'Skip',
                'save_to' => self::saveTarget($c['save_to'] ?? 'var:photo') ?? 'var:photo',
                'error_text' => self::str($c['error_text'] ?? null, 300),
            ] + $input(),
            'location' => [
                'text' => $text(1024),
                'save_to' => self::varName($c['save_to'] ?? 'location') ?? 'location',
                'error_text' => self::str($c['error_text'] ?? null, 300),
            ] + $input(),
            'rating' => [
                'text' => $text(1024),
                'button_label' => self::str($c['button_label'] ?? null, 20) ?: 'Rate us',
                'save_to' => self::saveTarget($c['save_to'] ?? 'var:rating') ?? 'var:rating',
            ] + $input(),
            'condition' => [
                'match' => ($c['match'] ?? 'all') === 'any' ? 'any' : 'all',
                'rules' => self::rules($c['rules'] ?? []),
            ],
            'setfield' => [
                'target' => self::saveTarget($c['target'] ?? null),
                'value' => self::str($c['value'] ?? null, 1000),
            ],
            'calculate' => [
                'expression' => self::str($c['expression'] ?? null, 500),
                'target' => self::saveTarget($c['target'] ?? null),
                'decimals' => max(0, min(6, (int) ($c['decimals'] ?? 2))),
            ],
            'delay' => [
                'mode' => ($c['mode'] ?? 'duration') === 'until' ? 'until' : 'duration',
                'minutes' => max(1, min(10080, (int) ($c['minutes'] ?? 10))),
                'until' => self::str($c['until'] ?? null, 200),
                'offset_minutes' => max(-43200, min(43200, (int) ($c['offset_minutes'] ?? 0))),
            ],
            'tag' => [
                'add' => self::words($c['add'] ?? [], 10, 40, false),
                'remove' => self::words($c['remove'] ?? [], 10, 40, false),
            ] + $assign(),
            'optin' => [
                'text' => $text(1024),
            ],
            'optout' => [
                'scope' => ($c['scope'] ?? 'marketing') === 'all' ? 'all' : 'marketing',
                'text' => $text(1024),
            ],
            'hours' => [
                'mode' => ($c['mode'] ?? 'business') === 'custom' ? 'custom' : 'business',
                'days' => array_values(array_intersect(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], (array) ($c['days'] ?? ['mon', 'tue', 'wed', 'thu', 'fri']))),
                'from' => self::clock($c['from'] ?? null, '09:00'),
                'to' => self::clock($c['to'] ?? null, '18:00'),
                'closed_dates' => array_values(array_filter(array_map(fn ($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $d) === 1 ? (string) $d : null, array_slice((array) ($c['closed_dates'] ?? []), 0, 60)))),
            ],
            'split' => [
                'percent_a' => max(1, min(99, (int) ($c['percent_a'] ?? 50))),
                // The block that counts as success when comparing A and B (empty = the flow is completed or handed off).
                'goal' => is_string($c['goal'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $c['goal']) === 1 ? $c['goal'] : null,
            ],
            'http' => [
                'method' => in_array(strtoupper((string) ($c['method'] ?? 'GET')), ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true) ? strtoupper((string) $c['method']) : 'GET',
                'url' => self::str($c['url'] ?? null, 1000),
                'headers' => self::pairs($c['headers'] ?? [], 'key', 'value', 10, 100, 1000),
                'body' => self::str($c['body'] ?? null, 5000),
                'timeout' => max(1, min(10, (int) ($c['timeout'] ?? 5))),
                'retries' => max(0, min(2, (int) ($c['retries'] ?? 1))),
                'save' => array_values(array_filter(self::pairs($c['save'] ?? [], 'path', 'to', 10, 200, 80), fn (array $p) => self::saveTarget($p['to']) !== null)),
            ],
            'order' => [
                'order_number' => self::str($c['order_number'] ?? null, 200) ?? '{{last_input}}',
                'save_to' => self::varName($c['save_to'] ?? 'order') ?? 'order',
            ],
            'sheets' => [
                'webapp_url' => self::str($c['webapp_url'] ?? null, 1000),
                'sheet' => self::str($c['sheet'] ?? null, 100),
                'columns' => self::pairs($c['columns'] ?? [], 'name', 'value', 20, 60, 500),
            ],
            'goto' => ['target' => is_string($c['target'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $c['target']) === 1 ? $c['target'] : null],
            'subflow' => ['flow_id' => self::uuid($c['flow_id'] ?? null)],
            'note' => ['text' => $text(2000)],
            'handoff' => [
                'text' => $text(1024),
                'offline_text' => self::str($c['offline_text'] ?? null, 1024),
                'note' => (bool) ($c['note'] ?? true),
            ] + $assign(),
            'end' => [
                'text' => $text(1024),
                'action' => in_array($c['action'] ?? null, ['none', 'close', 'assign'], true) ? $c['action'] : 'none',
            ] + $assign(),
            default => [],
        };

        if (isset($c['i18n']) && is_array($c['i18n'])) {
            $config['i18n'] = self::translations($type, $c['i18n'], $config);
        }

        return $config;
    }

    /**
     * Per-language variants: the same fields in another language (button and row titles by id).
     *
     * @param  array<mixed>  $i18n
     * @param  array<string, mixed>  $config
     * @return array<string, array<string, mixed>>
     */
    private static function translations(string $type, array $i18n, array $config): array
    {
        $out = [];
        foreach (array_slice($i18n, 0, 6, true) as $lang => $t) {
            if (! is_string($lang) || preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $lang) !== 1 || ! is_array($t)) {
                continue;
            }
            $v = [];
            foreach (self::TRANSLATABLE as $field) {
                if (array_key_exists($field, $config) && ($s = self::str($t[$field] ?? null, match ($field) {
                    'header', 'footer' => 60, 'button_label', 'order_label', 'cta', 'all_label' => 20, 'section_title' => 24, 'error_text' => 300, 'text' => $type === 'message' ? 4096 : 1024, default => 1024,
                })) !== null) {
                    $v[$field] = $s;
                }
            }
            foreach (['buttons' => 20, 'rows' => 24, 'items' => 60] as $list => $max) {
                foreach ((array) ($t[$list] ?? []) as $id => $title) {
                    if (is_string($id) && preg_match('/^[A-Za-z0-9_-]{1,24}$/', $id) === 1 && ($s = self::str(is_array($title) ? ($title['title'] ?? $title['name'] ?? null) : $title, $max)) !== null) {
                        $v[$list][$id] = is_array($title) && isset($title['description']) ? ['title' => $s, 'description' => self::str($title['description'], 72)] : $s;
                    }
                }
            }
            if ($v !== []) {
                $out[$lang] = $v;
            }
        }

        return $out;
    }

    // ── Small cleaners ───────────────────────────────────────────────────────────────────────

    public static function str(mixed $value, int $max): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $s = trim(mb_substr((string) $value, 0, $max));

        return $s === '' ? null : $s;
    }

    private static function https(mixed $value): ?string
    {
        $s = self::str($value, 1000);

        return $s !== null && str_starts_with(strtolower($s), 'https://') ? $s : null;
    }

    /** An https link, or one that starts with a business variable holding it ({{business.store_url}}/cakes). */
    public static function link(mixed $value): ?string
    {
        $s = self::str($value, 1000);

        return $s !== null && (str_starts_with(strtolower($s), 'https://') || preg_match('/^\{\{\s*business\.[a-z0-9_]+\s*\}\}/', $s) === 1) ? $s : null;
    }

    private static function uuid(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1 ? strtolower($value) : null;
    }

    private static function clock(mixed $value, string $default): string
    {
        return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1 ? $value : $default;
    }

    /** A regular expression the author typed, kept only when PHP can compile it. */
    private static function regex(mixed $value): ?string
    {
        $s = self::str($value, 200);
        if ($s === null) {
            return null;
        }

        return @preg_match('/'.str_replace('/', '\/', $s).'/u', '') === false ? null : $s;
    }

    /** @return list<string> */
    public static function words(mixed $list, int $count, int $max, bool $lower = true): array
    {
        $out = [];
        foreach (is_array($list) ? array_slice($list, 0, $count) : [] as $w) {
            $s = self::str($w, $max);
            if ($s !== null) {
                $s = $lower ? mb_strtolower($s) : $s;
                $out[mb_strtolower($s)] = $s;
            }
        }

        return array_values($out);
    }

    /** @return list<array{id: string, title: string, description?: ?string}> */
    public static function choices(mixed $list, int $count, int $max, bool $withDescription): array
    {
        $out = [];
        $ids = [];
        $titles = [];
        foreach (is_array($list) ? array_slice($list, 0, $count) : [] as $i => $b) {
            $title = self::str(is_array($b) ? ($b['title'] ?? null) : $b, $max);
            if ($title === null || isset($titles[mb_strtolower($title)])) {
                continue;
            }
            $id = is_array($b) && is_string($b['id'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,24}$/', $b['id']) === 1 && ! isset($ids[$b['id']]) && ! in_array($b['id'], ['fallback', 'timeout', 'next'], true)
                ? $b['id'] : 'o'.($i + 1).substr(md5($title), 0, 4);
            $ids[$id] = true;
            $titles[mb_strtolower($title)] = true;
            $choice = ['id' => $id, 'title' => $title];
            if ($withDescription) {
                $choice['description'] = self::str(is_array($b) ? ($b['description'] ?? null) : null, 72);
            }
            $out[] = $choice;
        }

        return $out;
    }

    /**
     * List sections (multi-section lists). Older flows stored one `rows` list + `section_title`.
     *
     * @param  array<string, mixed>  $c
     * @return list<array{title: ?string, rows: list<array{id: string, title: string, description?: ?string}>}>
     */
    private static function sections(array $c): array
    {
        $raw = isset($c['sections']) && is_array($c['sections']) ? $c['sections'] : [['title' => $c['section_title'] ?? null, 'rows' => $c['rows'] ?? []]];
        $out = [];
        $left = 10;
        $seen = [];
        foreach (array_slice($raw, 0, 10) as $s) {
            if (! is_array($s) || $left <= 0) {
                continue;
            }
            $rows = array_values(array_filter(self::choices(array_slice((array) ($s['rows'] ?? []), 0, $left), $left, 24, true), function (array $r) use (&$seen) {
                if (isset($seen[$r['id']])) {
                    return false;
                }
                $seen[$r['id']] = true;

                return true;
            }));
            $left -= count($rows);
            $out[] = ['title' => self::str($s['title'] ?? null, 24), 'rows' => $rows];
        }

        return $out === [] ? [['title' => null, 'rows' => []]] : $out;
    }

    /** @return list<array{id: string, name: string, price: ?string, description: ?string, image_url: ?string, link: ?string}> */
    private static function products(mixed $list): array
    {
        $out = [];
        $ids = [];
        foreach (is_array($list) ? array_slice($list, 0, 10) : [] as $i => $p) {
            if (! is_array($p) || ($name = self::str($p['name'] ?? null, 60)) === null) {
                continue;
            }
            $id = is_string($p['id'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,24}$/', $p['id']) === 1 && ! isset($ids[$p['id']]) ? $p['id'] : 'p'.($i + 1).substr(md5($name), 0, 4);
            $ids[$id] = true;
            $out[] = [
                'id' => $id,
                'name' => $name,
                'price' => self::str($p['price'] ?? null, 30),
                'description' => self::str($p['description'] ?? null, 300),
                'image_url' => self::https($p['image_url'] ?? null),
                'link' => self::link($p['link'] ?? null),
            ];
        }

        return $out;
    }

    /** @return list<string> */
    private static function values(mixed $list): array
    {
        return array_values(array_map(fn ($v) => mb_substr(is_scalar($v) ? (string) $v : '', 0, 500), is_array($list) ? array_slice($list, 0, 20) : []));
    }

    /** @return list<array{field: string, op: string, value: ?string}> */
    private static function rules(mixed $list): array
    {
        $out = [];
        foreach (is_array($list) ? array_slice($list, 0, 10) : [] as $r) {
            if (! is_array($r)) {
                continue;
            }
            $field = (string) ($r['field'] ?? '');
            $op = (string) ($r['op'] ?? '');
            $validField = in_array($field, ['name', 'email', 'phone', 'tag', 'consent', 'segment', 'last_input', 'language'], true)
                || preg_match('/^(field|var|business):[A-Za-z0-9_.]{1,64}$/', $field) === 1;
            if ($validField && in_array($op, self::OPERATORS, true)) {
                $out[] = ['field' => $field, 'op' => $op, 'value' => self::str($r['value'] ?? null, 200)];
            }
        }

        return $out;
    }

    /** @return list<array<string, string>> */
    private static function pairs(mixed $list, string $a, string $b, int $count, int $maxA, int $maxB): array
    {
        $out = [];
        foreach (is_array($list) ? array_slice($list, 0, $count) : [] as $p) {
            $k = is_array($p) ? self::str($p[$a] ?? null, $maxA) : null;
            $v = is_array($p) ? self::str($p[$b] ?? null, $maxB) : null;
            if ($k !== null && $v !== null) {
                $out[] = [$a => $k, $b => $v];
            }
        }

        return $out;
    }

    /** "field:<key>" | "name" | "email" | "var:<name>" */
    public static function saveTarget(mixed $value): ?string
    {
        $s = is_string($value) ? trim($value) : '';
        if (in_array($s, ['name', 'email'], true) || preg_match('/^field:[a-z0-9][a-z0-9_]{0,39}$/', $s) === 1) {
            return $s;
        }
        if (str_starts_with($s, 'var:') && self::varName(substr($s, 4)) !== null) {
            return 'var:'.self::varName(substr($s, 4));
        }

        return null;
    }

    public static function varName(mixed $value): ?string
    {
        $s = is_string($value) ? trim($value) : '';

        return preg_match('/^[A-Za-z][A-Za-z0-9_]{0,39}$/', $s) === 1 ? $s : null;
    }
}
