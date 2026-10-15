<?php

declare(strict_types=1);

namespace App\Domain\Chatbots\Engine;

/**
 * The node contract. Every node is the same shape — id, type, author config, and an ordered list
 * of named outputs that each point at another node (or nowhere yet):
 *
 *   {id, type, title, x, y, config: {...}, outputs: [{id, label, to}]}
 *
 * The server derives the outputs from the config (buttons → one per button + "No match"), so a
 * client can never invent a branch the runtime does not know. normalize() cleans whatever the
 * builder sends; issues() is the publish gate.
 */
final class FlowGraph
{
    public const TYPES = ['start', 'message', 'buttons', 'list', 'question', 'product', 'media', 'template', 'condition', 'setfield', 'http', 'delay', 'tag', 'handoff', 'end'];

    /** Nodes that stop and wait for the customer's answer. */
    public const INPUT = ['buttons', 'list', 'question', 'product'];

    public const TERMINAL = ['handoff', 'end'];

    /** Nodes that send a normal (free-form) message, which needs the 24-hour window. */
    public const FREE_FORM = ['message', 'buttons', 'list', 'question', 'product', 'media'];

    public const MAX_NODES = 150;

    public const OPERATORS = ['equals', 'not_equals', 'contains', 'not_contains', 'gt', 'lt', 'is_set', 'is_not_set', 'has_tag', 'not_has_tag', 'in_segment', 'not_in_segment'];

    public const VALIDATIONS = ['any', 'text', 'number', 'email', 'date', 'phone'];

    /** @var array<string, array<string, mixed>> node id → node */
    private array $nodes;

    /** @param array<string, array<string, mixed>> $nodes */
    private function __construct(array $nodes)
    {
        $this->nodes = $nodes;
    }

    /** @param array<string, mixed>|null $graph */
    public static function from(?array $graph): self
    {
        return new self(self::index(self::normalize($graph)));
    }

    /** @return array<string, mixed>|null */
    public function node(?string $id): ?array
    {
        return $id !== null ? ($this->nodes[$id] ?? null) : null;
    }

    /** @return array<string, array<string, mixed>> */
    public function nodes(): array
    {
        return $this->nodes;
    }

    /** @return array<string, mixed>|null */
    public function start(): ?array
    {
        foreach ($this->nodes as $node) {
            if ($node['type'] === 'start') {
                return $node;
            }
        }

        return null;
    }

    /** Where an output of a node leads (null = not connected). */
    public function target(string $nodeId, string $outputId): ?string
    {
        foreach ((array) ($this->nodes[$nodeId]['outputs'] ?? []) as $out) {
            if ($out['id'] === $outputId) {
                return $out['to'] !== null && isset($this->nodes[$out['to']]) ? $out['to'] : null;
            }
        }

        return null;
    }

