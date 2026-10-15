<?php

declare(strict_types=1);

namespace App\Domain\Chatbots\Engine;

/**
 * The publish gate. Every problem says what is wrong, on which block (and which branch), and how
 * to fix it in one line, so the builder can jump to it and highlight it. Level "error" blocks
 * publishing; "warning" is shown but does not.
 *
 * Dependencies (fields, templates, API keys, teammates, segments, subflows, uploaded files) are
 * passed in, so a flow referencing a rejected template or a deleted field is caught here.
 */
final class FlowChecks
{
    /**
     * @param  array{kind?: string, fields?: array<string, true>, templates?: array<string, string>, secrets?: array<string, true>, members?: array<string, true>, segments?: array<string, true>, subflows?: array<string, bool>, media?: array<string, true>, stores?: bool, settings?: array<string, mixed>}  $deps
     * @return list<array{level: string, code: string, node_id: ?string, output_id: ?string, message: string, fix: string}>
     */
    public static function run(FlowGraph $graph, array $deps = []): array
    {
        $issues = [];
        $add = function (string $level, string $code, ?string $node, string $message, string $fix, ?string $output = null) use (&$issues): void {
            $issues[] = ['level' => $level, 'code' => $code, 'node_id' => $node, 'output_id' => $output, 'message' => $message, 'fix' => $fix];
        };
        $nodes = $graph->nodes();
        $subflow = ($deps['kind'] ?? 'bot') === 'subflow';
        $title = fn (string $id) => '“'.($nodes[$id]['title'] ?? $id).'”';

        $starts = array_values(array_filter($nodes, fn ($n) => $n['type'] === 'start'));
        if (count($starts) !== 1) {
            $add('error', 'start_count', null, count($starts) === 0 ? 'The flow has no Start block.' : 'The flow has more than one Start block.',
                count($starts) === 0 ? 'Add a Start block from the Control group: it decides what starts the flow.' : 'Delete the extra Start blocks; keep one.');

            return $issues;
        }
        $start = $starts[0];
        $sc = $start['config'];
        $hasTrigger = $sc['any_message'] || $sc['greeting'] || $sc['keywords'] !== [] || $sc['regex'] !== null || $sc['ad_referral'] || $sc['qr_code'] !== null
            || $sc['campaign_buttons'] !== [] || $sc['tags_added'] !== [] || $sc['fields_changed'] !== [] || $sc['webhook'];
        if (! $hasTrigger && ! $subflow) {
            $add('error', 'no_trigger', $start['id'], 'Nothing starts this flow.', 'Open the Start block and turn on “Any message”, add keywords, or pick another trigger.');
        }

        foreach ($nodes as $id => $node) {
            foreach ($node['outputs'] as $out) {
                if ($out['to'] === null) {
                    [$what, $fix] = match ($out['id']) {
                        'fallback' => ['the “No match” branch', 'Connect it to the block that should handle unrecognised answers, or in the block settings choose “Ask again, then connect to a person”.'],
                        'timeout' => ['the “No reply” branch', 'Connect it (for example to a reminder or a Handoff), or switch the no-reply timer off in the block settings.'],
                        'failed' => ['the “Not delivered” branch', 'Connect it to what should happen when WhatsApp cannot deliver the template, or switch it off in the block settings.'],
                        default => ["the “{$out['label']}” branch", 'Drag from its dot to the next block, or click + on it to add one.'],
                    };
                    $add('error', 'unconnected', $id, "{$title($id)}: {$what} goes nowhere.", $fix, $out['id']);
                }
            }
            foreach (self::config($node, $deps) as [$level, $code, $message, $fix]) {
                $add($level, $code, $id, "{$title($id)}: {$message}", $fix);
            }
        }

        // Reachability: no orphan blocks, and every block can still reach an exit.
        $edges = [];
        foreach ($nodes as $id => $node) {
            $edges[$id] = array_values(array_filter(array_map(fn ($o) => $o['to'], $node['outputs'])));
            if ($node['type'] === 'goto' && $node['config']['target'] !== null) {
                $edges[$id][] = $node['config']['target'];
            }
        }
        // "Ask again, then connect to a person" leads to the first Handoff block when answers are not understood.
        $firstHandoff = $graph->firstOfType('handoff')['id'] ?? null;
        if ($firstHandoff !== null) {
            foreach ($nodes as $id => $node) {
                if (in_array($node['type'], FlowGraph::INPUT, true) && ($node['config']['no_match'] ?? 'handoff') === 'handoff' && ! ($node['type'] === 'product' && $node['config']['mode'] === 'cards')) {
                    $edges[$id][] = $firstHandoff;
                }
            }
        }
        $reachable = self::walk([$start['id']], $edges);
        foreach ($nodes as $id => $node) {
            if (! isset($reachable[$id])) {
                $add('error', 'unreachable', $id, "{$title($id)} can never be reached.", 'Connect a branch to it, or delete it if it is not needed.');
            }
        }
        $reverse = [];
        foreach ($edges as $from => $tos) {
            foreach ($tos as $to) {
                $reverse[$to][] = $from;
            }
        }
        $exits = array_keys(array_filter($nodes, fn ($n) => in_array($n['type'], ['handoff', 'end'], true)));
        $canFinish = self::walk($exits, $reverse);
        foreach ($reachable as $id => $_) {
            if (! isset($canFinish[$id]) && ! in_array($nodes[$id]['type'], ['subflow'], true) && ! ($subflow && $nodes[$id]['type'] !== 'goto' && $nodes[$id]['outputs'] === [])) {
                $add('error', 'endless_loop', $id, "{$title($id)} is in a loop with no way out.", 'Connect one branch of the loop to a Talk to a person (Handoff) or End block.');
            }
        }
        if (! $subflow && ! array_filter($exits, fn ($e) => $nodes[$e]['type'] === 'handoff' && isset($reachable[$e]))) {
            $add('error', 'no_handoff', null, 'Customers can never reach a person.', 'Add a Talk to a person (Handoff) block and connect at least one branch to it, for example a “Talk to us” button.');
        }

        $reopen = ($deps['settings']['reopen_template'] ?? null) !== null;
        // The 24-hour window: what runs after a long wait (Delay, No reply) may be outside it.
        foreach ($nodes as $id => $node) {
            $waits = [];
            if ($node['type'] === 'delay') {
                $waits[] = [$node['config']['mode'] === 'until' ? 1440 : (int) $node['config']['minutes'], $node['outputs'][0]['to'] ?? null, 'a wait'];
            }
            if (($node['config']['timeout_minutes'] ?? null) !== null) {
                $waits[] = [(int) $node['config']['timeout_minutes'], $graph->target($id, 'timeout'), 'the no-reply timer'];
            }
            foreach ($waits as [$minutes, $next, $why]) {
                foreach (self::freeFormAfter($graph, $next) as $sendId) {
                    if ($minutes >= 1440 && $reopen) {
                        $add('warning', 'window_risk', $sendId, "{$title($sendId)} runs after {$why} of a day or more. If the 24-hour window has closed, your re-opening template is sent first and this step runs when the customer answers.", 'Use a Template block here if the message must arrive without waiting for a reply.');
                    } elseif ($minutes >= 1440) {
                        $add('error', 'needs_template', $sendId, "{$title($sendId)} runs after {$why} of a day or more, when WhatsApp only delivers approved templates.", 'Replace it with a Template block, or shorten the wait to under 24 hours.');
                    } elseif ($minutes >= 120) {
                        $add('warning', 'window_risk', $sendId, "{$title($sendId)} runs after {$why} of ".intdiv($minutes, 60).' hours. If the customer has not written for 24 hours by then, it is not delivered.', 'Use a Template block here to be safe.');
                    }
                }
            }
        }
        // Flows started by a data change or an incoming webhook may start outside the window.
        if (($sc['tags_added'] !== [] || $sc['fields_changed'] !== [] || $sc['webhook']) && ($first = self::freeFormAfter($graph, $start['outputs'][0]['to'] ?? null)) !== []) {
            $add('warning', 'window_risk', $first[0], "{$title($first[0])} is the first message of a flow that can start without the customer writing first.", 'Start these flows with a Template block, so the message is delivered even outside the 24-hour window.');
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $deps
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function config(array $node, array $deps): array
    {
        $c = $node['config'];
        $p = [];
        $err = function (string $code, string $message, string $fix) use (&$p): void {
            $p[] = ['error', $code, $message, $fix];
        };
        $warn = function (string $code, string $message, string $fix) use (&$p): void {
            $p[] = ['warning', $code, $message, $fix];
        };
        $fields = $deps['fields'] ?? null;
        $target = function (?string $t, string $what) use ($err, $fields): void {
            if ($t === null) {
                $err('missing_target', "choose where {$what} is saved.", 'Pick a contact field or name a variable in the block settings.');
            } elseif (str_starts_with($t, 'field:') && is_array($fields) && ! isset($fields[substr($t, 6)])) {
                $err('deleted_field', 'saves to the custom field “'.substr($t, 6).'”, which no longer exists.', 'Choose another field, or create it again in Contacts → Segments, tags & fields.');
            }
        };
        $needsText = in_array($node['type'], ['message', 'buttons', 'list', 'question', 'product', 'url', 'form', 'ask_media', 'location', 'rating', 'note'], true);
        if ($needsText && trim((string) ($c['text'] ?? '')) === '') {
            $err('missing_text', $node['type'] === 'note' ? 'the note is empty.' : 'the message text is empty.', 'Type the message in the block settings.');
        }
        if (in_array($node['type'], FlowGraph::INPUT, true) && ($c['no_match'] ?? null) === 'off') {
            $warn('no_match_off', 'answers that match nothing get no reply.', 'If customers may type freely here, choose “Ask again, then connect to a person”.');
        }

        switch ($node['type']) {
            case 'buttons':
                if ($c['buttons'] === []) {
                    $err('no_buttons', 'has no buttons.', 'Add at least one button (up to 3).');
                }
                break;
            case 'list':
                $rows = array_sum(array_map(fn ($s) => count($s['rows']), $c['sections']));
                if ($rows === 0) {
                    $err('no_rows', 'the list has no rows.', 'Add at least one row (up to 10 in total).');
                }
                if (count($c['sections']) > 1 && array_filter($c['sections'], fn ($s) => $s['title'] === null)) {
                    $err('section_title', 'a list with several sections needs a title on each section.', 'Give every section a short title (up to 24 characters).');
                }
                break;
            case 'question':
                $target($c['save_to'], 'the answer');
                if ($c['validate'] === 'regex' && $c['pattern'] === null) {
                    $err('missing_pattern', 'checks the answer with a pattern, but none is set.', 'Enter the pattern (regular expression), e.g. ^[A-Z]{2}\d{4}$');
                }
                break;
            case 'ask_media':
                $target($c['save_to'], 'the file');
                break;
            case 'rating':
                $target($c['save_to'], 'the rating');
                break;
            case 'product':
                if ($c['items'] === []) {
                    $err('no_products', 'has no products.', 'Add products (name, photo link, price and product page).');
                }
                if ($c['mode'] !== 'list' && array_filter($c['items'], fn ($i) => $i['image_url'] === null)) {
                    $err('product_photo', 'every product needs a photo link to be shown as '.($c['mode'] === 'cards' ? 'cards' : 'pictures').'.', 'Paste an https link to each product photo, or show the products as a list.');
                }
                if ($c['mode'] === 'cards' && array_filter($c['items'], fn ($i) => $i['link'] === null)) {
                    $err('product_link', 'every card needs the link to its product page.', 'Paste the https link of each product page (where the customer orders).');
                }
                break;
            case 'media':
                if ($c['media_id'] === null && $c['link'] === null) {
                    $err('no_file', 'no file chosen.', 'Upload the file, or paste a public https link to it.');
                } elseif ($c['media_id'] !== null && isset($deps['media']) && ! isset($deps['media'][$c['media_id']])) {
                    $err('missing_file', 'the uploaded file is no longer available.', 'Upload it again.');
                }
                break;
            case 'template':
                if ($c['name'] === null || $c['language'] === null) {
                    $err('no_template', 'no template chosen.', 'Choose an approved template.');
                } elseif (isset($deps['templates'])) {
                    $status = $deps['templates'][$c['name'].'|'.$c['language']] ?? null;
                    if ($status === null) {
                        $err('missing_template', "uses the template “{$c['name']}” ({$c['language']}), which does not exist on this number's WhatsApp account.", 'Choose another template, or sync templates in Templates.');
                    } elseif ($status !== 'APPROVED') {
                        $err('template_status', "uses the template “{$c['name']}”, which is ".strtolower(str_replace('_', ' ', $status)).'.', 'Choose an approved template.');
                    }
                }
                break;
            case 'url':
                if ($c['url'] === null || NodeConfig::link($c['url']) === null) {
                    $err('bad_url', 'the button link must start with https://.', 'Paste the full https link, e.g. https://example.com/book');
                }
                break;
            case 'form':
                if ($c['flow_id'] === null) {
                    $err('no_form', 'no WhatsApp form chosen.', 'Paste the Flow ID of a published form from WhatsApp Manager → Flows.');
                }
                if ($c['screen'] === null) {
                    $err('no_screen', 'the first screen of the form is not set.', 'Enter the ID of the form’s first screen (as written in the Flow JSON, e.g. WELCOME).');
                }
                break;
            case 'condition':
                if ($c['rules'] === []) {
                    $err('no_rules', 'has no rules.', 'Add at least one rule.');
                }
                foreach ($c['rules'] as $r) {
                    if (str_starts_with($r['field'], 'field:') && is_array($fields) && ! isset($fields[substr($r['field'], 6)])) {
                        $err('deleted_field', 'checks the custom field “'.substr($r['field'], 6).'”, which no longer exists.', 'Choose another field in this rule.');
                    }
                    if (($r['field'] === 'segment' || in_array($r['op'], ['in_segment', 'not_in_segment'], true)) && $r['value'] !== null && ! str_contains($r['value'], '{{')
                        && isset($deps['segments']) && ! isset($deps['segments'][mb_strtolower($r['value'])])) {
                        $err('deleted_segment', 'checks a segment that no longer exists.', 'Choose another segment in this rule.');
                    }
                }
                break;
            case 'setfield':
                $target($c['target'], 'the value');
                break;
            case 'calculate':
                $target($c['target'], 'the result');
                if ($c['expression'] === null || ! Expression::valid($c['expression'])) {
                    $err('bad_expression', 'the formula cannot be calculated.', 'Use numbers, {{variables}}, + - * / ( ) and functions such as round(), days_between(); e.g. {{var.nights}} * 450');
                }
                break;
            case 'delay':
                if ($c['mode'] === 'until' && $c['until'] === null) {
                    $err('no_until', 'waits until a date, but no date is set.', 'Enter the date or a variable holding it, e.g. {{var.appointment}}.');
                }
                break;
            case 'tag':
                if ($c['add'] === [] && $c['remove'] === [] && $c['assign'] === 'none') {
                    $err('empty_tag', 'does nothing yet.', 'Add or remove a tag, or assign the conversation.');
                }
                break;
            case 'hours':
                if ($c['mode'] === 'custom' && $c['days'] === []) {
                    $err('no_days', 'no open days chosen.', 'Tick the days you are open.');
                }
                break;
            case 'http':
                if ($c['url'] === null || preg_match('#^https://[^\s/{}]+(/\S*)?$#i', (string) preg_replace('/\{\{[^}]+\}\}/', 'x', $c['url'])) !== 1) {
                    $err('bad_url', 'the address must be a full https URL.', 'Enter it like https://api.example.com/orders/{{var.order}} (placeholders only after the domain).');
                }
                foreach ($c['headers'] as $h) {
                    if (preg_match_all('/\{\{\s*secret\.([A-Za-z0-9_]+)\s*\}\}/', $h['value'], $m) && isset($deps['secrets'])) {
                        foreach ($m[1] as $name) {
                            if (! isset($deps['secrets'][$name])) {
                                $err('missing_secret', "uses the API key “{$name}”, which is not saved.", 'Add it under Chatbots → API keys, or fix the name.');
                            }
                        }
                    }
                }
                break;
            case 'order':
                if (isset($deps['stores']) && ! $deps['stores']) {
                    $err('no_store', 'needs a connected Shopify or WooCommerce store.', 'Connect your store in Integrations.');
                }
                break;
            case 'sheets':
                if ($c['webapp_url'] === null || ! str_starts_with($c['webapp_url'], 'https://script.google.com/')) {
                    $err('bad_sheet', 'needs the web app link of your Google Sheet script.', 'Follow the steps in the block settings and paste the link that starts with https://script.google.com/.');
                }
                if ($c['columns'] === []) {
                    $err('no_columns', 'has no columns.', 'Add the columns to write, e.g. Name = {{contact.name}}.');
                }
                break;
            case 'goto':
                if ($c['target'] === null) {
                    $err('no_goto', 'no block chosen to go to.', 'Pick the block to continue at.');
                }
                break;
            case 'subflow':
                if ($c['flow_id'] === null) {
                    $err('no_subflow', 'no subflow chosen.', 'Pick a published subflow.');
                } elseif (isset($deps['subflows']) && ! ($deps['subflows'][$c['flow_id']] ?? false)) {
                    $err('missing_subflow', 'the chosen subflow does not exist or is not published.', 'Publish that subflow, or pick another one.');
                }
                break;
            case 'tag_assign_member':
            case 'handoff':
            case 'end':
                break;
        }
        if (in_array($node['type'], ['tag', 'handoff', 'end'], true) && ($c['assign'] ?? null) === 'member') {
            if ($c['member_id'] === null) {
                $err('no_member', 'no teammate chosen.', 'Pick the teammate, or assign to the least busy teammate.');
            } elseif (isset($deps['members']) && ! isset($deps['members'][$c['member_id']])) {
                $err('missing_member', 'assigns to a teammate who is no longer in the workspace.', 'Pick another teammate.');
            }
        }

        return $p;
    }

    /**
     * @param  list<string>  $from
     * @param  array<string, list<string>>  $edges
     * @return array<string, true>
     */
    private static function walk(array $from, array $edges): array
    {
        $seen = array_fill_keys($from, true);
        $queue = $from;
        while ($queue !== []) {
            $id = array_shift($queue);
            foreach ($edges[$id] ?? [] as $to) {
                if (! isset($seen[$to])) {
                    $seen[$to] = true;
                    $queue[] = $to;
                }
            }
        }

        return $seen;
    }

    /**
     * Free-form sending blocks that run from $start before the customer answers anything.
     *
     * @return list<string>
     */
    private static function freeFormAfter(FlowGraph $graph, ?string $start): array
    {
        $found = [];
        $seen = [];
        $queue = [$start];
        while ($queue !== []) {
            $id = array_shift($queue);
            $node = $graph->node($id);
            if ($node === null || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $sendsText = in_array($node['type'], FlowGraph::FREE_FORM, true)
                || (in_array($node['type'], ['handoff', 'end'], true) && trim((string) ($node['config']['text'] ?? '')) !== '');
            if ($sendsText) {
                $found[] = (string) $id;
            }
            // An answer from the customer opens a new window: stop looking past input blocks.
            if (in_array($node['type'], FlowGraph::INPUT, true) || in_array($node['type'], ['handoff', 'end', 'template'], true)) {
                continue;
            }
            foreach ($node['outputs'] as $out) {
                $queue[] = $out['to'];
            }
            if ($node['type'] === 'goto') {
                $queue[] = $node['config']['target'];
            }
        }

        return $found;
    }
}
