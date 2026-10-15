<?php

declare(strict_types=1);

namespace App\Domain\Chatbots\Services;

/** Starting points for a new flow. Each one passes the publish checks as it is. */
final class FlowTemplates
{
    public const KEYS = ['menu', 'lead', 'blank'];

    /** @return array{nodes: list<array<string, mixed>>} */
    public static function graph(string $key): array
    {
        return match ($key) {
            'lead' => self::lead(),
            'blank' => self::blank(),
            default => self::menu(),
        };
    }

    /** @return array{nodes: list<array<string, mixed>>} */
    private static function blank(): array
    {
        return ['nodes' => [
            self::node('start', 'start', 'Conversation starts', 60, 40, ['any_message' => true, 'keywords' => [], 'ad_referral' => true], ['next' => 'handoff']),
            self::node('handoff', 'handoff', 'Talk to a person', 60, 240, ['text' => 'Thanks for your message! A member of our team will reply shortly.', 'assign' => 'none'], []),
        ]];
    }

    /** @return array{nodes: list<array<string, mixed>>} */
    private static function menu(): array
    {
        return ['nodes' => [
            self::node('start', 'start', 'Conversation starts', 330, 20, ['any_message' => true, 'keywords' => ['hi', 'hello', 'menu'], 'ad_referral' => true], ['next' => 'welcome']),
            self::node('welcome', 'buttons', 'Welcome menu', 300, 170, [
                'text' => 'Hi {{contact.first_name}} 👋 Welcome! How can we help you today?',
                'buttons' => [['id' => 'services', 'title' => 'Our services'], ['id' => 'hours', 'title' => 'Opening hours'], ['id' => 'person', 'title' => 'Talk to a person']],
            ], ['services' => 'services', 'hours' => 'hours', 'person' => 'handoff', 'fallback' => 'handoff']),
            self::node('services', 'message', 'Our services', 20, 390, ['text' => "Here is what we offer:\n• Service one\n• Service two\n• Service three"], ['next' => 'more']),
            self::node('hours', 'message', 'Opening hours', 300, 390, ['text' => 'We are open Sunday to Thursday, 9:00 to 18:00.'], ['next' => 'more']),
            self::node('more', 'buttons', 'Anything else?', 150, 590, [
                'text' => 'Can we help with anything else?',
                'buttons' => [['id' => 'menu', 'title' => 'Main menu'], ['id' => 'person', 'title' => 'Talk to a person'], ['id' => 'done', 'title' => 'No, thanks']],
            ], ['menu' => 'welcome', 'person' => 'handoff', 'done' => 'end', 'fallback' => 'handoff']),
            self::node('handoff', 'handoff', 'Talk to a person', 620, 390, [
                'text' => 'Connecting you with our team 👩‍💼 Someone will reply shortly.',
                'offline_text' => 'Thanks! Our team is away right now and will reply as soon as we are back.',
                'assign' => 'none',
            ], []),
            self::node('end', 'end', 'Goodbye', 180, 800, ['text' => 'Thank you for contacting us! 😊', 'action' => 'none'], []),
        ]];
    }

    /** @return array{nodes: list<array<string, mixed>>} */
    private static function lead(): array
    {
        return ['nodes' => [
            self::node('start', 'start', 'Conversation starts', 60, 30, ['any_message' => true, 'keywords' => [], 'ad_referral' => true], ['next' => 'ask_name']),
            self::node('ask_name', 'question', 'Ask the name', 60, 180, ['text' => 'Welcome! 👋 What is your name?', 'save_to' => 'name', 'validate' => 'text'], ['next' => 'ask_email', 'fallback' => 'handoff']),
            self::node('ask_email', 'question', 'Ask the email', 60, 360, [
                'text' => 'Thanks {{contact.first_name}}! What is your email address?', 'save_to' => 'email', 'validate' => 'email',
                'error_text' => 'That does not look like an email address. Please type it again, e.g. name@example.com',
            ], ['next' => 'tag', 'fallback' => 'handoff']),
            self::node('tag', 'tag', 'Tag as lead', 60, 540, ['add' => ['Lead'], 'remove' => [], 'assign' => 'none'], ['next' => 'handoff']),
            self::node('handoff', 'handoff', 'Talk to a person', 380, 540, ['text' => 'Thank you! A member of our team will contact you shortly.', 'assign' => 'auto'], []),
        ]];
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, string>  $to  output id → node id
     * @return array<string, mixed>
     */
    private static function node(string $id, string $type, string $title, int $x, int $y, array $config, array $to): array
    {
        return [
            'id' => $id, 'type' => $type, 'title' => $title, 'x' => $x, 'y' => $y, 'config' => $config,
            'outputs' => array_map(fn (string $out, string $target) => ['id' => $out, 'to' => $target], array_keys($to), $to),
        ];
    }
}
