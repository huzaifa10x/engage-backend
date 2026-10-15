<?php

declare(strict_types=1);

namespace App\Domain\Chatbots\Engine;

/**
 * One call into the engine: the flow that started the session ($main), the graph the session is
 * in right now (the main flow, or a subflow it called), the flow's settings and the runtime.
 */
final class FlowRun
{
    public FlowGraph $graph;

    /**
     * @param  array<string, mixed>  $settings  FlowSettings::normalize() output
     */
    public function __construct(
        public readonly FlowGraph $main,
        public readonly array $settings,
        public readonly BotRuntime $rt,
    ) {
        $this->graph = $main;
    }

    /** The session's language (one of the flow's languages). */
    public function lang(): string
    {
        $langs = (array) $this->settings['languages'];
        $lang = $this->rt->state['vars']['_lang'] ?? null;

        return is_string($lang) && in_array($lang, $langs, true) ? $lang : (string) ($langs[0] ?? 'en');
    }

    public function defaultLang(): string
    {
        return (string) ($this->settings['languages'][0] ?? 'en');
    }
}
