<?php

declare(strict_types=1);

namespace App\Domain\Chatbots\Services;

use App\Domain\Chatbots\Engine\BotInput;
use App\Domain\Chatbots\Engine\FlowGraph;
use App\Domain\Chatbots\Models\ChatbotFlow;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\WhatsApp\Models\PhoneNumber;
use Illuminate\Support\Facades\DB;

/**
 * Start rules: one place to see what starts which flow on each number, in the order routing checks
 * them, and where two flows can match the same message.
 *
 * The rules are the triggers of each flow's Start step (edited in the builder); this service adds
 * the order and the checks. Routing (Chatbots::pick) goes by kind first, as before — campaign button,
 * QR code, Click-to-WhatsApp ad, keyword / pattern, then greeting, then the default flow — and,
 * within a kind, by the number's priority list. Overlaps are found by trying each trigger's own
 * example messages against the other flows with the same matcher pick() uses, so what the page says
 * and what routing does cannot disagree.
 */
final class StartRules
{
    public const LEVELS = ['campaign', 'qr', 'ad', 'keyword', 'greeting', 'default'];

    public function __construct(private readonly Chatbots $chatbots) {}

    /** @return list<array<string, mixed>> one entry per number: its flows in order, and the overlaps */
    public function overview(): array
    {
        $flows = ChatbotFlow::query()->where('kind', 'bot')->whereNull('archived_at')->whereNotNull('phone_number_id')
            ->orderBy('start_priority')->orderBy('published_at')->orderBy('id')->get();
        $numbers = PhoneNumber::query()->whereIn('id', $flows->pluck('phone_number_id')->unique())->get()->keyBy('id');
        $out = [];
        foreach ($flows->groupBy('phone_number_id') as $numberId => $group) {
            $rules = [];
            foreach ($group->values() as $i => $flow) {
                $config = $this->startConfig($flow);
                $rules[] = [
                    'flow_id' => $flow->id,
                    'name' => $flow->name,
                    'position' => $i + 1,
                    'priority' => $flow->start_priority,
                    'status' => $flow->is_active ? 'live' : ($flow->published_version !== null ? 'paused' : 'draft'),
                    'kinds' => $config !== null ? $this->kinds($config) : [],
                    'keywords' => $config['keywords'] ?? [],
                    'keyword_match' => $config['keyword_match'] ?? null,
                    'pattern' => $config['regex'] ?? null,
                    'qr_code' => $config['qr_code'] ?? null,
                    'campaign_buttons' => $config['campaign_buttons'] ?? [],
                    'after_hours' => (bool) ($config['after_hours'] ?? false),
                    'reentry' => $config['reentry'] ?? 'always',
                    'cooldown_hours' => $config['cooldown_hours'] ?? null,
                    'published' => $flow->published_version !== null,
                ];
            }
            $number = $numbers[$numberId] ?? null;
            $out[] = [
                'phone_number_id' => (string) $numberId,
                'display_phone_number' => $number?->display_phone_number,
                'verified_name' => $number?->verified_name,
                'rules' => $rules,
                'overlaps' => $this->overlaps($group->filter(fn (ChatbotFlow $f) => $f->is_active)->values()->all()),
            ];
        }

        return $out;
    }

    /**
     * Sets the order of the flows on one number (first = checked first within each kind).
     *
     * @param  list<string>  $flowIds
     */
    public function reorder(string $phoneNumberId, array $flowIds): void
    {
        DB::transaction(function () use ($phoneNumberId, $flowIds): void {
            $flows = ChatbotFlow::query()->where('phone_number_id', $phoneNumberId)->where('kind', 'bot')->lockForUpdate()->get()->keyBy('id');
            $position = 0;
            foreach ($flowIds as $id) {
                if (isset($flows[$id])) {
                    $flows[$id]->forceFill(['start_priority' => ++$position * 10])->save();
                    unset($flows[$id]);
                }
            }
            // Flows not in the list keep their order, after the listed ones.
            foreach ($flows->sortBy([['start_priority', 'asc'], ['published_at', 'asc'], ['id', 'asc']]) as $flow) {
                $flow->forceFill(['start_priority' => ++$position * 10])->save();
            }
        });
    }

    /**
     * Which flow would answer this message on this number now (business hours as they are now), and
     * why: pick() itself, on an unsaved conversation, so nothing is written and nothing is sent.
     *
     * @return array{flow: ?array{id: string, name: string}, reason: ?string, text: string}
     */
    public function test(string $phoneNumberId, string $text, bool $button, bool $fromAd, bool $firstMessage): array
    {
        $conversation = new Conversation;
        $conversation->forceFill(['phone_number_id' => $phoneNumberId, 'auto_reply_enabled' => false]);
        $input = $button ? new BotInput($text, $text, $fromAd, 'button') : new BotInput($text, null, $fromAd);
        $picked = $this->chatbots->pick($conversation, new Contact, $input, null, $firstMessage);
        if ($picked === null) {
            return ['flow' => null, 'reason' => null, 'text' => 'No flow starts: the message waits for your team in the inbox.'];
        }
        [$flow, , $why] = $picked;
        $config = $this->startConfig($flow) ?? [];
        $kind = $why === 'specific' ? $this->matchedKind($config, $input) : $why;

        return [
            'flow' => ['id' => $flow->id, 'name' => $flow->name],
            'reason' => $kind,
            'text' => '“'.$flow->name.'” starts: '.match ($kind) {
                'campaign' => 'the message is a reply to its campaign button.',
                'qr' => 'the message contains its QR code text.',
                'ad' => 'the message came from a Click-to-WhatsApp ad.',
                'keyword' => 'the message matches its keywords.',
                'greeting' => 'it greets customers writing for the first time.',
                'after_hours' => 'it answers outside business hours.',
                'auto_reply' => 'it is the auto reply for this number.',
                default => 'it answers any message on this number.',
            },
        ];
    }

