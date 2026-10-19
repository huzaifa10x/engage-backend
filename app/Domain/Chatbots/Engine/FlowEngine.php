<?php

declare(strict_types=1);

namespace App\Domain\Chatbots\Engine;

use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * Runs a flow: enter a block, do its action, pick one output, advance — until a block needs the
 * customer's answer, a Wait pauses the session, or an exit (Handoff / End) is reached.
 *
 * Stateless: the session lives in BotRuntime::$state and is saved by the caller. Every message
 * goes through BotRuntime::send(), which (live) re-checks under lock that the bot still owns the
 * conversation; when it does not, BotPreempted stops the run and nothing more is sent.
 *
 * Session variables starting with "_" are the engine's own: _lang (language), _flow and _stack
 * (the subflow being run and where to return), _history (questions asked, for "back"), _pick
 * (numbers of the products shown), _resume (the branch to follow after a wait), _delivery (a
 * template whose delivery is awaited), _reopen (the step to run again once the customer answers
 * the re-opening template).
 */
final class FlowEngine
{
    /** Guard against a loop of non-waiting blocks (Condition ↔ Set field …). */
    private const MAX_STEPS = 60;

    private const MAX_DEPTH = 5;

    /** How long a template with a "Not delivered" branch waits for WhatsApp's delivery report. */
    private const DELIVERY_WAIT_MINUTES = 3;

    public function __construct(private readonly HttpBlock $http) {}

    /**
     * A new session: choose the language, then begin after the Start block.
     *
     * @param  array<string, mixed>  $settings
     */
    public function begin(FlowGraph $graph, array $settings, BotRuntime $rt, ?BotInput $first = null): void
    {
        $run = new FlowRun($graph, $settings, $rt);
        $rt->state['vars']['_lang'] = $this->language($run, $first);
        $start = $graph->start();
        if ($start === null) {
            $this->finish($rt, 'stopped', 'no_start');

            return;
        }
        $rt->event($start['id'], 'enter');
        $this->guard($run, fn () => $this->follow($run, $start['id'], 'next'));
    }

    /**
     * The customer wrote (or tapped) something while the session is running.
     *
     * @param  array<string, mixed>  $settings
     */
    public function inbound(FlowGraph $graph, array $settings, BotRuntime $rt, BotInput $input): void
    {
        $run = new FlowRun($graph, $settings, $rt);
        if (! $this->enterCurrentGraph($run)) {
            return;
        }
        $this->guard($run, function () use ($run, $rt, $settings, $input): void {
            $rt->state['vars']['last_input'] = $input->text !== null ? mb_substr($input->text, 0, 1000) : null;
            $words = $input->normalized();

            // The window had closed and a template re-opened it: run the step that could not be sent.
            if (isset($rt->state['vars']['_reopen'])) {
                $node = (string) $rt->state['vars']['_reopen'];
                unset($rt->state['vars']['_reopen'], $rt->state['vars']['_reopened']);
                $this->run($run, $node);

                return;
            }

            // Words that always work, whatever the bot just asked.
            if ($words !== '' && $input->payload === null) {
                if (in_array($words, $settings['handoff_keywords'], true)) {
                    $rt->event((string) $rt->state['current_node'], 'click', 'handoff_keyword');
                    $this->escalate($run, 'asked for a person');

                    return;
                }
                if (in_array($words, $settings['menu_keywords'], true) && ($start = $run->main->start()) !== null) {
                    $this->toMain($run);
                    $rt->state['retry_count'] = 0;
                    $rt->state['vars']['_history'] = [];
                    $this->follow($run, $start['id'], 'next');

                    return;
                }
                if (in_array($words, $settings['back_keywords'], true)) {
                    $this->back($run);

                    return;
                }
            }

            // A tap on a button of an earlier menu still counts, and routes from that menu.
            $node = $run->graph->node($rt->state['current_node']);
            if ($input->payload !== null && preg_match('/^bot:([A-Za-z0-9_-]+):/', $input->payload, $m) === 1 && $m[1] !== ($node['id'] ?? null)) {
                $tapped = $run->graph->node($m[1]);
                if ($tapped !== null && in_array($tapped['type'], FlowGraph::INPUT, true)) {
                    $node = $tapped;
                    $rt->state['current_node'] = $tapped['id'];
                    $rt->state['awaiting_input'] = true;
                    $rt->state['retry_count'] = 0;
                }
            }

            if ($node === null || ! $rt->state['awaiting_input']) {
                return; // nothing was asked: the customer is just chatting while the bot works
            }
            $node = $this->localize($run, $node);

            // A voice note, sticker or emoji where the block reads text or taps: say so, do not count it.
            if ($input->isNonText() && ! $this->accepts($node, $input)) {
                $this->sendText($rt, Placeholders::fill($settings['non_text_text'], $rt));

                return;
            }

            $output = $this->answer($run, $node, $input);
            if ($output === null) {
                $this->notUnderstood($run, $node);

                return;
            }

            $rt->state['retry_count'] = 0;
            $rt->state['timeout_at'] = null;
            $rt->event($node['id'], 'click', $output);
            $this->follow($run, $node['id'], $output);
        });
    }

    /**
     * A Wait is over (or a template's delivery report arrived / did not arrive in time).
     *
     * @param  array<string, mixed>  $settings
     */
    public function resume(FlowGraph $graph, array $settings, BotRuntime $rt, ?string $output = null): void
    {
        $run = new FlowRun($graph, $settings, $rt);
        $rt->state['status'] = 'active';
        $rt->state['resume_at'] = null;
        $output ??= (string) ($rt->state['vars']['_resume'] ?? 'next');
        unset($rt->state['vars']['_resume'], $rt->state['vars']['_delivery']);
        if (! $this->enterCurrentGraph($run)) {
            return;
        }
        $node = $run->graph->node($rt->state['current_node']);
        if ($node === null) {
            $this->finish($rt, 'stopped', 'node_missing');

            return;
        }
        if ($output === 'failed') {
            $rt->event($node['id'], 'error', 'not_delivered');
        }
        $this->guard($run, fn () => $this->follow($run, $node['id'], $output));
    }

