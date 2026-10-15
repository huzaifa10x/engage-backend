<?php

declare(strict_types=1);

namespace App\Domain\Chatbots\Engine;

/**
 * The node contract. Every node is the same shape — id, type, title, position, author config, and
 * an ordered list of named outputs that each point at another node (or nowhere yet):
 *
 *   {id, type, title, auto_title, x, y, config: {...}, outputs: [{id, label, to}]}
 *
 * The server derives the outputs from the config (buttons → one per button, plus "No match" only
 * when the author asked for it, plus "No reply" when the block has a timer), so a client can never
 * invent a branch the runtime does not know. normalize() cleans whatever the builder sends;
 * FlowChecks is the publish gate.
 */
final class FlowGraph
{
    /** The block library, by group (see the builder's palette). */
    public const GROUPS = [
        'send' => ['message', 'media', 'buttons', 'list', 'product', 'template', 'url', 'form'],
        'capture' => ['question', 'ask_media', 'location', 'rating'],
        'logic' => ['condition', 'setfield', 'calculate', 'delay', 'tag', 'optout', 'hours', 'split'],
        'connect' => ['http', 'order', 'sheets'],
        'control' => ['start', 'goto', 'subflow', 'note', 'handoff', 'end'],
    ];

    public const TYPES = [
        'start', 'message', 'media', 'buttons', 'list', 'product', 'template', 'url', 'form', 'question', 'ask_media', 'location', 'rating',
        'condition', 'setfield', 'calculate', 'delay', 'tag', 'optout', 'hours', 'split', 'http', 'order', 'sheets', 'goto', 'subflow', 'note', 'handoff', 'end',
    ];

    /** Blocks that stop and wait for the customer's answer. */
    public const INPUT = ['buttons', 'list', 'question', 'product', 'form', 'ask_media', 'location', 'rating'];

    public const TERMINAL = ['handoff', 'end', 'goto'];

    /** Blocks that send a normal (free-form) message, which needs the 24-hour window. */
    public const FREE_FORM = ['message', 'media', 'buttons', 'list', 'product', 'url', 'form', 'question', 'ask_media', 'location', 'rating', 'optout'];

    public const MAX_NODES = 200;

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
        $nodes = [];
        foreach (self::normalize($graph)['nodes'] as $node) {
            $nodes[$node['id']] = $node;
        }

        return new self($nodes);
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

    /** @return array<string, mixed>|null */
    public function firstOfType(string $type): ?array
    {
        foreach ($this->nodes as $node) {
            if ($node['type'] === $type) {
                return $node;
            }
        }

        return null;
    }

