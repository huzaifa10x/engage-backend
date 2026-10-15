<?php

declare(strict_types=1);

namespace App\Domain\Chatbots\Engine;

use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Runs a flow: enter a node, do its action, pick one output, advance — until a node needs the
 * customer's answer, a Delay pauses the session, or an exit (Handoff / End) is reached.
 *
 * Stateless: the session lives in BotRuntime::$state and is saved by the caller. Every message
 * goes through BotRuntime::send(), which (live) re-checks under lock that the bot still owns the
 * conversation; when it does not, BotPreempted stops the run and nothing more is sent.
 */
final class FlowEngine
{
    /** Guard against a loop of non-waiting blocks (Condition ↔ Set field …). */
    private const MAX_STEPS = 40;

    /** Settings are FlowSettings::normalize() output; nodes are FlowGraph nodes. */
    public function __construct(private readonly HttpBlock $http) {}

    /**
     * A new session: begin after the Start block.
     *
     * @param  array<string, mixed>  $settings
     */
    public function begin(FlowGraph $graph, array $settings, BotRuntime $rt): void
    {
        $start = $graph->start();
        if ($start === null) {
            $this->finish($rt, 'stopped', 'no_start');

            return;
        }
        $rt->event($start['id'], 'enter');
        $this->guard(fn () => $this->follow($graph, $settings, $rt, $start['id'], 'next'), $rt);
    }

    /**
     * The customer wrote (or tapped) something while the session is running.
     *
     * @param  array<string, mixed>  $settings
     */
    public function inbound(FlowGraph $graph, array $settings, BotRuntime $rt, BotInput $input): void
    {
        $this->guard(function () use ($graph, $settings, $rt, $input): void {
            $rt->state['vars']['last_input'] = $input->text !== null ? mb_substr($input->text, 0, 1000) : null;
            $words = $input->normalized();

            // Words that always work, whatever the bot just asked.
            if ($words !== '' && in_array($words, $settings['handoff_keywords'], true) && ($handoff = $this->firstOfType($graph, 'handoff')) !== null) {
                $rt->event((string) $rt->state['current_node'], 'click', 'handoff_keyword');
                $this->run($graph, $settings, $rt, $handoff);

                return;
            }
            if ($words !== '' && in_array($words, $settings['menu_keywords'], true) && ($start = $graph->start()) !== null) {
                $rt->state['retry_count'] = 0;
                $this->follow($graph, $settings, $rt, $start['id'], 'next');

                return;
            }

            // A tap on a button of an earlier menu still counts, and routes from that menu.
            $node = $graph->node($rt->state['current_node']);
            if ($input->payload !== null && preg_match('/^bot:([A-Za-z0-9_-]+):/', $input->payload, $m) === 1 && $m[1] !== ($node['id'] ?? null)) {
                $tapped = $graph->node($m[1]);
                if ($tapped !== null && in_array($tapped['type'], FlowGraph::INPUT, true)) {
                    $node = $tapped;
                    $rt->state['current_node'] = $tapped['id'];
                    $rt->state['awaiting_input'] = true;
                }
            }

            if ($node === null || ! $rt->state['awaiting_input']) {
                return; // nothing was asked: the customer is just chatting while the bot works
            }

            $output = $this->answer($node, $input, $rt);
            if ($output === null) {
                $rt->state['retry_count']++;
                if ($rt->state['retry_count'] > $settings['retry_limit']) {
                    $rt->state['retry_count'] = 0;
                    $rt->event($node['id'], 'click', 'fallback');
                    $this->follow($graph, $settings, $rt, $node['id'], 'fallback');

                    return;
                }
                $error = $node['type'] === 'question' && ($node['config']['error_text'] ?? null) !== null
                    ? $node['config']['error_text']
                    : $settings['invalid_text'];
                $this->sendText($rt, Placeholders::fill($error, $rt));
                $this->prompt($node, $rt);

                return;
            }

            $rt->state['retry_count'] = 0;
            $rt->event($node['id'], 'click', $output);
            $this->follow($graph, $settings, $rt, $node['id'], $output);
        }, $rt);
    }

    /**
     * A Delay is over: continue after it.
     *
     * @param  array<string, mixed>  $settings
     */
    public function resume(FlowGraph $graph, array $settings, BotRuntime $rt): void
    {
        $node = $graph->node($rt->state['current_node']);
        $rt->state['status'] = 'active';
        $rt->state['resume_at'] = null;
        if ($node === null) {
            $this->finish($rt, 'stopped', 'node_missing');

            return;
        }
        $this->guard(fn () => $this->follow($graph, $settings, $rt, $node['id'], 'next'), $rt);
    }

