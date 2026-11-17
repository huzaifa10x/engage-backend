<?php

declare(strict_types=1);

namespace App\Domain\Chatbots\Services;

use App\Application\WhatsApp\WhatsappCredentials;
use App\Domain\Audit\AuditLogger;
use App\Domain\Chatbots\Models\ChatbotFlow;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Domain\WhatsApp\Models\WabaAccount;
use App\Infrastructure\Meta\GraphClient;
use App\Infrastructure\Meta\MetaApiException;
use Illuminate\Validation\ValidationException;

/**
 * Welcome message and ice breakers of one number (Meta's Conversational Automation API).
 *
 * Meta shows the ice breakers in a new chat; a tap arrives as an ordinary text message with the ice
 * breaker's words, which starts the flow chosen for it. With the welcome message on, Meta tells us when
 * someone opens the chat for the first time (a request_welcome message) and the welcome flow answers.
 */
final class WelcomeAutomation
{
    /** Meta: up to 4 ice breakers, 80 characters each, no emoji. */
    public const MAX_ICE_BREAKERS = 4;

    public const MAX_LENGTH = 80;

    public function __construct(
        private readonly GraphClient $graph,
        private readonly WhatsappCredentials $credentials,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{welcome_enabled: bool, welcome_flow_id: ?string, ice_breakers: list<array{text: string, flow_id: ?string}>, synced_at: ?string} */
    public static function settings(PhoneNumber $number): array
    {
        /** @var array<string, mixed> $c */
        $c = (array) ($number->getAttribute('conversational_automation') ?? []);
        $breakers = [];
        foreach ((array) ($c['ice_breakers'] ?? []) as $b) {
            $b = (array) $b;
            $breakers[] = ['text' => (string) ($b['text'] ?? ''), 'flow_id' => isset($b['flow_id']) ? (string) $b['flow_id'] : null];
        }

        return [
            'welcome_enabled' => (bool) ($c['welcome_enabled'] ?? false),
            'welcome_flow_id' => isset($c['welcome_flow_id']) ? (string) $c['welcome_flow_id'] : null,
            'ice_breakers' => $breakers,
            'synced_at' => isset($c['synced_at']) ? (string) $c['synced_at'] : null,
        ];
    }

    /**
     * Validates, sets them on Meta, then keeps them. Nothing is kept when Meta refuses.
     *
     * @param  array<string, mixed>  $data  welcome_enabled, welcome_flow_id, ice_breakers: [{text, flow_id}]
     * @return array<string, mixed>
     */
    public function save(PhoneNumber $number, array $data): array
    {
        $breakers = [];
        foreach (array_values((array) ($data['ice_breakers'] ?? [])) as $i => $b) {
            $b = (array) $b;
            $text = trim((string) ($b['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $breakers[] = ['text' => $text, 'flow_id' => isset($b['flow_id']) && $b['flow_id'] !== '' ? (string) $b['flow_id'] : null, 'index' => $i];
        }
        if (count($breakers) > self::MAX_ICE_BREAKERS) {
            throw ValidationException::withMessages(['ice_breakers' => 'WhatsApp shows up to 4 ice breakers.']);
        }
        $seen = [];
        foreach ($breakers as $b) {
            $key = "ice_breakers.{$b['index']}";
            if (mb_strlen($b['text']) > self::MAX_LENGTH) {
                throw ValidationException::withMessages(["{$key}.text" => 'An ice breaker can be at most 80 characters.']);
            }
            if (preg_match('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}\x{1F1E6}-\x{1F1FF}]/u', $b['text']) === 1) {
                throw ValidationException::withMessages(["{$key}.text" => 'WhatsApp does not allow emoji in ice breakers.']);
            }
            $norm = mb_strtolower($b['text']);
            if (isset($seen[$norm])) {
                throw ValidationException::withMessages(["{$key}.text" => 'Two ice breakers have the same words.']);
            }
            $seen[$norm] = true;
            if ($b['flow_id'] === null) {
                throw ValidationException::withMessages(["{$key}.flow_id" => 'Choose the flow this ice breaker starts.']);
            }
            $this->liveFlow($number, $b['flow_id'], "{$key}.flow_id");
        }
        $welcome = (bool) ($data['welcome_enabled'] ?? false);
        $welcomeFlow = isset($data['welcome_flow_id']) && $data['welcome_flow_id'] !== '' ? (string) $data['welcome_flow_id'] : null;
        if ($welcome && $welcomeFlow === null) {
            throw ValidationException::withMessages(['welcome_flow_id' => 'Choose the flow that welcomes people who open the chat.']);
        }
        if ($welcomeFlow !== null) {
            $this->liveFlow($number, $welcomeFlow, 'welcome_flow_id');
        }

        /** @var WabaAccount $waba */
        $waba = $number->wabaAccount()->firstOrFail();
        try {
            $this->graph->setConversationalAutomation($number->phone_number_id, $welcome, array_column($breakers, 'text'), $this->credentials->tokenFor($waba));
        } catch (MetaApiException $e) {
            throw ValidationException::withMessages(['ice_breakers' => 'WhatsApp did not accept these settings: '.$e->getMessage()]);
        }

        $before = self::settings($number);
        $number->forceFill(['conversational_automation' => [
            'welcome_enabled' => $welcome,
            'welcome_flow_id' => $welcomeFlow,
            'ice_breakers' => array_map(fn (array $b) => ['text' => $b['text'], 'flow_id' => $b['flow_id']], $breakers),
            'synced_at' => now()->toIso8601String(),
        ]])->save();
        $this->audit->record('chatbots.welcome_updated', $number, before: $before, after: self::settings($number));

        return self::settings($number);
    }

    /**
     * What Meta shows now (to spot changes made in WhatsApp Manager).
     *
     * @return array{enable_welcome_message: ?bool, prompts: list<string>}|null
     */
    public function onMeta(PhoneNumber $number): ?array
    {
        try {
            /** @var WabaAccount $waba */
            $waba = $number->wabaAccount()->firstOrFail();

            return $this->graph->getConversationalAutomation($number->phone_number_id, $this->credentials->tokenFor($waba));
        } catch (\Throwable) {
            return null;
        }
    }

    /** The flow an ice breaker's words start on this number, if the message is exactly one of them. */
    public static function iceBreakerFlow(PhoneNumber|string $number, ?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }
        $number = is_string($number) ? PhoneNumber::query()->find($number) : $number;
        if ($number === null) {
            return null;
        }
        $norm = mb_strtolower(trim($text));
        foreach (self::settings($number)['ice_breakers'] as $b) {
            if (mb_strtolower($b['text']) === $norm) {
                return $b['flow_id'];
            }
        }

        return null;
    }

    private function liveFlow(PhoneNumber $number, string $flowId, string $field): void
    {
        $ok = ChatbotFlow::query()->whereKey($flowId)->where('phone_number_id', $number->id)->where('kind', 'bot')
            ->whereNotNull('published_version')->whereNull('archived_at')->exists();
        if (! $ok) {
            throw ValidationException::withMessages([$field => 'Choose a published flow of this number.']);
        }
    }
}
