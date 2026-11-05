<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Services;

use App\Domain\Commerce\Models\CommerceLinkToken;
use App\Domain\Growth\Models\GrowthEvidence;
use App\Domain\Growth\Services\MetaReferral;
use App\Domain\Growth\Website\WebsiteEvidence;
use App\Domain\Integrations\Models\Integration;
use App\Domain\Messaging\Enums\MessageOrigin;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The chat-to-order link (Engagement Plan v4 §V.2, "Link from the chat"): every link to a connected
 * WooCommerce store that an agent or the bot sends gets a new token, ?xl=…, tied to that
 * conversation. The plugin saves it on the order, and the order is then an "Order from a tracked
 * chat link". This is not the R1 XG- reference (24 hours, single use, records how a contact arrived).
 *
 * The token is random with a short keyed checksum (bogus tokens are refused without a lookup); only
 * its HMAC is stored. It is valid for the store's link period (14 days by default) and attaches one
 * order, once, in its own workspace.
 */
final class ChatLinks
{
    public const PARAM = 'xl';

    public const DEFAULT_DAYS = 14;

    private const RANDOM = 20;

    private const CHECK = 4;

    /** Agent replies and the bot (and automations) — not campaigns or API messages. */
    private const ORIGINS = [MessageOrigin::Agent, MessageOrigin::Automation];

    public function __construct(private readonly TenantContext $context) {}

    /**
     * Adds a fresh token to every store link in an outbound text, interactive body or call-to-action
     * button. Returns the message data and the tokens created (their message is set once it exists).
     *
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    public function tag(Conversation $conversation, Contact $contact, array $data, MessageOrigin $origin, ?TenantMembership $sender): array
    {
        if (! in_array($origin, self::ORIGINS, true) || ! in_array($data['type'] ?? null, ['text', 'interactive'], true)) {
            return [$data, []];
        }
        $stores = $this->stores();
        if ($stores === []) {
            return [$data, []];
        }

        $ids = [];
        $source = false; // looked up once, on the first link
        $rewrite = function (?string $text) use ($stores, $conversation, $contact, $origin, $sender, &$ids, &$source): ?string {
            if ($text === null || stripos($text, 'http') === false) {
                return $text;
            }

            return preg_replace_callback('~https?://[^\s<>"\'`]+~i', function (array $m) use ($stores, $conversation, $contact, $origin, $sender, &$ids, &$source) {
                $url = rtrim($m[0], '.,!?;:)]}\'"');
                $tail = substr($m[0], strlen($url));
                $host = strtolower((string) parse_url($url, PHP_URL_HOST));
                $days = $stores[(string) preg_replace('/^www\./', '', $host)] ?? null;
                if ($days === null) {
                    return $m[0];
                }
                if ($source === false) {
                    $source = self::conversationSource($conversation, $contact);
                }
                [$token, $id] = $this->mint($conversation, $contact, $origin, $sender, $url, $days, $source);
                $ids[] = $id;

                return self::withToken($url, $token).$tail;
            }, $text);
        };

        if ($data['type'] === 'text') {
            $data['body'] = $rewrite($data['body'] ?? null);
        } else {
            $content = (array) ($data['content'] ?? []);
            if (isset($content['body']['text']) && is_string($content['body']['text'])) {
                $content['body']['text'] = $rewrite($content['body']['text']);
                $data['body'] = $content['body']['text'];
            }
            if (isset($content['action']['parameters']['url']) && is_string($content['action']['parameters']['url'])) {
                $content['action']['parameters']['url'] = $rewrite($content['action']['parameters']['url']);
            }
            $data['content'] = $content;
        }

        return [$data, $ids];
    }

    /** @param list<string> $ids */
    public function attach(array $ids, string $messageId): void
    {
        if ($ids !== []) {
            CommerceLinkToken::query()->whereIn('id', $ids)->update(['message_id' => $messageId]);
        }
    }

    /** The token row for a token a store sent back, or null when it is not one of ours (in this workspace). */
    public function find(string $token): ?CommerceLinkToken
    {
        if (! self::wellFormed($token)) {
            return null;
        }

        /** @var ?CommerceLinkToken */
        return CommerceLinkToken::query()->whereRaw('digest = decode(?, \'hex\')', [self::digest($token)])->first();
    }

    public static function wellFormed(string $token): bool
    {
        if (preg_match('/^[A-Za-z0-9]{'.(self::RANDOM + self::CHECK).'}$/', $token) !== 1) {
            return false;
        }

        return hash_equals(self::check(substr($token, 0, self::RANDOM)), substr($token, self::RANDOM));
    }