    public function hasOutput(string $nodeId, string $outputId): bool
    {
        foreach ((array) ($this->nodes[$nodeId]['outputs'] ?? []) as $out) {
            if ($out['id'] === $outputId) {
                return true;
            }
        }

        return false;
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
            $targets = [];
            foreach ((array) ($raw['outputs'] ?? []) as $out) {
                if (is_array($out) && isset($out['id'])) {
                    $to = $out['to'] ?? null;
                    $targets[(string) $out['id']] = is_string($to) && $to !== '' ? $to : null;
                }
            }
            $rawConfig = (array) ($raw['config'] ?? []);
            // Flows saved before "No match" became optional: a connected No-match branch stays a branch.
            if (in_array($type, self::INPUT, true) && ! isset($rawConfig['no_match'])) {
                $rawConfig['no_match'] = ($targets['fallback'] ?? null) !== null ? 'branch' : 'handoff';
            }
            $config = NodeConfig::clean($type, $rawConfig);
            $nodes[] = [
                'id' => $id,
                'type' => $type,
                'title' => NodeConfig::str($raw['title'] ?? null, 60) ?: self::defaultTitle($type),
                'auto_title' => (bool) ($raw['auto_title'] ?? false),
                'x' => (int) max(0, min(40000, (float) ($raw['x'] ?? 0))),
                'y' => (int) max(0, min(40000, (float) ($raw['y'] ?? 0))),
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
     * @param  array<string, mixed>  $config
     * @return list<array{id: string, label: string}>
     */
    public static function outputs(string $type, array $config): array
    {
        $choices = fn (array $items) => array_map(fn (array $b) => ['id' => $b['id'], 'label' => $b['title']], $items);
        $extra = [];
        if (in_array($type, self::INPUT, true) && ! ($type === 'product' && ($config['mode'] ?? null) === 'cards')) {
            if (($config['no_match'] ?? 'handoff') === 'branch') {
                $extra[] = ['id' => 'fallback', 'label' => 'No match'];
            }
            if (($config['timeout_minutes'] ?? null) !== null) {
                $extra[] = ['id' => 'timeout', 'label' => 'No reply'];
            }
        }

        return match ($type) {
            'start' => [['id' => 'next', 'label' => 'Start']],
            'message', 'media', 'url', 'note' => [['id' => 'next', 'label' => 'Next']],
            'template' => [['id' => 'next', 'label' => 'Sent'], ...(($config['on_failure'] ?? false) ? [['id' => 'failed', 'label' => 'Not delivered']] : [])],
            'setfield', 'tag', 'calculate', 'optout', 'sheets' => [['id' => 'next', 'label' => 'Done']],
            'delay' => [['id' => 'next', 'label' => 'After wait']],
            'subflow' => [['id' => 'next', 'label' => 'After subflow']],
            'buttons' => [...$choices($config['buttons'] ?? []), ...$extra],
            'list' => [...$choices(array_merge(...array_map(fn ($s) => $s['rows'], $config['sections'] ?? [['rows' => []]]))), ...$extra],
            'question', 'ask_media', 'location', 'rating' => [['id' => 'next', 'label' => 'Answered'], ...$extra],
            'form' => [['id' => 'next', 'label' => 'Submitted'], ...$extra],
            'product' => ($config['mode'] ?? 'cards') === 'cards' ? [['id' => 'next', 'label' => 'Next']] : [['id' => 'next', 'label' => 'Product chosen'], ...$extra],
            'condition' => [['id' => 'yes', 'label' => 'Yes'], ['id' => 'no', 'label' => 'No / else']],
            'hours' => [['id' => 'open', 'label' => 'Open'], ['id' => 'closed', 'label' => 'Closed']],
            'split' => [['id' => 'a', 'label' => 'A ('.($config['percent_a'] ?? 50).'%)'], ['id' => 'b', 'label' => 'B ('.(100 - (int) ($config['percent_a'] ?? 50)).'%)']],
            'http' => [['id' => 'success', 'label' => 'Success'], ['id' => 'error', 'label' => 'Error']],
            'order' => [['id' => 'found', 'label' => 'Found'], ['id' => 'not_found', 'label' => 'Not found']],
            default => [],
        };
    }

    public static function defaultTitle(string $type): string
    {
        return match ($type) {
            'start' => 'Conversation starts',
            'message' => 'Message',
            'media' => 'Media',
            'buttons' => 'Buttons',
            'list' => 'List menu',
            'product' => 'Products',
            'template' => 'Template',
            'url' => 'Link button',
            'form' => 'WhatsApp form',
            'question' => 'Question',
            'ask_media' => 'Ask for a photo or file',
            'location' => 'Ask for location',
            'rating' => 'Rating',
            'condition' => 'Condition',
            'setfield' => 'Set field',
            'calculate' => 'Calculate',
            'delay' => 'Wait',
            'tag' => 'Tag / assign',
            'optout' => 'Opt-out',
            'hours' => 'Business hours',
            'split' => 'A/B split',
            'http' => 'API call',
            'order' => 'Order lookup',
            'sheets' => 'Google Sheets row',
            'goto' => 'Go to',
            'subflow' => 'Subflow',
            'note' => 'Internal note',
            'handoff' => 'Talk to a person',
            'end' => 'End',
            default => ucfirst($type),
        };
    }
}