    /**
     * The "No reply" timer of the current step ran out.
     *
     * @param  array<string, mixed>  $settings
     */
    public function timeout(FlowGraph $graph, array $settings, BotRuntime $rt): void
    {
        $run = new FlowRun($graph, $settings, $rt);
        $rt->state['timeout_at'] = null;
        if (! $this->enterCurrentGraph($run)) {
            return;
        }
        $node = $run->graph->node($rt->state['current_node']);
        if ($node === null || ! $rt->state['awaiting_input'] || ! $run->graph->hasOutput($node['id'], 'timeout')) {
            return;
        }
        $rt->state['retry_count'] = 0;
        $rt->event($node['id'], 'click', 'timeout');
        $this->guard($run, fn () => $this->follow($run, $node['id'], 'timeout'));
    }

    /**
     * Step inactivity: one nudge, same session.
     *
     * @param  array<string, mixed>  $settings
     */
    public function remind(array $settings, BotRuntime $rt, ?FlowGraph $graph = null): void
    {
        $run = new FlowRun($graph ?? FlowGraph::from(null), $settings, $rt);
        $this->guard($run, fn () => $this->sendText($rt, Placeholders::fill($settings['reminder_text'], $rt)));
    }

    // ── Running ──────────────────────────────────────────────────────────────────────────────

    private function follow(FlowRun $run, string $nodeId, string $output): void
    {
        $target = $run->graph->target($nodeId, $output);
        if ($target === null) {
            $this->deadEnd($run);

            return;
        }
        $this->run($run, $target);
    }

    private function run(FlowRun $run, string $nodeId): void
    {
        $rt = $run->rt;
        for ($i = 0; $i < self::MAX_STEPS; $i++) {
            $node = $run->graph->node($nodeId);
            if ($node === null) {
                $this->deadEnd($run);

                return;
            }
            $rt->state['current_node'] = $node['id'];
            $rt->state['awaiting_input'] = false;
            $rt->state['timeout_at'] = null;
            $rt->event($node['id'], 'enter');

            $output = $this->execute($run, $this->localize($run, $node));
            if ($output === null) {
                return; // waiting for the customer, paused, or finished
            }
            if ($output === '__goto') {
                $nodeId = (string) $node['config']['target'];

                continue;
            }
            if ($output === '__subflow') {
                $start = $run->graph->start();
                $next = $start !== null ? $run->graph->target($start['id'], 'next') : null;
                if ($next === null) {
                    $next = $this->leaveSubflow($run);
                    if ($next === null) {
                        return;
                    }
                }
                $nodeId = $next;

                continue;
            }
            if ($output === '__return') {
                $next = $this->leaveSubflow($run);
                if ($next === null) {
                    return;
                }
                $nodeId = $next;

                continue;
            }
            $next = $run->graph->target($node['id'], $output);
            if ($next === null) {
                $next = $this->deadEnd($run);
                if ($next === null) {
                    return;
                }
            }
            $nodeId = $next;
        }
        $rt->event($nodeId, 'error', 'too_many_steps');
        $rt->note('Stopped: the flow went round in a loop without waiting for the customer.');
        $this->finish($rt, 'stopped', 'loop');
    }

    /**
     * A branch that leads nowhere (only possible for a flow published before a block was removed),
     * or the end of a subflow: inside a subflow, return to the flow that called it.
     *
     * @return ?string the block to continue at, or null when the session ended
     */
    private function deadEnd(FlowRun $run): ?string
    {
        if (($run->rt->state['vars']['_stack'] ?? []) !== []) {
            return $this->leaveSubflow($run);
        }
        $this->finish($run->rt, 'completed', 'dead_end');

        return null;
    }