    /** The customer came back soon after leaving: ask the same question again. */
    public function reprompt(FlowGraph $graph, BotRuntime $rt): void
    {
        $node = $graph->node($rt->state['current_node']);
        if ($node === null || ! in_array($node['type'], FlowGraph::INPUT, true)) {
            $this->finish($rt, 'stopped', 'node_missing');

            return;
        }
        $rt->state['status'] = 'active';
        $rt->state['awaiting_input'] = true;
        $rt->state['retry_count'] = 0;
        $this->guard(fn () => $this->prompt($node, $rt), $rt);
    }

    /**
     * Step inactivity: one nudge, same session.
     *
     * @param  array<string, mixed>  $settings
     */
    public function remind(array $settings, BotRuntime $rt): void
    {
        $this->guard(fn () => $this->sendText($rt, Placeholders::fill($settings['reminder_text'], $rt)), $rt);
    }

    // ── Running ──────────────────────────────────────────────────────────────────────────────
    /**
     * @param  array<string, mixed>  $settings
     */
    private function follow(FlowGraph $graph, array $settings, BotRuntime $rt, string $nodeId, string $output): void
    {
        $target = $graph->target($nodeId, $output);
        if ($target === null) {
            // Only possible for a flow published before a node was removed: end quietly.
            $this->finish($rt, 'completed', 'dead_end');

            return;
        }
        $this->run($graph, $settings, $rt, $target);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function run(FlowGraph $graph, array $settings, BotRuntime $rt, string $nodeId): void
    {
        for ($i = 0; $i < self::MAX_STEPS; $i++) {
            $node = $graph->node($nodeId);
            if ($node === null) {
                $this->finish($rt, 'completed', 'dead_end');

                return;
            }
            $rt->state['current_node'] = $node['id'];
            $rt->state['awaiting_input'] = false;
            $rt->event($node['id'], 'enter');

            $output = $this->execute($node, $settings, $rt);
            if ($output === null) {
                return; // waiting for the customer, paused, or finished
            }
            $next = $graph->target($node['id'], $output);
            if ($next === null) {
                $this->finish($rt, 'completed', 'dead_end');

                return;
            }
            $nodeId = $next;
        }
        $rt->event($nodeId, 'error', 'too_many_steps');
        $this->finish($rt, 'stopped', 'loop');
    }

    /**
     * Performs one block. Returns the output to follow, or null when the run stops here.
     *
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $settings
     */
    private function execute(array $node, array $settings, BotRuntime $rt): ?string
    {
        $c = $node['config'];

        switch ($node['type']) {
            case 'start':
                return 'next';

            case 'message':
                $this->sendText($rt, Placeholders::fill($c['text'], $rt));

                return 'next';

            case 'media':
                $rt->send($this->media($c, $rt));

                return 'next';

            case 'template':
                $rt->send(['type' => 'template', 'template' => [
                    'name' => $c['name'],
                    'language' => $c['language'],
                    'variables' => [
                        'header' => array_map(fn ($v) => Placeholders::fill($v, $rt), $c['header']),
                        'body' => array_map(fn ($v) => Placeholders::fill($v, $rt), $c['body']),
                    ],
                ]]);

                return 'next';

            case 'buttons':
            case 'list':
            case 'question':
            case 'product':
                $rt->state['awaiting_input'] = true;
                $rt->state['retry_count'] = 0;
                $this->prompt($node, $rt);

                return null;

            case 'condition':
                $result = (new ConditionEvaluator)->passes($c['match'], $c['rules'], $rt);
                $rt->note('Condition “'.$node['title'].'” → '.($result ? 'Yes' : 'No'));

                return $result ? 'yes' : 'no';

            case 'setfield':
                $value = Placeholders::fill($c['value'], $rt);
                $this->save($rt, (string) $c['target'], $value === '' ? null : $value, $node['id']);

                return 'next';

            case 'http':
                return $this->http->call($c, $rt, $node['id']) ? 'success' : 'error';

            case 'delay':
                $rt->state['status'] = 'waiting';
                $rt->state['resume_at'] = now()->addMinutes((int) $c['minutes'])->toIso8601String();
                $rt->note("Waiting {$c['minutes']} minute(s) before continuing.");

                return null;

            case 'tag':
                $this->tags($c, $rt, $node['id']);
                if ($c['assign'] !== 'none') {
                    $who = $rt->assign($c['assign'], $c['member_id'], 'The chatbot assigned a conversation to you');
                    $rt->note($who !== null ? "Assigned to {$who}." : 'Nobody could be assigned (no teammate can reply on this number).');
                }

                return 'next';

            case 'handoff':
                $text = ! $rt->businessOpen() && ($c['offline_text'] ?? null) !== null ? $c['offline_text'] : $c['text'];
                if ($text !== null) {
                    $this->sendText($rt, Placeholders::fill($text, $rt));
                }
                $who = $rt->assign($c['assign'] === 'none' ? 'route' : $c['assign'], $c['member_id'], 'A customer asked to talk to a person');
                $rt->note('Handed off to a person'.($who !== null ? " ({$who})" : '').'. The bot is paused for this conversation.');
                $rt->event($node['id'], 'handoff');
                $this->finish($rt, 'handed_off', 'handoff');

                return null;

            case 'end':
                if ($c['text'] !== null) {
                    $this->sendText($rt, Placeholders::fill($c['text'], $rt));
                }
                if ($c['action'] === 'assign') {
                    $who = $rt->assign($c['assign'] === 'none' ? 'auto' : $c['assign'], $c['member_id'], 'The chatbot finished and assigned a conversation to you');
                    $rt->note($who !== null ? "Assigned to {$who}." : 'Nobody could be assigned.');
                }
                $rt->event($node['id'], 'complete');
                $this->finish($rt, 'completed', 'end');
                if ($c['action'] === 'close') {
                    $rt->closeConversation();
                    $rt->note('Conversation closed.');
                }

                return null;
        }

        return null;
    }

    /**
     * Sends the question / menu of an input block.
     *
     * @param  array<string, mixed>  $node
     */
    private function prompt(array $node, BotRuntime $rt): void
    {
        $c = $node['config'];
        $text = Placeholders::fill($c['text'], $rt);
        $id = fn (string $choice) => "bot:{$node['id']}:{$choice}";
        $extras = fn (array $content) => array_filter($content + [
            'header' => ($c['header'] ?? null) !== null ? ['type' => 'text', 'text' => Placeholders::fill($c['header'], $rt)] : null,
            'footer' => ($c['footer'] ?? null) !== null ? ['text' => Placeholders::fill($c['footer'], $rt)] : null,
        ]);

        switch ($node['type']) {
            case 'buttons':
                $rt->send(['type' => 'interactive', 'body' => $text, 'content' => $extras([
                    'type' => 'button',
                    'body' => ['text' => $text],
                    'action' => ['buttons' => array_map(fn ($b) => ['type' => 'reply', 'reply' => ['id' => $id($b['id']), 'title' => $b['title']]], $c['buttons'])],
                ])]);
                break;

            case 'list':
                $rt->send(['type' => 'interactive', 'body' => $text, 'content' => $extras([
                    'type' => 'list',
                    'body' => ['text' => $text],
                    'action' => [
                        'button' => $c['button_label'],
                        'sections' => [array_filter([
                            'title' => $c['section_title'],
                            'rows' => array_map(fn ($r) => array_filter(['id' => $id($r['id']), 'title' => $r['title'], 'description' => $r['description'] ?? null]), $c['rows']),
                        ])],
                    ],
                ])]);
                break;

            case 'question':
                $this->sendText($rt, $text);
                break;

            case 'product':
                $this->products($node, $text, $rt);
                break;
        }
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function products(array $node, string $text, BotRuntime $rt): void
    {
        $c = $node['config'];
        $items = $c['items'];
        $label = fn (array $p) => trim($p['name'].($p['price'] !== null ? ' — '.$p['price'] : ''));

        if ($c['mode'] === 'list') {
            $rt->send(['type' => 'interactive', 'body' => $text, 'content' => [
                'type' => 'list',
                'body' => ['text' => $text],
                'action' => ['button' => $c['button_label'], 'sections' => [['rows' => array_map(fn ($p) => array_filter([
                    'id' => "bot:{$node['id']}:{$p['id']}",
                    'title' => mb_substr($p['name'], 0, 24),
                    'description' => mb_substr(trim(implode(' · ', array_filter([$p['price'], $p['description']]))), 0, 72) ?: null,
                ]), $items)]]],
            ]]);

            return;
        }

        $this->sendText($rt, $text);
        $map = [];
        foreach (array_values($items) as $i => $p) {
            $map[(string) ($i + 1)] = $p['id'];
            if ($c['mode'] === 'images') {
                $rt->send(['type' => 'image', 'body' => ($i + 1).'. '.$label($p).($p['description'] !== null ? "\n".$p['description'] : ''), 'content' => ['link' => $p['image_url']]]);
            } else {
                $body = '*'.$p['name'].'*'.($p['price'] !== null ? "\n".$p['price'] : '').($p['description'] !== null ? "\n".$p['description'] : '').($p['link'] !== null ? "\n".$p['link'] : '');
                $rt->send(['type' => 'interactive', 'body' => $body, 'content' => [
                    'type' => 'button',
                    'header' => ['type' => 'image', 'image' => ['link' => $p['image_url']]],
                    'body' => ['text' => $body],
                    'action' => ['buttons' => [['type' => 'reply', 'reply' => ['id' => "bot:{$node['id']}:{$p['id']}", 'title' => $c['order_label']]]]],
                ]]);
            }
        }
        // "2" means the 2nd product shown in this turn, not catalog item 2.
        $rt->state['vars']['_pick'] = ['node' => $node['id'], 'map' => $map];
        if ($c['mode'] === 'images') {
            $this->sendText($rt, 'Reply with the number (1–'.count($items).') of the one you want.');
        }
    }

    /**
     * Matches the customer's answer to an output of the block that asked. Null = not understood.
     *
     * @param  array<string, mixed>  $node
     */
    private function answer(array $node, BotInput $input, BotRuntime $rt): ?string
    {
        $c = $node['config'];
        $prefix = "bot:{$node['id']}:";
        $payload = $input->payload !== null && str_starts_with($input->payload, $prefix) ? substr($input->payload, strlen($prefix)) : null;
        $words = $input->normalized();

        switch ($node['type']) {
            case 'buttons':
            case 'list':
                $choices = $node['type'] === 'buttons' ? $c['buttons'] : $c['rows'];
                foreach ($choices as $i => $choice) {
                    if ($payload === $choice['id'] || ($words !== '' && $words === $this->plain($choice['title'])) || $input->number() === $i + 1) {
                        $rt->state['vars'][$node['id']] = $choice['title'];

                        return $choice['id'];
                    }
                }

                return null;

            case 'product':
                $byId = [];
                foreach ($c['items'] as $p) {
                    $byId[$p['id']] = $p;
                }
                $picked = $payload !== null ? ($byId[$payload] ?? null) : null;
                if ($picked === null && $input->number() !== null) {
                    $map = ($rt->state['vars']['_pick']['node'] ?? null) === $node['id'] ? (array) $rt->state['vars']['_pick']['map'] : [];
                    $picked = $byId[$map[(string) $input->number()] ?? array_values($byId)[$input->number() - 1]['id'] ?? ''] ?? null;
                }
                if ($picked === null && $words !== '') {
                    foreach ($byId as $p) {
                        if ($this->plain($p['name']) === $words) {
                            $picked = $p;
                        }
                    }
                }
                if ($picked === null) {
                    return null;
                }
                $rt->state['vars'][$c['save_to']] = array_filter(['id' => $picked['id'], 'name' => $picked['name'], 'price' => $picked['price'], 'link' => $picked['link']]);
                $rt->note("Chosen product: {$picked['name']}");

                return 'next';

            case 'question':
                $value = $this->validate((string) $c['validate'], (string) $input->text);
                if ($value === null) {
                    return null;
                }
                try {
                    $this->save($rt, (string) $c['save_to'], $value, $node['id'], strict: true);
                } catch (ValidationException) {
                    return null; // the field's own rules said no: ask again
                }

                return 'next';
        }

        return null;
    }

    private function validate(string $rule, string $text): ?string
    {
        $t = trim($text);
        if ($t === '') {
            return null;
        }

        return match ($rule) {
            'number' => is_numeric(str_replace([',', ' '], '', $t)) ? str_replace([',', ' '], '', $t) : null,
            'email' => filter_var($t, FILTER_VALIDATE_EMAIL) !== false ? mb_strtolower($t) : null,
            'phone' => preg_match('/^\+?[\d\s().-]{6,20}$/', $t) === 1 ? $t : null,
            'date' => ($ts = strtotime($t)) !== false ? date('Y-m-d', $ts) : null,
            default => mb_substr($t, 0, 1000),
        };
    }

    /** Writes an answer or computed value to a contact field, the name/email, or a session variable. */
    private function save(BotRuntime $rt, string $target, ?string $value, string $nodeId, bool $strict = false): void
    {
        if (str_starts_with($target, 'var:')) {
            $rt->state['vars'][substr($target, 4)] = $value;
            $rt->note('Saved “'.Placeholders::text($value).'” as '.$target);

            return;
        }
        try {
            if (str_starts_with($target, 'field:')) {
                $rt->updateContact(['fields' => [substr($target, 6) => $value]]);
            } elseif ($target === 'name' || $target === 'email') {
                $rt->updateContact([$target => $value]);
            }
            $rt->note('Saved “'.Placeholders::text($value).'” to '.$target);
        } catch (ValidationException $e) {
            $rt->event($nodeId, 'error', mb_substr((string) collect($e->errors())->flatten()->first(), 0, 120));
            $rt->note('Could not save to '.$target.': '.collect($e->errors())->flatten()->first());
            if ($strict) {
                throw $e;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $c
     */
    private function tags(array $c, BotRuntime $rt, string $nodeId): void
    {
        if ($c['add'] === [] && $c['remove'] === []) {
            return;
        }
        $remove = array_map('mb_strtolower', $c['remove']);
        $tags = array_values(array_filter($rt->contact['tags'], fn ($t) => ! in_array(mb_strtolower($t), $remove, true)));
        foreach ($c['add'] as $tag) {
            if (! in_array(mb_strtolower($tag), array_map('mb_strtolower', $tags), true)) {
                $tags[] = $tag;
            }
        }
        try {
            $rt->updateContact(['tags' => $tags]);
            $rt->note('Tags: '.($tags === [] ? 'none' : implode(', ', $tags)));
        } catch (Throwable $e) {
            $rt->event($nodeId, 'error', mb_substr($e->getMessage(), 0, 120));
            $rt->note('Tags could not be changed: '.$e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $c
     * @return array<string, mixed>
     */
    private function media(array $c, BotRuntime $rt): array
    {
        if ($c['kind'] === 'location') {
            return ['type' => 'location', 'body' => $c['name'] ?? $c['address'], 'content' => array_filter([
                'latitude' => $c['latitude'], 'longitude' => $c['longitude'], 'name' => $c['name'], 'address' => $c['address'],
            ], fn ($v) => $v !== null)];
        }

        return ['type' => $c['kind'], 'body' => Placeholders::fill($c['caption'], $rt) ?: null, 'content' => array_filter([
            'link' => $c['link'], 'filename' => $c['kind'] === 'document' ? $c['filename'] : null,
        ])];
    }

    private function sendText(BotRuntime $rt, string $text): void
    {
        if (trim($text) !== '') {
            $rt->send(['type' => 'text', 'body' => mb_substr($text, 0, 4096), 'content' => ['preview_url' => true]]);
        }
    }

    private function finish(BotRuntime $rt, string $status, string $reason): void
    {
        $rt->state['status'] = $status;
        $rt->state['awaiting_input'] = false;
        $rt->state['ended_reason'] = $reason;
        $rt->state['ended_node'] = $rt->state['current_node'];
    }

    /** Runs a step; a takeover stops it silently, a send that WhatsApp refuses ends the session. */
    private function guard(callable $step, BotRuntime $rt): void
    {
        try {
            $step();
        } catch (BotPreempted) {
            $rt->state['preempted'] = true;
        } catch (BotSendFailed $e) {
            $rt->event((string) $rt->state['current_node'], 'error', $e->reason);
            $rt->note('Stopped: '.$e->getMessage());
            $this->finish($rt, 'stopped', $e->reason);
        }
    }

    private function firstOfType(FlowGraph $graph, string $type): ?string
    {
        foreach ($graph->nodes() as $id => $node) {
            if ($node['type'] === $type) {
                return $id;
            }
        }

        return null;
    }

    /** Button titles compared without emoji, punctuation and case: "🧁 Our Cakes" = "our cakes". */
    private function plain(string $title): string
    {
        return (new BotInput($title))->normalized();
    }
}