    // ── Cleaning what the builder sends ──────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>|null  $graph
     * @return array{nodes: list<array<string, mixed>>}
     */
    public static function normalize(?array $graph): array
    {
        $nodes = [];
        $seen = [];
        foreach (array_slice(array_values((array) ($graph['nodes'] ?? [])), 0, self::MAX_NODES) as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $id = (string) ($raw['id'] ?? '');
            $type = (string) ($raw['type'] ?? '');
            if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) !== 1 || isset($seen[$id]) || ! in_array($type, self::TYPES, true)) {
                continue;
            }
            $seen[$id] = true;
            $config = self::config($type, (array) ($raw['config'] ?? []));
            $targets = [];
            foreach ((array) ($raw['outputs'] ?? []) as $out) {
                if (is_array($out) && isset($out['id'])) {
                    $to = $out['to'] ?? null;
                    $targets[(string) $out['id']] = is_string($to) && $to !== '' ? $to : null;
                }
            }
            $nodes[] = [
                'id' => $id,
                'type' => $type,
                'title' => self::str($raw['title'] ?? null, 60) ?: self::defaultTitle($type),
                'x' => (int) max(0, min(20000, (float) ($raw['x'] ?? 0))),
                'y' => (int) max(0, min(20000, (float) ($raw['y'] ?? 0))),
                'config' => $config,
                'outputs' => array_map(fn (array $o) => $o + ['to' => $targets[$o['id']] ?? null], self::outputs($type, $config)),
            ];
        }

        // A branch may only point at a node that exists (and never at the start node).
        $ids = array_flip(array_column($nodes, 'id'));
        $startIds = array_column(array_filter($nodes, fn ($n) => $n['type'] === 'start'), 'id');
        foreach ($nodes as &$node) {
            foreach ($node['outputs'] as &$out) {
                if ($out['to'] !== null && (! isset($ids[$out['to']]) || in_array($out['to'], $startIds, true))) {
                    $out['to'] = null;
                }
            }
        }
        unset($node, $out);

        return ['nodes' => $nodes];
    }

    /**
     * @param  array<string, mixed>  $c
     * @return array<string, mixed>
     */
    private static function config(string $type, array $c): array
    {
        $text = fn (int $max = 1024) => self::str($c['text'] ?? null, $max);
        $assign = fn () => [
            'assign' => in_array($c['assign'] ?? null, ['none', 'auto', 'member'], true) ? $c['assign'] : 'none',
            'member_id' => is_string($c['member_id'] ?? null) && preg_match('/^[0-9a-f-]{36}$/i', $c['member_id']) === 1 ? $c['member_id'] : null,
        ];

        return match ($type) {
            'start' => [
                'any_message' => (bool) ($c['any_message'] ?? true),
                'keywords' => self::words($c['keywords'] ?? [], 30, 60),
                'ad_referral' => (bool) ($c['ad_referral'] ?? true),
            ],
            'message' => ['text' => $text(4096)],
            'buttons' => [
                'header' => self::str($c['header'] ?? null, 60),
                'text' => $text(1024),
                'footer' => self::str($c['footer'] ?? null, 60),
                'buttons' => self::choices($c['buttons'] ?? [], 3, 20, false),
            ],
            'list' => [
                'header' => self::str($c['header'] ?? null, 60),
                'text' => $text(1024),
                'footer' => self::str($c['footer'] ?? null, 60),
                'button_label' => self::str($c['button_label'] ?? null, 20) ?: 'Choose',
                'section_title' => self::str($c['section_title'] ?? null, 24),
                'rows' => self::choices($c['rows'] ?? [], 10, 24, true),
            ],
            'question' => [
                'text' => $text(1024),
                'save_to' => self::saveTarget($c['save_to'] ?? null),
                'validate' => in_array($c['validate'] ?? null, self::VALIDATIONS, true) ? $c['validate'] : 'any',
                'error_text' => self::str($c['error_text'] ?? null, 300),
            ],
            'product' => [
                'text' => $text(1024),
                'mode' => in_array($c['mode'] ?? null, ['cards', 'images', 'list'], true) ? $c['mode'] : 'list',
                'button_label' => self::str($c['button_label'] ?? null, 20) ?: 'View products',
                'order_label' => self::str($c['order_label'] ?? null, 20) ?: 'Order',
                'items' => self::products($c['items'] ?? []),
                'save_to' => self::varName($c['save_to'] ?? 'product') ?? 'product',
            ],
            'media' => [
                'kind' => in_array($c['kind'] ?? null, ['image', 'video', 'document', 'location'], true) ? $c['kind'] : 'image',
                'link' => self::str($c['link'] ?? null, 1000),
                'caption' => self::str($c['caption'] ?? null, 1024),
                'filename' => self::str($c['filename'] ?? null, 120),
                'latitude' => is_numeric($c['latitude'] ?? null) ? max(-90, min(90, (float) $c['latitude'])) : null,
                'longitude' => is_numeric($c['longitude'] ?? null) ? max(-180, min(180, (float) $c['longitude'])) : null,
                'name' => self::str($c['name'] ?? null, 100),
                'address' => self::str($c['address'] ?? null, 300),
            ],
            'template' => [
                'name' => self::str($c['name'] ?? null, 512),
                'language' => self::str($c['language'] ?? null, 15),
                'header' => self::values($c['header'] ?? []),
                'body' => self::values($c['body'] ?? []),
            ],
            'condition' => [
                'match' => ($c['match'] ?? 'all') === 'any' ? 'any' : 'all',
                'rules' => self::rules($c['rules'] ?? []),
            ],
            'setfield' => [
                'target' => self::saveTarget($c['target'] ?? null),
                'value' => self::str($c['value'] ?? null, 1000),
            ],
            'http' => [
                'method' => in_array(strtoupper((string) ($c['method'] ?? 'GET')), ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true) ? strtoupper((string) $c['method']) : 'GET',
                'url' => self::str($c['url'] ?? null, 1000),
                'headers' => self::pairs($c['headers'] ?? [], 'key', 'value', 10, 100, 1000),
                'body' => self::str($c['body'] ?? null, 5000),
                'timeout' => max(1, min(10, (int) ($c['timeout'] ?? 5))),
                'retries' => max(0, min(2, (int) ($c['retries'] ?? 1))),
                'save' => array_values(array_filter(
                    self::pairs($c['save'] ?? [], 'path', 'to', 10, 200, 80),
                    fn (array $p) => self::saveTarget($p['to']) !== null,
                )),
            ],
            'delay' => ['minutes' => max(1, min(10080, (int) ($c['minutes'] ?? 10)))],
            'tag' => [
                'add' => self::words($c['add'] ?? [], 10, 40, false),
                'remove' => self::words($c['remove'] ?? [], 10, 40, false),
            ] + $assign(),
            'handoff' => [
                'text' => $text(1024),
                'offline_text' => self::str($c['offline_text'] ?? null, 1024),
            ] + $assign(),
            'end' => [
                'text' => $text(1024),
                'action' => in_array($c['action'] ?? null, ['none', 'close', 'assign'], true) ? $c['action'] : 'none',
            ] + $assign(),
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<array{id: string, label: string}>
     */
    public static function outputs(string $type, array $config): array
    {
        $choices = fn (string $key) => array_map(fn (array $b) => ['id' => $b['id'], 'label' => $b['title']], (array) ($config[$key] ?? []));

        return match ($type) {
            'start' => [['id' => 'next', 'label' => 'Start']],
            'message', 'media' => [['id' => 'next', 'label' => 'Next']],
            'template' => [['id' => 'next', 'label' => 'Sent']],
            'setfield', 'tag' => [['id' => 'next', 'label' => 'Done']],
            'delay' => [['id' => 'next', 'label' => 'After wait']],
            'buttons' => [...$choices('buttons'), ['id' => 'fallback', 'label' => 'No match']],
            'list' => [...$choices('rows'), ['id' => 'fallback', 'label' => 'No match']],
            'question' => [['id' => 'next', 'label' => 'Answered'], ['id' => 'fallback', 'label' => 'Invalid']],
            'product' => [['id' => 'next', 'label' => 'Product selected'], ['id' => 'fallback', 'label' => 'No match']],
            'condition' => [['id' => 'yes', 'label' => 'Yes'], ['id' => 'no', 'label' => 'No / else']],
            'http' => [['id' => 'success', 'label' => 'Success'], ['id' => 'error', 'label' => 'Error']],
            default => [],
        };
    }

    public static function defaultTitle(string $type): string
    {
        return match ($type) {
            'start' => 'Conversation starts',
            'message' => 'Message',
            'buttons' => 'Buttons',
            'list' => 'List menu',
            'question' => 'Question',
            'product' => 'Products',
            'media' => 'Media',
            'template' => 'Template',
            'condition' => 'Condition',
            'setfield' => 'Set field',
            'http' => 'API call',
            'delay' => 'Wait',
            'tag' => 'Tag / assign',
            'handoff' => 'Talk to a person',
            'end' => 'End',
            default => ucfirst($type),
        };
    }

    // ── Publish gate ─────────────────────────────────────────────────────────────────────────

    /**
     * Everything that blocks go-live (level "error") or deserves a look ("warning").
     *
     * @param  array<string, true>  $knownFields  custom field keys of the workspace
     * @return list<array{level: string, node_id: ?string, message: string}>
     */
    public function issues(array $knownFields = []): array
    {
        $issues = [];
        $error = function (?string $node, string $message) use (&$issues): void {
            $issues[] = ['level' => 'error', 'node_id' => $node, 'message' => $message];
        };
        $warn = function (?string $node, string $message) use (&$issues): void {
            $issues[] = ['level' => 'warning', 'node_id' => $node, 'message' => $message];
        };

        $starts = array_filter($this->nodes, fn ($n) => $n['type'] === 'start');
        if (count($starts) !== 1) {
            $error(null, count($starts) === 0 ? 'Add a Start block: it decides which messages begin the flow.' : 'A flow has exactly one Start block. Delete the extra ones.');

            return $issues;
        }
        $start = array_values($starts)[0];
        $sc = $start['config'];
        if (! $sc['any_message'] && $sc['keywords'] === [] && ! $sc['ad_referral']) {
            $error($start['id'], 'The Start block has no trigger: choose "Any message", add keywords, or allow ad clicks.');
        }

        foreach ($this->nodes as $id => $node) {
            $name = "“{$node['title']}”";
            foreach ($node['outputs'] as $out) {
                if ($out['to'] === null) {
                    $error($id, "{$name} has an unconnected branch: {$out['label']}. Connect every branch to a next step.");
                }
            }
            foreach ($this->configProblems($node, $knownFields) as $problem) {
                $error($id, "{$name}: {$problem}");
            }
        }

        // Reachability: no orphan blocks, and every block can still reach an exit.
        $reachable = $this->reachableFrom($start['id']);
        foreach ($this->nodes as $id => $node) {
            if (! isset($reachable[$id])) {
                $error($id, "“{$node['title']}” can never be reached from Start. Connect it, or delete it.");
            }
        }
        $exits = array_keys(array_filter($this->nodes, fn ($n) => in_array($n['type'], self::TERMINAL, true)));
        if (! array_filter($exits, fn ($e) => $this->nodes[$e]['type'] === 'handoff' && isset($reachable[$e]))) {
            $error(null, 'Add a Talk to a person (Handoff) block that customers can reach, so a human is always one step away.');
        }
        $canFinish = $this->reachingAny($exits);
        foreach ($reachable as $id => $_) {
            if (! isset($canFinish[$id])) {
                $error($id, "“{$this->nodes[$id]['title']}” loops forever: no path from it leads to a Handoff or End block.");
            }
        }

        // The 24-hour window: after a long wait the customer may not have written for a day.
        foreach ($this->nodes as $id => $node) {
            if ($node['type'] !== 'delay') {
                continue;
            }
            $minutes = (int) $node['config']['minutes'];
            foreach ($this->freeFormAfter($id) as $sendId) {
                $send = "“{$this->nodes[$sendId]['title']}”";
                if ($minutes >= 1440) {
                    $error($sendId, "{$send} is sent after a wait of a day or more, when the 24-hour window is closed. Use a Template block there.");
                } elseif ($minutes >= 120) {
                    $warn($sendId, "{$send} is sent after a long wait. If the customer has not written for 24 hours by then it is not delivered; a Template block always is.");
                }
            }
        }

        return $issues;
    }

    /** @return list<array{level: string, node_id: ?string, message: string}> */
    public function errors(): array
    {
        return array_values(array_filter($this->issues(), fn ($i) => $i['level'] === 'error'));
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, true>  $knownFields
     * @return list<string>
     */
    private function configProblems(array $node, array $knownFields): array
    {
        $c = $node['config'];
        $p = [];
        $needsText = in_array($node['type'], ['message', 'buttons', 'list', 'question', 'product'], true);
        if ($needsText && trim((string) $c['text']) === '') {
            $p[] = 'write the message text.';
        }
        $targetOk = function (?string $target) use ($knownFields): bool {
            if ($target === null) {
                return false;
            }
            if (str_starts_with($target, 'field:')) {
                return $knownFields === [] || isset($knownFields[substr($target, 6)]);
            }

            return true;
        };

        switch ($node['type']) {
            case 'buttons':
                if ($c['buttons'] === []) {
                    $p[] = 'add at least one button (up to 3).';
                }
                break;
            case 'list':
                if ($c['rows'] === []) {
                    $p[] = 'add at least one row (up to 10).';
                }
                break;
            case 'question':
                if (! $targetOk($c['save_to'])) {
                    $p[] = 'choose where the answer is saved.';
                }
                break;
            case 'product':
                if ($c['items'] === []) {
                    $p[] = 'add at least one product.';
                }
                if ($c['mode'] !== 'list' && array_filter($c['items'], fn ($i) => $i['image_url'] === null)) {
                    $p[] = 'every product needs a picture (https link) to be shown as '.($c['mode'] === 'cards' ? 'cards' : 'pictures').'.';
                }
                break;
            case 'media':
                if ($c['kind'] === 'location') {
                    if ($c['latitude'] === null || $c['longitude'] === null) {
                        $p[] = 'enter the latitude and longitude.';
                    }
                } elseif ($c['link'] === null || ! str_starts_with(strtolower($c['link']), 'https://')) {
                    $p[] = 'enter the public https link of the file.';
                }
                break;
            case 'template':
                if ($c['name'] === null || $c['language'] === null) {
                    $p[] = 'choose an approved template.';
                }
                break;
            case 'condition':
                if ($c['rules'] === []) {
                    $p[] = 'add at least one rule.';
                }
                break;
            case 'setfield':
                if (! $targetOk($c['target'])) {
                    $p[] = 'choose the field to set.';
                }
                break;
            case 'http':
                if ($c['url'] === null || preg_match('#^https://[^\s/$.?\#].[^\s]*$#i', (string) preg_replace('/\{\{[^}]+\}\}/', 'x', $c['url'])) !== 1) {
                    $p[] = 'enter an https URL.';
                }
                break;
            case 'tag':
                if ($c['add'] === [] && $c['remove'] === [] && $c['assign'] === 'none') {
                    $p[] = 'add or remove a tag, or assign the conversation.';
                }
                if ($c['assign'] === 'member' && $c['member_id'] === null) {
                    $p[] = 'choose the teammate.';
                }
                break;
            case 'handoff':
            case 'end':
                if ($c['assign'] === 'member' && $c['member_id'] === null) {
                    $p[] = 'choose the teammate.';
                }
                break;
        }

        return $p;
    }

    /** @return array<string, true> */
    private function reachableFrom(string $from): array
    {
        $seen = [$from => true];
        $queue = [$from];
        while ($queue !== []) {
            $id = array_shift($queue);
            foreach ($this->nodes[$id]['outputs'] ?? [] as $out) {
                if ($out['to'] !== null && isset($this->nodes[$out['to']]) && ! isset($seen[$out['to']])) {
                    $seen[$out['to']] = true;
                    $queue[] = $out['to'];
                }
            }
        }

        return $seen;
    }

    /**
     * Nodes from which at least one of $targets can be reached.
     *
     * @param  list<string>  $targets
     * @return array<string, true>
     */
    private function reachingAny(array $targets): array
    {
        $incoming = [];
        foreach ($this->nodes as $id => $node) {
            foreach ($node['outputs'] as $out) {
                if ($out['to'] !== null) {
                    $incoming[$out['to']][] = $id;
                }
            }
        }
        $seen = array_fill_keys($targets, true);
        $queue = $targets;
        while ($queue !== []) {
            $id = array_shift($queue);
            foreach ($incoming[$id] ?? [] as $from) {
                if (! isset($seen[$from])) {
                    $seen[$from] = true;
                    $queue[] = $from;
                }
            }
        }

        return $seen;
    }

    /**
     * Free-form sending nodes that run right after a Delay, before the customer answers anything.
     *
     * @return list<string>
     */
    private function freeFormAfter(string $delayId): array
    {
        $found = [];
        $seen = [$delayId => true];
        $queue = [$this->nodes[$delayId]['outputs'][0]['to'] ?? null];
        while ($queue !== []) {
            $id = array_shift($queue);
            if ($id === null || isset($seen[$id]) || ! isset($this->nodes[$id])) {
                continue;
            }
            $seen[$id] = true;
            $node = $this->nodes[$id];
            $sendsText = in_array($node['type'], self::FREE_FORM, true)
                || (in_array($node['type'], self::TERMINAL, true) && trim((string) ($node['config']['text'] ?? '')) !== '');
            if ($sendsText) {
                $found[] = $id;
            }
            // An answer from the customer opens a new window: stop looking past input nodes.
            if (in_array($node['type'], self::INPUT, true) || in_array($node['type'], self::TERMINAL, true)) {
                continue;
            }
            foreach ($node['outputs'] as $out) {
                $queue[] = $out['to'];
            }
        }

        return $found;
    }

    // ── Small cleaners ───────────────────────────────────────────────────────────────────────

    private static function str(mixed $value, int $max): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $s = trim(mb_substr((string) $value, 0, $max));

        return $s === '' ? null : $s;
    }

    /** @return list<string> */
    private static function words(mixed $list, int $count, int $max, bool $lower = true): array
    {
        $out = [];
        foreach (is_array($list) ? array_slice($list, 0, $count) : [] as $w) {
            $s = self::str($w, $max);
            if ($s !== null) {
                $out[$lower ? mb_strtolower($s) : $s] = $lower ? mb_strtolower($s) : $s;
            }
        }

        return array_values($out);
    }

    /** @return list<array{id: string, title: string, description?: ?string}> */
    private static function choices(mixed $list, int $count, int $max, bool $withDescription): array
    {
        $out = [];
        $ids = [];
        $titles = [];
        foreach (is_array($list) ? array_slice($list, 0, $count) : [] as $i => $b) {
            $title = self::str(is_array($b) ? ($b['title'] ?? null) : $b, $max);
            if ($title === null || isset($titles[mb_strtolower($title)])) {
                continue;
            }
            $id = is_array($b) && is_string($b['id'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,24}$/', $b['id']) === 1 && ! isset($ids[$b['id']]) && $b['id'] !== 'fallback'
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

    /** @return list<array{id: string, name: string, price: ?string, description: ?string, image_url: ?string, link: ?string}> */
    private static function products(mixed $list): array
    {
        $out = [];
        foreach (is_array($list) ? array_slice($list, 0, 10) : [] as $i => $p) {
            if (! is_array($p) || ($name = self::str($p['name'] ?? null, 60)) === null) {
                continue;
            }
            $https = fn (mixed $u) => ($s = self::str($u, 1000)) !== null && str_starts_with(strtolower($s), 'https://') ? $s : null;
            $out[] = [
                'id' => is_string($p['id'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,24}$/', $p['id']) === 1 ? $p['id'] : 'p'.($i + 1),
                'name' => $name,
                'price' => self::str($p['price'] ?? null, 30),
                'description' => self::str($p['description'] ?? null, 300),
                'image_url' => $https($p['image_url'] ?? null),
                'link' => $https($p['link'] ?? null),
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
            $validField = in_array($field, ['name', 'email', 'phone', 'tag', 'consent', 'segment', 'last_input'], true)
                || preg_match('/^(field|var):[A-Za-z0-9_.]{1,64}$/', $field) === 1;
            if (! $validField || ! in_array($op, self::OPERATORS, true)) {
                continue;
            }
            $out[] = ['field' => $field, 'op' => $op, 'value' => self::str($r['value'] ?? null, 200)];
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

    private static function varName(mixed $value): ?string
    {
        $s = is_string($value) ? trim($value) : '';

        return preg_match('/^[A-Za-z][A-Za-z0-9_]{0,39}$/', $s) === 1 ? $s : null;
    }

    /**
     * @param  array{nodes: list<array<string, mixed>>}  $graph
     * @return array<string, array<string, mixed>>
     */
    private static function index(array $graph): array
    {
        $out = [];
        foreach ($graph['nodes'] as $node) {
            $out[$node['id']] = $node;
        }

        return $out;
    }
}