    /**
     * Performs one block. Returns the output to follow, null when the run stops here, or one of
     * __goto / __subflow / __return.
     *
     * @param  array<string, mixed>  $node
     */
    private function execute(FlowRun $run, array $node): ?string
    {
        $rt = $run->rt;
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

            case 'url':
                $rt->send($this->interactive($c, $rt, [
                    'type' => 'cta_url',
                    'action' => ['name' => 'cta_url', 'parameters' => ['display_text' => $c['cta'], 'url' => Placeholders::fill($c['url'], $rt, escape: fn (string $v) => rawurlencode($v))]],
                ]));

                return 'next';

            case 'template':
                return $this->template($run, $node);

            case 'product':
                if ($c['mode'] === 'cards') {
                    $this->cards($node, $rt);

                    return 'next';
                }
                // List and pictures wait for a choice, like the blocks below.
                // no break
            case 'buttons':
            case 'list':
            case 'question':
            case 'form':
            case 'ask_media':
            case 'location':
            case 'rating':
                $rt->state['awaiting_input'] = true;
                $rt->state['retry_count'] = 0;
                $this->prompt($run, $node);

                return null;

            case 'condition':
                $result = (new ConditionEvaluator)->passes($c['match'], $c['rules'], $rt);
                $rt->note('Condition “'.$node['title'].'” → '.($result ? 'Yes' : 'No'));

                return $result ? 'yes' : 'no';

            case 'setfield':
                $value = Placeholders::fill($c['value'], $rt);
                $this->save($rt, (string) $c['target'], $value === '' ? null : $value, $node['id']);

                return 'next';

            case 'calculate':
                $this->calculate($node, $rt);

                return 'next';

            case 'delay':
                return $this->delay($node, $rt);

            case 'tag':
                $this->tags($c, $rt, $node['id']);
                if ($c['assign'] !== 'none') {
                    $who = $rt->assign($c['assign'], $c['member_id'], 'The chatbot assigned a conversation to you');
                    $rt->note($who !== null ? "Assigned to {$who}." : 'Nobody could be assigned (no teammate can reply on this number).');
                }

                return 'next';

                // Put after the customer agreed (a "Yes, subscribe" button, a keyword): recorded in the consent
                // ledger as an opt-in from the chatbot, and marketing messages are allowed again.
            case 'optin':
                $rt->optIn();
                $rt->note('The customer is opted in to messages, marketing included.');
                if ($c['text'] !== null) {
                    $this->sendText($rt, Placeholders::fill($c['text'], $rt));
                }

                return 'next';

            case 'optout':
                if ($c['text'] !== null) {
                    $this->sendText($rt, Placeholders::fill($c['text'], $rt));
                }
                $rt->optOut($c['scope']);
                $rt->note($c['scope'] === 'all' ? 'The customer is opted out of all messages.' : 'The customer is opted out of marketing messages.');
                if ($c['scope'] === 'all') {
                    $rt->event($node['id'], 'complete');
                    $this->finish($rt, 'completed', 'opted_out');

                    return null;
                }

                return 'next';

            case 'hours':
                $open = $c['mode'] === 'business' ? $rt->businessOpen() : $this->customOpen($c, $rt->timezone());
                $rt->note('Business hours: '.($open ? 'open' : 'closed').' now.');

                return $open ? 'open' : 'closed';

            case 'split':
                $branch = random_int(1, 100) <= (int) $c['percent_a'] ? 'a' : 'b';
                $rt->event($node['id'], 'click', $branch);
                $rt->note('A/B split → '.strtoupper($branch));

                return $branch;

            case 'http':
                return $this->http->call($c, $rt, $node['id']) ? 'success' : 'error';

            case 'order':
                $number = trim(Placeholders::fill($c['order_number'], $rt), " #\t\n");
                $order = $number !== '' ? $rt->order($number) : null;
                $rt->state['vars'][$c['save_to']] = $order;
                $rt->note($order !== null ? "Order {$number}: ".Placeholders::text($order['status'] ?? '') : "Order “{$number}” was not found for this customer.");

                return $order !== null ? 'found' : 'not_found';

            case 'sheets':
                $this->http->sheet($c, $rt, $node['id']);

                return 'next';

            case 'goto':
                return $c['target'] !== null && $run->graph->node($c['target']) !== null ? '__goto' : null;

            case 'subflow':
                return $this->enterSubflow($run, $node);

            case 'note':
                $rt->internalNote(Placeholders::fill($c['text'], $rt));

                return 'next';

            case 'handoff':
                $this->handoff($run, $node);

                return null;

            case 'end':
                if ($c['text'] !== null) {
                    $this->sendText($rt, Placeholders::fill($c['text'], $rt));
                }
                // The end of a subflow returns to the flow that called it.
                if (($rt->state['vars']['_stack'] ?? []) !== []) {
                    return '__return';
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

    /** @param array<string, mixed> $node */
    private function template(FlowRun $run, array $node): ?string
    {
        $rt = $run->rt;
        $c = $node['config'];
        try {
            $key = $rt->send(['type' => 'template', 'template' => [
                'name' => $c['name'],
                'language' => $c['language'],
                'variables' => [
                    'header' => array_map(fn ($v) => Placeholders::fill($v, $rt), $c['header']),
                    'body' => array_map(fn ($v) => Placeholders::fill($v, $rt), $c['body']),
                ],
            ]]);
        } catch (BotSendFailed $e) {
            if (! $c['on_failure']) {
                throw $e;
            }
            $rt->event($node['id'], 'error', $e->reason);
            $rt->note('Template not sent: '.$e->getMessage().' (Not delivered branch)');

            return 'failed';
        }
        if (! $c['on_failure'] || $key === null) {
            return 'next';
        }
        // Wait briefly for WhatsApp's delivery report; no report in time counts as sent.
        $rt->state['status'] = 'waiting';
        $rt->state['resume_at'] = now()->addMinutes(self::DELIVERY_WAIT_MINUTES)->toIso8601String();
        $rt->state['vars']['_resume'] = 'next';
        $rt->state['vars']['_delivery'] = $key;

        return null;
    }

    /** @param array<string, mixed> $node */
    private function delay(array $node, BotRuntime $rt): ?string
    {
        $c = $node['config'];
        if ($c['mode'] === 'until') {
            $raw = Placeholders::fill($c['until'], $rt);
            $ts = $raw !== '' ? strtotime($raw.(preg_match('/\d{1,2}:\d{2}/', $raw) === 1 ? '' : ' 09:00')) : false;
            if ($ts === false) {
                $rt->event($node['id'], 'error', 'bad_date');
                $rt->note("Wait skipped: “{$raw}” is not a date.");

                return 'next';
            }
            $at = Carbon::parse(date('Y-m-d H:i:s', $ts), $rt->timezone())->addMinutes((int) $c['offset_minutes']);
            if ($at->isPast()) {
                $rt->note('Wait skipped: '.$at->toDayDateTimeString().' has already passed.');

                return 'next';
            }
        } else {
            $at = now()->addMinutes((int) $c['minutes']);
        }
        $rt->state['status'] = 'waiting';
        $rt->state['resume_at'] = $at->toIso8601String();
        $rt->state['vars']['_resume'] = 'next';
        $rt->note('Waiting until '.$at->copy()->setTimezone($rt->timezone())->toDayDateTimeString().' before continuing.');

        return null;
    }

    /** @param array<string, mixed> $node */
    private function calculate(array $node, BotRuntime $rt): void
    {
        $c = $node['config'];
        try {
            $value = Expression::evaluate((string) $c['expression'], fn (string $path) => Placeholders::raw($path, $rt), $rt->timezone());
        } catch (InvalidArgumentException $e) {
            $rt->event($node['id'], 'error', 'calculation');
            $rt->note('Could not calculate “'.$node['title'].'”: '.$e->getMessage());

            return;
        }
        $text = is_float($value) ? Placeholders::text(round($value, (int) $c['decimals'])) : $value;
        $this->save($rt, (string) $c['target'], $text, $node['id']);
    }

    /** @param array<string, mixed> $node */
    private function enterSubflow(FlowRun $run, array $node): string
    {
        $rt = $run->rt;
        $stack = (array) ($rt->state['vars']['_stack'] ?? []);
        $graph = $node['config']['flow_id'] !== null ? $rt->graph($node['config']['flow_id']) : null;
        if ($graph === null || count($stack) >= self::MAX_DEPTH) {
            $rt->event($node['id'], 'error', $graph === null ? 'subflow_missing' : 'subflow_depth');
            $rt->note($graph === null ? 'The subflow is not published: skipped.' : 'Subflows call each other too deeply: skipped.');

            return 'next';
        }
        $stack[] = ['flow' => $rt->state['vars']['_flow'] ?? null, 'node' => $node['id']];
        $rt->state['vars']['_stack'] = $stack;
        $rt->state['vars']['_flow'] = $node['config']['flow_id'];
        $rt->state['vars']['_history'] = [];
        $run->graph = $graph;
        $rt->note('Subflow “'.$node['title'].'” starts.');

        return '__subflow';
    }

    /** @return ?string the block after the Subflow block in the calling flow, or null when the session ended */
    private function leaveSubflow(FlowRun $run): ?string
    {
        $rt = $run->rt;
        $stack = (array) ($rt->state['vars']['_stack'] ?? []);
        $frame = array_pop($stack);
        if (! is_array($frame)) {
            $this->finish($rt, 'completed', 'dead_end');

            return null;
        }
        $rt->state['vars']['_stack'] = $stack;
        $rt->state['vars']['_flow'] = $frame['flow'];
        $rt->state['vars']['_history'] = [];
        $graph = $frame['flow'] === null ? $run->main : $rt->graph((string) $frame['flow']);
        if ($graph === null) {
            $this->finish($rt, 'stopped', 'subflow_missing');

            return null;
        }
        $run->graph = $graph;
        $rt->note('Subflow finished: back to the main flow.');
        $next = $graph->target((string) $frame['node'], 'next');
        if ($next === null) {
            return $this->deadEnd($run);
        }

        return $next;
    }

    /** Sessions inside a subflow continue in that subflow's graph. */
    private function enterCurrentGraph(FlowRun $run): bool
    {
        $flow = $run->rt->state['vars']['_flow'] ?? null;
        if ($flow === null) {
            return true;
        }
        $graph = $run->rt->graph((string) $flow);
        if ($graph === null) {
            $this->finish($run->rt, 'stopped', 'subflow_missing');

            return false;
        }
        $run->graph = $graph;

        return true;
    }

    private function toMain(FlowRun $run): void
    {
        unset($run->rt->state['vars']['_flow'], $run->rt->state['vars']['_stack']);
        $run->graph = $run->main;
    }

    /** "back": ask the previous question again. */
    private function back(FlowRun $run): void
    {
        $rt = $run->rt;
        $history = array_values((array) ($rt->state['vars']['_history'] ?? []));
        $current = $rt->state['current_node'];
        if ($history !== [] && end($history) === $current) {
            array_pop($history);
        }
        $previous = array_pop($history);
        $rt->state['vars']['_history'] = $history;
        $rt->state['retry_count'] = 0;
        if (is_string($previous) && $run->graph->node($previous) !== null) {
            $rt->event((string) $current, 'click', 'back');
            $this->run($run, $previous);

            return;
        }
        $node = $run->graph->node($current);
        if ($node !== null && in_array($node['type'], FlowGraph::INPUT, true)) {
            $this->prompt($run, $this->localize($run, $node));
        }
    }

    /**
     * An answer that matches nothing: ask again, then (per the block's "No match" setting) connect
     * to a person, follow the "No match" branch, or stay silent.
     *
     * @param  array<string, mixed>  $node
     */
    private function notUnderstood(FlowRun $run, array $node): void
    {
        $rt = $run->rt;
        $c = $node['config'];
        $mode = $c['no_match'] ?? 'handoff';
        if ($mode === 'off') {
            return;
        }
        $rt->state['retry_count']++;
        $rt->event($node['id'], 'error', 'no_match');
        $limit = $c['retries'] ?? $run->settings['retry_limit'];
        if ($rt->state['retry_count'] > $limit) {
            $rt->state['retry_count'] = 0;
            if ($mode === 'branch' && $run->graph->hasOutput($node['id'], 'fallback')) {
                $rt->event($node['id'], 'click', 'fallback');
                $this->follow($run, $node['id'], 'fallback');

                return;
            }
            $this->escalate($run, 'did not understand '.($limit + 1).' answer(s) in “'.$node['title'].'”');

            return;
        }
        $error = ($c['error_text'] ?? null) !== null ? $c['error_text'] : $run->settings['invalid_text'];
        $this->sendText($rt, Placeholders::fill($error, $rt));
        $this->prompt($run, $node);
    }

    /** Connects the customer to a person: the flow's first Handoff block, or a plain handoff. */
    private function escalate(FlowRun $run, string $why): void
    {
        $rt = $run->rt;
        foreach ([$run->graph, $run->main] as $graph) {
            $handoff = $graph->firstOfType('handoff');
            if ($handoff !== null) {
                $run->graph = $graph;
                $rt->note("Handing off: the customer {$why}.");
                $this->run($run, $handoff['id']);

                return;
            }
        }
        $this->sendText($rt, Placeholders::fill($run->settings['escalate_text'], $rt));
        $this->handoff($run, ['id' => (string) ($rt->state['current_node'] ?? 'start'), 'type' => 'handoff', 'title' => 'Talk to a person', 'config' => [
            'text' => null, 'offline_text' => null, 'note' => true, 'assign' => 'none', 'member_id' => null,
        ]], $why);
    }

    /** @param array<string, mixed> $node */
    private function handoff(FlowRun $run, array $node, ?string $why = null): void
    {
        $rt = $run->rt;
        $c = $node['config'];
        $text = ! $rt->businessOpen() && ($c['offline_text'] ?? null) !== null ? $c['offline_text'] : $c['text'];
        if ($text !== null) {
            $this->sendText($rt, Placeholders::fill($text, $rt));
        }
        $who = $rt->assign($c['assign'] === 'none' ? 'route' : $c['assign'], $c['member_id'], 'A customer asked to talk to a person');
        if ($c['note'] ?? true) {
            $rt->internalNote($this->summary($run, $who, $why));
        }
        $rt->note('Handed off to a person'.($who !== null ? " ({$who})" : '').'. The bot is paused for this conversation.');
        $rt->event($node['id'], 'handoff');
        $this->finish($rt, 'handed_off', 'handoff');
    }

    /** The note agents read on handoff: which bot, why, and what the customer answered. */
    private function summary(FlowRun $run, ?string $who, ?string $why): string
    {
        $rt = $run->rt;
        // A menu's choice is kept under the block's id: show the block's name instead.
        $label = fn (string $key) => $run->graph->node($key)['title'] ?? $run->main->node($key)['title'] ?? str_replace('_', ' ', $key);
        $lines = ['🤖 '.$rt->botName().' handed this conversation to the team'.($who !== null ? " ({$who})" : '').'.'];
        if ($why !== null) {
            $lines[] = 'Reason: the customer '.$why.'.';
        }
        $answers = [];
        foreach ((array) $rt->state['vars'] as $key => $value) {
            if (is_string($key) && ! str_starts_with($key, '_') && ! in_array($key, ['last_input', 'http_status', 'started_from_ad'], true) && $value !== null && $value !== '' && count($answers) < 15) {
                $answers[] = '• '.$label($key).': '.mb_substr(Placeholders::text($value), 0, 200);
            }
        }
        if ($answers !== []) {
            $lines[] = '';
            $lines[] = 'Answers collected:';
            array_push($lines, ...$answers);
        }

        return implode("\n", $lines);
    }

    /**
     * Sends the question / menu of a block that waits for an answer.
     *
     * @param  array<string, mixed>  $node
     */
    private function prompt(FlowRun $run, array $node): void
    {
        $rt = $run->rt;
        $c = $node['config'];
        $text = Placeholders::fill($c['text'] ?? null, $rt);
        $id = fn (string $choice) => "bot:{$node['id']}:{$choice}";

        $history = (array) ($rt->state['vars']['_history'] ?? []);
        if (end($history) !== $node['id']) {
            $history[] = $node['id'];
            $rt->state['vars']['_history'] = array_slice($history, -20);
        }
        if (($c['timeout_minutes'] ?? null) !== null) {
            $rt->state['timeout_at'] = now()->addMinutes((int) $c['timeout_minutes'])->toIso8601String();
        }

        switch ($node['type']) {
            case 'buttons':
                $rt->send($this->interactive($c, $rt, [
                    'type' => 'button',
                    'action' => ['buttons' => array_map(fn ($b) => ['type' => 'reply', 'reply' => ['id' => $id($b['id']), 'title' => $b['title']]], $c['buttons'])],
                ]));
                break;

            case 'list':
                $rt->send($this->interactive($c, $rt, [
                    'type' => 'list',
                    'action' => [
                        'button' => $c['button_label'],
                        'sections' => array_values(array_filter(array_map(fn ($s) => array_filter([
                            'title' => $s['title'],
                            'rows' => array_map(fn ($r) => array_filter(['id' => $id($r['id']), 'title' => $r['title'], 'description' => $r['description'] ?? null]), $s['rows']),
                        ]), $c['sections']), fn ($s) => ($s['rows'] ?? []) !== [])),
                    ],
                ]));
                break;

            case 'rating':
                $rt->send($this->interactive($c, $rt, [
                    'type' => 'list',
                    'action' => ['button' => $c['button_label'], 'sections' => [['rows' => array_map(fn (int $n) => [
                        'id' => $id("r{$n}"), 'title' => str_repeat('⭐', $n), 'description' => [1 => 'Poor', 2 => 'Fair', 3 => 'Good', 4 => 'Very good', 5 => 'Excellent'][$n],
                    ], [5, 4, 3, 2, 1])]]],
                ]));
                break;

            case 'form':
                $rt->send($this->interactive($c, $rt, [
                    'type' => 'flow',
                    'action' => ['name' => 'flow', 'parameters' => array_filter([
                        'flow_message_version' => '3',
                        'flow_token' => 'bot:'.$node['id'],
                        'flow_id' => $c['flow_id'],
                        'flow_cta' => $c['cta'],
                        'mode' => $c['draft'] ? 'draft' : null,
                        'flow_action' => 'navigate',
                        'flow_action_payload' => $c['screen'] !== null ? ['screen' => $c['screen']] : null,
                    ])],
                ]));
                break;

            case 'location':
                $rt->send(['type' => 'interactive', 'body' => $text, 'content' => [
                    'type' => 'location_request_message',
                    'body' => ['text' => $text],
                    'action' => ['name' => 'send_location'],
                ]]);
                break;

            case 'ask_media':
                if ($c['optional']) {
                    $rt->send($this->interactive($c, $rt, [
                        'type' => 'button',
                        'action' => ['buttons' => [['type' => 'reply', 'reply' => ['id' => $id('skip'), 'title' => $c['skip_label']]]]],
                    ]));
                } else {
                    $this->sendText($rt, $text);
                }
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
     * An interactive message with the block's header / body / footer.
     *
     * @param  array<string, mixed>  $c
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function interactive(array $c, BotRuntime $rt, array $content): array
    {
        $text = Placeholders::fill($c['text'] ?? null, $rt);

        return ['type' => 'interactive', 'body' => $text, 'content' => array_filter($content + [
            'header' => ($c['header'] ?? null) !== null ? ['type' => 'text', 'text' => mb_substr(Placeholders::fill($c['header'], $rt), 0, 60)] : null,
            'body' => ['text' => mb_substr($text, 0, 1024)],
            'footer' => ($c['footer'] ?? null) !== null ? ['text' => mb_substr(Placeholders::fill($c['footer'], $rt), 0, 60)] : null,
        ])];
    }

    /**
     * Product cards: one card per product with its photo and a button to its page, then "See all".
     *
     * @param  array<string, mixed>  $node
     */
    private function cards(array $node, BotRuntime $rt): void
    {
        $c = $node['config'];
        if ($c['text'] !== null) {
            $this->sendText($rt, Placeholders::fill($c['text'], $rt));
        }
        foreach ($c['items'] as $p) {
            $body = '*'.$p['name'].'*'.($p['price'] !== null ? "\n".$p['price'] : '').($p['description'] !== null ? "\n".$p['description'] : '');
            $url = Placeholders::fill($p['link'], $rt);
            $rt->send(['type' => 'interactive', 'body' => $body, 'content' => array_filter([
                'type' => 'cta_url',
                'header' => $p['image_url'] !== null ? ['type' => 'image', 'image' => ['link' => $p['image_url']]] : null,
                'body' => ['text' => mb_substr($body, 0, 1024)],
                'action' => ['name' => 'cta_url', 'parameters' => ['display_text' => $c['order_label'], 'url' => $url]],
            ])]);
        }
        if ($c['all_url'] !== null) {
            $rt->send(['type' => 'interactive', 'body' => $c['all_label'] ?? 'See all', 'content' => [
                'type' => 'cta_url',
                'body' => ['text' => $c['all_label'] ?? 'See all'],
                'action' => ['name' => 'cta_url', 'parameters' => ['display_text' => mb_substr($c['all_label'] ?? 'See all', 0, 20), 'url' => Placeholders::fill($c['all_url'], $rt)]],
            ]]);
        }
    }

    /** @param array<string, mixed> $node */
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

        // Pictures: one photo per product, the customer replies with a number.
        $this->sendText($rt, $text);
        $map = [];
        foreach (array_values($items) as $i => $p) {
            $map[(string) ($i + 1)] = $p['id'];
            $rt->send(['type' => 'image', 'body' => ($i + 1).'. '.$label($p).($p['description'] !== null ? "\n".$p['description'] : ''), 'content' => ['link' => $p['image_url']]]);
        }
        // "2" means the 2nd product shown in this turn, not catalog item 2.
        $rt->state['vars']['_pick'] = ['node' => $node['id'], 'map' => $map];
        $this->sendText($rt, 'Reply with the number (1–'.count($items).') of the one you want.');
    }

    /**
     * Does this block take this kind of message as its answer?
     *
     * @param  array<string, mixed>  $node
     */
    private function accepts(array $node, BotInput $input): bool
    {
        return match ($node['type']) {
            'ask_media' => $input->isMedia(),
            'location' => $input->type === 'location',
            'form' => $input->formAnswers() !== null,
            'rating' => $input->type === 'text' && preg_match('/^[⭐★]+$/u', trim((string) $input->text)) === 1,
            default => false,
        };
    }

    /**
     * Matches the customer's answer to an output of the block that asked. Null = not understood.
     *
     * @param  array<string, mixed>  $node  localized
     */
    private function answer(FlowRun $run, array $node, BotInput $input): ?string
    {
        $rt = $run->rt;
        $c = $node['config'];
        $prefix = "bot:{$node['id']}:";
        $payload = $input->payload !== null && str_starts_with($input->payload, $prefix) ? substr($input->payload, strlen($prefix)) : null;
        $words = $input->normalized();

        switch ($node['type']) {
            case 'buttons':
            case 'list':
                $choices = $node['type'] === 'buttons' ? $c['buttons'] : array_merge(...array_map(fn ($s) => $s['rows'], $c['sections']));
                $titles = $this->allTitles($run, $node['id'], $node['type'] === 'buttons' ? 'buttons' : 'rows');
                foreach ($choices as $i => $choice) {
                    $match = $payload === $choice['id'] || $input->number() === $i + 1
                        || ($words !== '' && in_array($words, array_map($this->plain(...), [$choice['title'], ...($titles[$choice['id']] ?? [])]), true));
                    if ($match) {
                        $rt->state['vars'][$node['id']] = $choice['title'];

                        return $choice['id'];
                    }
                }

                return null;

            case 'rating':
                $n = $payload !== null && preg_match('/^r([1-5])$/', $payload, $m) === 1 ? (int) $m[1]
                    : ($input->number() ?? (preg_match('/^[⭐★]{1,5}$/u', trim((string) $input->text)) === 1 ? mb_strlen(trim((string) $input->text)) : null));
                if ($n === null || $n < 1 || $n > 5) {
                    return null;
                }
                $this->save($rt, (string) $c['save_to'], (string) $n, $node['id']);

                return 'next';

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
                $value = $this->validate($c, (string) $input->text, $rt);
                if ($value === null) {
                    return null;
                }
                try {
                    $this->save($rt, (string) $c['save_to'], $value, $node['id'], strict: true);
                } catch (ValidationException) {
                    return null; // the field's own rules said no: ask again
                }

                return 'next';

            case 'ask_media':
                if ($payload === 'skip' || ($c['optional'] && $words !== '' && in_array($words, [$this->plain((string) $c['skip_label']), 'skip', 'no'], true))) {
                    $rt->note('No file sent (skipped).');

                    return 'next';
                }
                $ok = $input->isMedia() && match ($c['accept']) {
                    'image' => $input->type === 'image',
                    'document' => in_array($input->type, ['document', 'image'], true),
                    default => true,
                };
                if (! $ok) {
                    return null;
                }
                $this->save($rt, (string) $c['save_to'], $this->fileReference($input), $node['id']);

                return 'next';

            case 'location':
                if ($input->type === 'location') {
                    $loc = array_filter([
                        'latitude' => $input->content['latitude'] ?? null, 'longitude' => $input->content['longitude'] ?? null,
                        'name' => $input->content['name'] ?? null, 'address' => $input->content['address'] ?? null,
                    ], fn ($v) => $v !== null);
                    if (isset($loc['latitude'], $loc['longitude'])) {
                        $loc['map'] = 'https://maps.google.com/?q='.$loc['latitude'].','.$loc['longitude'];
                    }
                } elseif ($words !== '' && mb_strlen(trim((string) $input->text)) >= 3) {
                    $loc = ['address' => mb_substr(trim((string) $input->text), 0, 300)]; // a typed area or address
                } else {
                    return null;
                }
                $rt->state['vars'][$c['save_to']] = $loc;
                $rt->note('Location saved as {{var.'.$c['save_to'].'}}');

                return 'next';

            case 'form':
                $answers = $input->formAnswers();
                if ($answers === null) {
                    return null;
                }
                unset($answers['flow_token']);
                $rt->state['vars'][$node['id']] = $answers;
                foreach ($c['fields'] as $map) {
                    $value = data_get($answers, $map['key']);
                    if ($value !== null) {
                        $this->save($rt, (string) NodeConfig::saveTarget($map['to']), Placeholders::text($value), $node['id']);
                    }
                }

                return 'next';
        }

        return null;
    }

    /** What is saved for a file the customer sent: a link agents can open in the inbox. */
    private function fileReference(BotInput $input): string
    {
        $name = $input->content['filename'] ?? null;

        return trim(($input->type ?? 'file').($name !== null ? " {$name}" : '').($input->messageId !== null ? " (message {$input->messageId})" : ''));
    }

    /**
     * Titles of a block's buttons / rows in every language, by id (an Arabic reply matches too).
     *
     * @return array<string, list<string>>
     */
    private function allTitles(FlowRun $run, string $nodeId, string $kind): array
    {
        $out = [];
        foreach ([$run->graph->node($nodeId)] as $raw) {
            $base = $raw['config'] ?? [];
            $items = $kind === 'buttons' ? ($base['buttons'] ?? []) : array_merge(...array_map(fn ($s) => $s['rows'], $base['sections'] ?? [['rows' => []]]));
            foreach ($items as $item) {
                $out[$item['id']][] = $item['title'];
            }
            foreach ((array) ($base['i18n'] ?? []) as $variant) {
                foreach ((array) ($variant[$kind] ?? []) as $id => $title) {
                    $out[$id][] = is_array($title) ? (string) ($title['title'] ?? '') : (string) $title;
                }
            }
        }

        return $out;
    }

    /**
     * The block in the session's language: translated fields replace the default ones.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function localize(FlowRun $run, array $node): array
    {
        $lang = $run->lang();
        $t = $node['config']['i18n'][$lang] ?? null;
        if ($lang === $run->defaultLang() || ! is_array($t)) {
            return $node;
        }
        $c = $node['config'];
        foreach (NodeConfig::TRANSLATABLE as $field) {
            if (isset($t[$field])) {
                $c[$field] = $t[$field];
            }
        }
        if (isset($t['buttons'], $c['buttons'])) {
            $c['buttons'] = array_map(fn ($b) => ['title' => $t['buttons'][$b['id']] ?? $b['title']] + $b, $c['buttons']);
        }
        if (isset($t['rows'], $c['sections'])) {
            $c['sections'] = array_map(fn ($s) => ['rows' => array_map(function ($r) use ($t) {
                $v = $t['rows'][$r['id']] ?? null;

                return is_array($v) ? ['title' => $v['title'], 'description' => $v['description'] ?? $r['description'] ?? null] + $r : ['title' => $v ?? $r['title']] + $r;
            }, $s['rows'])] + $s, $c['sections']);
        }
        if (isset($t['items'], $c['items'])) {
            $c['items'] = array_map(fn ($p) => ['name' => $t['items'][$p['id']] ?? $p['name']] + $p, $c['items']);
        }
        $node['config'] = $c;

        return $node;
    }

    /** The customer's language: the contact's language field, else the script they write in. */
    private function language(FlowRun $run, ?BotInput $first): string
    {
        $langs = (array) $run->settings['languages'];
        if (count($langs) < 2) {
            return $run->defaultLang();
        }
        $field = $run->settings['language_field'] !== null ? mb_strtolower(trim(Placeholders::text($run->rt->contact['fields'][$run->settings['language_field']] ?? null))) : '';
        if ($field !== '') {
            $aliases = ['arabic' => 'ar', 'العربية' => 'ar', 'english' => 'en', 'french' => 'fr', 'français' => 'fr', 'hindi' => 'hi', 'urdu' => 'ur'];
            $code = $aliases[$field] ?? substr($field, 0, 2);
            foreach ($langs as $l) {
                if (str_starts_with($l, $code)) {
                    return $l;
                }
            }
        }
        if ($first?->script() === 'ar' && in_array('ar', $langs, true)) {
            return 'ar';
        }

        return $run->defaultLang();
    }

    /** @param array<string, mixed> $c */
    private function validate(array $c, string $text, BotRuntime $rt): ?string
    {
        $t = trim($text);
        if ($t === '') {
            return null;
        }
        $date = function (string $t) use ($rt): ?Carbon {
            $t = strtr($t, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
            // 15/10/2026 and 15-10-2026 are day first (as written in the UAE).
            if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{2,4})$#', $t, $m) === 1) {
                $t = sprintf('%04d-%02d-%02d', strlen($m[3]) === 2 ? 2000 + (int) $m[3] : (int) $m[3], (int) $m[2], (int) $m[1]);
            }
            $ts = strtotime($t, now($rt->timezone())->getTimestamp());

            return $ts !== false ? Carbon::createFromTimestamp($ts, $rt->timezone())->startOfDay() : null;
        };
        $today = now($rt->timezone())->startOfDay();

        return match ($c['validate']) {
            'number' => is_numeric($n = str_replace([',', ' '], '', strtr($t, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']))) ? $n : null,
            'email' => filter_var($t, FILTER_VALIDATE_EMAIL) !== false ? mb_strtolower($t) : null,
            'phone' => preg_match('/^\+?[\d\s().-]{6,20}$/', $t) === 1 ? $t : null,
            'date' => ($d = $date($t)) !== null ? $d->toDateString() : null,
            'future_date' => ($d = $date($t)) !== null && $d->gt($today) ? $d->toDateString() : null,
            'workday' => ($d = $date($t)) !== null && $d->gt($today) && in_array(strtolower($d->format('D')), $rt->openDays(), true) ? $d->toDateString() : null,
            'text' => preg_match('/\p{L}/u', $t) === 1 ? mb_substr($t, 0, 1000) : null,
            'regex' => $c['pattern'] !== null && @preg_match('/'.str_replace('/', '\/', $c['pattern']).'/u', $t) === 1 ? mb_substr($t, 0, 1000) : null,
            default => mb_substr($t, 0, 1000),
        };
    }

    /** @param array<string, mixed> $c */
    private function customOpen(array $c, string $timezone): bool
    {
        $now = now($timezone);
        if (in_array($now->toDateString(), $c['closed_dates'], true) || ! in_array(strtolower($now->format('D')), $c['days'], true)) {
            return false;
        }
        $time = $now->format('H:i');

        return $c['from'] <= $c['to'] ? $time >= $c['from'] && $time < $c['to'] : $time >= $c['from'] || $time < $c['to'];
    }

    /** Writes an answer or computed value to a contact field, the name/email, or a session variable. */
    private function save(BotRuntime $rt, string $target, ?string $value, string $nodeId, bool $strict = false): void
    {
        if ($target === '') {
            return;
        }
        if (str_starts_with($target, 'var:')) {
            $rt->state['vars'][substr($target, 4)] = $value;
            $rt->note('Saved “'.Placeholders::text($value).'” as {{'.str_replace(':', '.', $target).'}}');

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

    /** @param array<string, mixed> $c */
    private function tags(array $c, BotRuntime $rt, string $nodeId): void
    {
        if ($c['add'] === [] && $c['remove'] === []) {
            return;
        }
        $remove = array_map('mb_strtolower', $c['remove']);
        $tags = array_values(array_filter($rt->contact['tags'], fn ($t) => ! in_array(mb_strtolower($t), $remove, true)));
        foreach ($c['add'] as $tag) {
            $tag = Placeholders::fill($tag, $rt);
            if ($tag !== '' && ! in_array(mb_strtolower($tag), array_map('mb_strtolower', $tags), true)) {
                $tags[] = mb_substr($tag, 0, 40);
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
        $caption = $c['kind'] !== 'audio' ? (Placeholders::fill($c['caption'], $rt) ?: null) : null;
        if ($c['media_id'] !== null) {
            return array_filter(['type' => $c['kind'], 'media_id' => $c['media_id'], 'body' => $caption, 'content' => array_filter([
                'filename' => $c['kind'] === 'document' ? ($c['filename'] ?? $c['media_name']) : null,
            ])]);
        }

        return ['type' => $c['kind'], 'body' => $caption, 'content' => array_filter([
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
        $rt->state['timeout_at'] = null;
        $rt->state['ended_reason'] = $reason;
        $rt->state['ended_node'] = $rt->state['current_node'];
    }

    /**
     * Runs a step. A takeover stops it silently. A message WhatsApp refuses because the 24-hour
     * window closed sends the flow's re-opening template instead (when set) and runs the step again
     * once the customer answers; any other refusal ends the session.
     */
    private function guard(FlowRun $run, callable $step): void
    {
        $rt = $run->rt;
        try {
            $step();
        } catch (BotPreempted) {
            $rt->state['preempted'] = true;
        } catch (BotSendFailed $e) {
            $node = (string) $rt->state['current_node'];
            $rt->event($node, 'error', $e->reason);
            $template = $run->settings['reopen_template'] ?? null;
            if ($e->reason === 'window_closed' && is_array($template) && ! isset($rt->state['vars']['_reopened'])) {
                try {
                    $rt->send(['type' => 'template', 'template' => [
                        'name' => $template['name'], 'language' => $template['language'],
                        'variables' => ['body' => array_map(fn ($v) => Placeholders::fill($v, $rt), $template['body'])],
                    ]]);
                    $rt->state['vars']['_reopen'] = $node;
                    $rt->state['vars']['_reopened'] = true;
                    $rt->state['status'] = 'active';
                    $rt->state['awaiting_input'] = true;
                    $rt->state['timeout_at'] = null;
                    $rt->note('The 24-hour window had closed: sent the re-opening template. The step runs again when the customer answers.');

                    return;
                } catch (BotPreempted) {
                    $rt->state['preempted'] = true;

                    return;
                } catch (BotSendFailed $again) {
                    $e = $again;
                }
            }
            $rt->note('Stopped: '.$e->getMessage());
            $this->finish($rt, 'stopped', $e->reason);
        }
    }

    /** Button titles compared without emoji, punctuation and case: "🧁 Our Cakes" = "our cakes". */
    private function plain(string $title): string
    {
        return (new BotInput($title))->normalized();
    }
}
