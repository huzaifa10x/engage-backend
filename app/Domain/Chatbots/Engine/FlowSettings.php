<?php

declare(strict_types=1);

namespace App\Domain\Chatbots\Engine;

/**
 * Per-flow behaviour that is not a node: the three timers (kept separate on purpose), retries,
 * and the words that always work (talk to a person, back to the menu).
 */
final class FlowSettings
{
    public const DEFAULTS = [
        // Step inactivity: one reminder when the customer stops answering mid-flow.
        'reminder_minutes' => 12,
        'reminder_text' => 'Are you still there? Just tap one of the options above to continue 🙂',
        // Flow abandonment: the bot stops, the conversation stays open in the inbox.
        'abandon_minutes' => 45,
        // A customer who comes back within this time continues where they left; later = main menu.
        'resume_minutes' => 60,
        // Unrecognised answers before the node's "No match" branch is taken.
        'retry_limit' => 2,
        'invalid_text' => "Sorry, I didn't catch that. Please choose one of the options.",
        'handoff_keywords' => ['agent', 'human', 'talk to agent', 'talk to a person'],
        'menu_keywords' => ['menu', 'main menu'],
        // Conversations a person took over go back to the bot after this many minutes without an
        // agent message (null = only when someone clicks "Hand back to bot" or closes the chat).
        'auto_resume_minutes' => null,
        'abandoned_tag' => null,
    ];

    /**
     * @param  array<string, mixed>|null  $input
     * @return array{reminder_minutes: ?int, reminder_text: string, abandon_minutes: int, resume_minutes: int, retry_limit: int, invalid_text: string, handoff_keywords: list<string>, menu_keywords: list<string>, auto_resume_minutes: ?int, abandoned_tag: ?string}
     */
    public static function normalize(?array $input): array
    {
        $in = ($input ?? []) + self::DEFAULTS;
        $words = fn (mixed $list): array => array_values(array_unique(array_filter(array_map(
            fn ($w) => mb_strtolower(trim(mb_substr((string) $w, 0, 40))),
            is_array($list) ? array_slice($list, 0, 20) : [],
        ), fn ($w) => $w !== '')));
        $int = fn (mixed $v, int $min, int $max): int => max($min, min($max, (int) $v));
        $abandon = $int($in['abandon_minutes'], 5, 1440);
        $reminder = $in['reminder_minutes'] === null || $in['reminder_minutes'] === '' || (int) $in['reminder_minutes'] === 0 ? null : $int($in['reminder_minutes'], 1, 720);
        if ($reminder !== null && $reminder >= $abandon) {
            $reminder = null; // a reminder after the bot already gave up would never be sent
        }
        $tag = trim((string) ($in['abandoned_tag'] ?? ''));

        return [
            'reminder_minutes' => $reminder,
            'reminder_text' => mb_substr(trim((string) $in['reminder_text']), 0, 1000) ?: self::DEFAULTS['reminder_text'],
            'abandon_minutes' => $abandon,
            'resume_minutes' => $int($in['resume_minutes'], 0, 1440),
            'retry_limit' => $int($in['retry_limit'], 0, 5),
            'invalid_text' => mb_substr(trim((string) $in['invalid_text']), 0, 1000) ?: self::DEFAULTS['invalid_text'],
            'handoff_keywords' => $words($in['handoff_keywords']),
            'menu_keywords' => $words($in['menu_keywords']),
            'auto_resume_minutes' => $in['auto_resume_minutes'] === null || $in['auto_resume_minutes'] === '' || (int) $in['auto_resume_minutes'] === 0 ? null : $int($in['auto_resume_minutes'], 5, 10080),
            'abandoned_tag' => $tag === '' ? null : mb_substr($tag, 0, 40),
        ];
    }
}