    public static function withToken(string $url, string $token): string
    {
        $fragment = '';
        if (($hash = strpos($url, '#')) !== false) {
            $fragment = substr($url, $hash);
            $url = substr($url, 0, $hash);
        }
        // A token copied from an older message is replaced, never doubled.
        $url = (string) preg_replace('/([?&])'.self::PARAM.'=[^&]*(&|$)/', '$1', $url);
        $url = rtrim($url, '?&');

        return $url.(str_contains($url, '?') ? '&' : '?').self::PARAM.'='.$token.$fragment;
    }

    /**
     * The source of this chat when the link is sent: the latest tracked touch that arrived in this
     * conversation. A buyer who first came from Google Ads and returns through a Meta ad has the Meta
     * ad as this chat's source.
     *
     * @return array<string, mixed>|null
     */
    public static function conversationSource(Conversation $conversation, Contact $contact): ?array
    {
        /** @var ?GrowthEvidence $evidence */
        $evidence = GrowthEvidence::query()
            ->whereIn('message_id', fn ($q) => $q->select('id')->from('messages')->where('conversation_id', $conversation->id))
            ->whereNot('confidence', 'reused_other_sender')
            ->orderByDesc('occurred_at')->first();
        if ($evidence === null) {
            return null;
        }
        foreach (['acquisition_latest', 'acquisition_original'] as $column) {
            $touch = $contact->getAttribute($column);
            if (is_array($touch) && ($touch['evidence_id'] ?? null) === $evidence->id) {
                return $touch;
            }
        }
        $f = $evidence->provider_fields;
        $label = $evidence->evidence_type === GrowthEvidence::TYPE_META_CTWA
            ? MetaReferral::label($f['platform'] ?? null, $f['source_type'] ?? null)
            : (WebsiteEvidence::present($evidence)['label'] ?? $evidence->source);

        return [
            'evidence_id' => $evidence->id, 'type' => $evidence->evidence_type, 'occurred_at' => $evidence->occurred_at->toIso8601String(),
            'source' => $evidence->source, 'label' => $label,
            'campaign' => $f['campaign']['utm_campaign'] ?? null, 'headline' => $f['headline'] ?? null,
        ];
    }

    /**
     * Connected stores that can carry the token (plugin installed): host => link validity in days.
     *
     * @return array<string, int>
     */
    private function stores(): array
    {
        $tenant = $this->context->tenantOrNull();
        if ($tenant === null) {
            return [];
        }

        return Cache::remember("commerce:stores:{$tenant->id}", 300, fn () => Integration::query()
            ->where('provider', Integration::WOOCOMMERCE)->where('status', 'active')->whereNotNull('plugin_secret')->get()
            ->filter(fn (Integration $i) => $i->host() !== null)
            ->mapWithKeys(fn (Integration $i) => [(string) $i->host() => max(1, min(90, (int) $i->setting('link_days', self::DEFAULT_DAYS)))])
            ->all());
    }

    public static function forgetStores(string $tenantId): void
    {
        Cache::forget("commerce:stores:{$tenantId}");
    }

    /**
     * @param  array<string, mixed>|null  $source
     * @return array{0: string, 1: string}
     */
    private function mint(Conversation $conversation, Contact $contact, MessageOrigin $origin, ?TenantMembership $sender, string $url, int $days, ?array $source): array
    {
        $random = Str::random(self::RANDOM);
        $token = $random.self::check($random);
        $id = (string) Str::uuid7();
        DB::table('commerce_link_tokens')->insert([
            'id' => $id,
            'tenant_id' => $conversation->tenant_id,
            'digest' => DB::raw("decode('".self::digest($token)."', 'hex')"),
            'conversation_id' => $conversation->id,
            'contact_id' => $contact->id,
            'sent_by' => $origin === MessageOrigin::Agent ? 'agent' : 'bot',
            'sent_by_membership_id' => $sender?->id,
            'url' => mb_substr($url, 0, 2048),
            'conversation_source' => $source !== null ? json_encode($source, JSON_THROW_ON_ERROR) : null,
            'expires_at' => Carbon::now()->addDays($days),
            'created_at' => Carbon::now(),
        ]);

        return [$token, $id];
    }

    private static function digest(string $token): string
    {
        return hash_hmac('sha256', 'xl:'.$token, (string) config('app.key'));
    }

    private static function check(string $random): string
    {
        $raw = hash_hmac('sha256', 'xl-check:'.$random, (string) config('app.key'), true);
        $alphabet = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $out = '';
        for ($i = 0; $i < self::CHECK; $i++) {
            $out .= $alphabet[ord($raw[$i]) % 62];
        }

        return $out;
    }
}