    /**
     * Pairs of live flows on one number that can both match the same message, with an example and
     * which one routing picks.
     *
     * @param  list<ChatbotFlow>  $flows  in routing order
     * @return list<array<string, mixed>>
     */
    public function overlaps(array $flows): array
    {
        $out = [];
        $configs = [];
        foreach ($flows as $f) {
            $configs[$f->id] = $this->startConfig($f);
        }
        foreach ($flows as $i => $a) {
            foreach ($flows as $j => $b) {
                if ($j <= $i || $configs[$a->id] === null || $configs[$b->id] === null) {
                    continue;
                }
                foreach ([[$a, $b], [$b, $a]] as [$x, $y]) {
                    foreach ($this->samples($configs[$x->id]) as [$sample, $button, $fromAd]) {
                        $input = $button ? new BotInput($sample, $sample, false, 'button') : new BotInput($sample, null, $fromAd);
                        $kx = $this->matchedKind($configs[$x->id], $input);
                        $ky = $this->matchedKind($configs[$y->id], $input);
                        if ($kx === null || $ky === null) {
                            continue;
                        }
                        // Routing: the earlier kind wins; the same kind goes by the priority list.
                        $rank = fn (string $k) => (int) array_search($k, self::LEVELS, true);
                        $winner = $rank($kx) !== $rank($ky) ? ($rank($kx) < $rank($ky) ? $x : $y) : ($i < $j ? $a : $b);
                        // One entry per pair and kind of message (a typed keyword, a button reply, an ad).
                        $key = $a->id.'|'.$b->id.'|'.($fromAd ? 'ad' : ($button ? 'button' : 'text'));
                        $what = $fromAd ? 'A message from a Click-to-WhatsApp ad' : "“{$sample}”";
                        $out[$key] ??= [
                            'flows' => [['id' => $a->id, 'name' => $a->name], ['id' => $b->id, 'name' => $b->name]],
                            'example' => $fromAd ? '(Click-to-WhatsApp ad)' : $sample,
                            'winner' => ['id' => $winner->id, 'name' => $winner->name],
                            'same_kind' => $kx === $ky,
                            'text' => "{$what} starts both “{$a->name}” and “{$b->name}”. “{$winner->name}” answers"
                                .($kx === $ky ? ' because it is higher in the list.' : ' because its kind of trigger is checked first.'),
                        ];
                    }
                }
            }
        }

        return array_values($out);
    }

    /** @return ?array<string, mixed> the Start block's settings of the published version (or the draft, for an unpublished flow) */
    private function startConfig(ChatbotFlow $flow): ?array
    {
        $graph = $flow->published_version !== null ? ($this->chatbots->version($flow->id, $flow->published_version)[0] ?? null) : FlowGraph::from($flow->draft);

        return $graph?->start()['config'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $c
     * @return list<string>
     */
    private function kinds(array $c): array
    {
        return array_values(array_filter([
            $c['campaign_buttons'] !== [] ? 'campaign' : null,
            $c['qr_code'] !== null ? 'qr' : null,
            $c['ad_referral'] && ! $c['any_message'] ? 'ad' : null,
            $c['keywords'] !== [] || $c['regex'] !== null ? 'keyword' : null,
            $c['greeting'] ? 'greeting' : null,
            $c['any_message'] ? 'default' : null,
            $c['after_hours'] ? 'after_hours' : null,
        ]));
    }

    /** @param array<string, mixed> $c */
    private function matchedKind(array $c, BotInput $input): ?string
    {
        foreach (['campaign', 'qr', 'ad', 'keyword'] as $level) {
            if ($this->chatbots->triggerMatches($level, $c, $input)) {
                return $level;
            }
        }

        return null;
    }

    /**
     * Messages a trigger is meant to catch: each keyword alone and in a sentence, the QR code text,
     * each campaign button, and a message from a Click-to-WhatsApp ad.
     *
     * @param  array<string, mixed>  $c
     * @return list<array{0: string, 1: bool, 2: bool}> [text, is a button reply, came from an ad]
     */
    private function samples(array $c): array
    {
        $out = [];
        foreach ($c['keywords'] as $k) {
            $out[] = [(string) $k, false, false];
            $out[] = [$k.' please', false, false];
        }
        if ($c['qr_code'] !== null) {
            $out[] = [(string) $c['qr_code'], false, false];
        }
        foreach ($c['campaign_buttons'] as $b) {
            $out[] = [(string) $b, true, false];
        }
        if ($c['ad_referral'] && ! $c['any_message']) {
            $out[] = ['Hi', false, true];
        }

        return $out;
    }
}
