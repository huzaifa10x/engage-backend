<?php

declare(strict_types=1);

namespace App\Infrastructure\Meta;

use App\Application\WhatsApp\AccessRevocation;
use App\Domain\WhatsApp\Events\MetaAccessRejected;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The single place the platform talks to Meta's Graph API. Every method maps 1:1 to an endpoint
 * in Meta's documentation (Graph v25.0) — nothing invented. Business-token calls carry
 * appsecret_proof. Tokens are never logged.
 */
final class GraphClient
{
    public function __construct(
        private readonly string $appId,
        private readonly string $appSecret,
        private readonly string $version,
        private readonly string $baseUrl,
        private readonly int $timeout,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (string) config('engage.meta.app_id'),
            (string) config('engage.meta.app_secret'),
            (string) config('engage.meta.graph_version', 'v25.0'),
            (string) config('engage.meta.graph_url', 'https://graph.facebook.com'),
            (int) config('engage.meta.timeout', 20),
        );
    }

    public function version(): string
    {
        return $this->version;
    }

    public function appId(): string
    {
        return $this->appId;
    }

    // ── Embedded Signup onboarding (Tech Provider) ─────────────────────────────────────────

    /** GET /oauth/access_token — exchange the Embedded Signup code (30s TTL) for a business token. */
    public function exchangeCode(string $code): string
    {
        $this->assertConfigured();

        $body = $this->send('GET', 'oauth/access_token', null, [
            'client_id' => $this->appId,
            'client_secret' => $this->appSecret,
            'code' => $code,
        ]);

        $token = $body['access_token'] ?? null;
        if (! is_string($token) || $token === '') {
            throw new MetaApiException('Meta did not return a business token.', 200);
        }

        return $token;
    }

    /**
     * GET /debug_token — validate a token with the app access token.
     *
     * @return array<string, mixed>
     */
    public function debugToken(string $token): array
    {
        $this->assertConfigured();

        $body = $this->send('GET', 'debug_token', null, [
            'input_token' => $token,
            'access_token' => $this->appId.'|'.$this->appSecret,
        ]);

        return is_array($body['data'] ?? null) ? $body['data'] : [];
    }

    /** POST /<WABA_ID>/subscribed_apps */
    public function subscribeApp(string $wabaId, string $token): bool
    {
        return (bool) ($this->send('POST', "{$wabaId}/subscribed_apps", $token)['success'] ?? false);
    }

    /**
     * GET /<WABA_ID>/subscribed_apps — every app currently subscribed to this WABA's webhooks.
     *
     * @return list<array{id: string, name: ?string, link: ?string}>
     */
    public function listSubscribedApps(string $wabaId, string $token): array
    {
        $body = $this->send('GET', "{$wabaId}/subscribed_apps", $token);

        $apps = [];
        foreach ((array) ($body['data'] ?? []) as $row) {
            $app = is_array($row) ? ($row['whatsapp_business_api_data'] ?? null) : null;
            if (! is_array($app) || ! isset($app['id'])) {
                continue;
            }
            $apps[] = [
                'id' => (string) $app['id'],
                'name' => isset($app['name']) && $app['name'] !== '' ? (string) $app['name'] : null,
                'link' => isset($app['link']) && $app['link'] !== '' ? (string) $app['link'] : null,
            ];
        }

        return $apps;
    }

    /** DELETE /<WABA_ID>/subscribed_apps */
    public function unsubscribeApp(string $wabaId, string $token): bool
    {
        return (bool) ($this->send('DELETE', "{$wabaId}/subscribed_apps", $token)['success'] ?? false);
    }

    /** POST /<PHONE_NUMBER_ID>/register — sets the two-step verification PIN. */
    public function registerPhoneNumber(string $phoneNumberId, string $pin, string $token): bool
    {
        return (bool) ($this->send('POST', "{$phoneNumberId}/register", $token, [
            'messaging_product' => 'whatsapp',
            'pin' => $pin,
        ])['success'] ?? false);
    }

    /** POST /<PHONE_NUMBER_ID>/deregister (not allowed for coexistence numbers). */
    public function deregisterPhoneNumber(string $phoneNumberId, string $token): bool
    {
        return (bool) ($this->send('POST', "{$phoneNumberId}/deregister", $token)['success'] ?? false);
    }

    /**
     * POST /<PHONE_NUMBER_ID>/smb_app_data — coexistence contacts / history sync (each once).
     *
     * @param  'smb_app_state_sync'|'history'  $syncType
     */
    public function requestSmbAppData(string $phoneNumberId, string $syncType, string $token): string
    {
        $body = $this->send('POST', "{$phoneNumberId}/smb_app_data", $token, [
            'messaging_product' => 'whatsapp',
            'sync_type' => $syncType,
        ]);

        return (string) ($body['request_id'] ?? '');
    }

    // ── Messaging (Cloud API) ──────────────────────────────────────────────────────────────

    /**
     * POST /<PHONE_NUMBER_ID>/messages — any message type. $message is the type-specific part
     * (to|recipient, type, <type>, context); messaging_product / recipient_type are added here.
     *
     * @param  array<string, mixed>  $message
     * @return array{wamid: string, wa_id: ?string, user_id: ?string, message_status: ?string}
     */
    public function sendMessage(string $phoneNumberId, array $message, string $token): array
    {
        $body = $this->send('POST', "{$phoneNumberId}/messages", $token, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
        ] + $message);

        $wamid = $body['messages'][0]['id'] ?? null;
        if (! is_string($wamid) || $wamid === '') {
            throw new MetaApiException('Cloud API accepted the request but returned no message ID.', 200);
        }

        return [
            'wamid' => $wamid,
            'wa_id' => $body['contacts'][0]['wa_id'] ?? null,
            'user_id' => $body['contacts'][0]['user_id'] ?? null,
            'message_status' => $body['messages'][0]['message_status'] ?? null,
        ];
    }

    /** POST /<PHONE_NUMBER_ID>/messages {status: read} — blue ticks for an inbound message. */
    public function markRead(string $phoneNumberId, string $wamid, string $token): bool
    {
        return (bool) ($this->send('POST', "{$phoneNumberId}/messages", $token, [
            'messaging_product' => 'whatsapp',
            'status' => 'read',
            'message_id' => $wamid,
        ])['success'] ?? false);
    }

    /**
     * POST /<PHONE_NUMBER_ID>/media (multipart) — returns the media ID used in send requests.
     */
    public function uploadMedia(string $phoneNumberId, string $contents, string $filename, string $mime, string $token): string
    {
        $this->assertConfigured();

        try {
            $response = Http::baseUrl($this->baseUrl)
                ->acceptJson()
                ->timeout(max($this->timeout, 60))
                ->withToken($token)
                ->withQueryParameters(['appsecret_proof' => hash_hmac('sha256', $token, $this->appSecret)])
                ->attach('file', $contents, $filename, ['Content-Type' => $mime])
                ->post("{$this->version}/{$phoneNumberId}/media", ['messaging_product' => 'whatsapp', 'type' => $mime]);
        } catch (ConnectionException $e) {
            throw new MetaApiException('Could not reach Meta: '.$e->getMessage(), 503);
        }

        $body = $response->json();
        if ($response->failed() || ! isset($body['id'])) {
            throw MetaApiException::fromResponse($response->status(), is_array($body) ? $body : null);
        }

        return (string) $body['id'];
    }

    /**
     * GET /<MEDIA_ID> — short-lived download URL plus metadata for an inbound media object.
     *
     * @return array{url: string, mime_type: ?string, sha256: ?string, file_size: ?int}
     */
    public function getMedia(string $mediaId, string $token, ?string $phoneNumberId = null): array
    {
        $body = $this->send('GET', $mediaId, $token, array_filter(['phone_number_id' => $phoneNumberId]));

        if (! isset($body['url'])) {
            throw new MetaApiException('Meta returned no download URL for this media.', 200);
        }

        return [
            'url' => (string) $body['url'],
            'mime_type' => $body['mime_type'] ?? null,
            'sha256' => $body['sha256'] ?? null,
            'file_size' => isset($body['file_size']) ? (int) $body['file_size'] : null,
        ];
    }

    /** Downloads bytes from a media URL returned by getMedia (requires the business token). */
    public function downloadMedia(string $url, string $token): string
    {
        if (parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw new MetaApiException('Refusing to download media over a non-HTTPS URL.', 400);
        }

        try {
            $response = Http::timeout(120)->withToken($token)->withUserAgent('10X-Engage/1.0')->get($url);
        } catch (ConnectionException $e) {
            throw new MetaApiException('Could not download media: '.$e->getMessage(), 503);
        }

        if ($response->failed()) {
            throw MetaApiException::fromResponse($response->status(), $response->json());
        }

        return $response->body();
    }

    // ── Message templates ──────────────────────────────────────────────────────────────────

    public const TEMPLATE_FIELDS = ['id', 'name', 'language', 'status', 'category', 'components', 'quality_score', 'rejected_reason', 'parameter_format'];

    /**
     * GET /<WABA_ID>/message_templates — every template of the WABA (all pages).
     *
     * @return list<array<string, mixed>>
     */
    public function listTemplates(string $wabaId, string $token): array
    {
        $templates = [];
        $query = ['fields' => implode(',', self::TEMPLATE_FIELDS), 'limit' => 100];
        $minimal = false;

        // Bounded: 100 pages × 100 = far above Meta's per-WABA template limit.
        for ($page = 0; $page < 100; $page++) {
            try {
                $body = $this->send('GET', "{$wabaId}/message_templates", $token, $query);
            } catch (MetaApiException $e) {
                if (! $e->isUnknownField() || $minimal) {
                    throw $e;
                }
                // An older Graph version without quality_score / parameter_format: ask for less.
                $minimal = true;
                $query['fields'] = 'id,name,language,status,category,components';

                continue;
            }

            foreach ((array) ($body['data'] ?? []) as $row) {
                if (is_array($row) && isset($row['name'])) {
                    $templates[] = $row;
                }
            }

            $after = $body['paging']['cursors']['after'] ?? null;
            if (! isset($body['paging']['next']) || ! is_string($after) || $after === '') {
                break;
            }
            $query['after'] = $after;
        }

        return $templates;
    }

    /**
     * Resumable Upload API: uploads the sample file for a template's media header and returns the
     * file handle Meta expects in `example.header_handle`.
     *   1. POST /<APP_ID>/uploads?file_name&file_length&file_type  → upload session id
     *   2. POST /<SESSION_ID> (Authorization: OAuth <token>, file_offset: 0, raw bytes) → handle
     */
    public function uploadTemplateSample(string $contents, string $filename, string $mime, string $token): string
    {
        try {
            return $this->resumableUpload($contents, $filename, $mime, $token);
        } catch (MetaApiException $e) {
            if ($e->isTransient()) {
                throw $e;
            }
            // Upload sessions belong to the app, not to the client's account: when Meta refuses
            // the client's token here, the app's own token is the documented alternative.
            Log::warning('Template sample upload failed with the business token; retrying with the app token.', $e->context());

            return $this->resumableUpload($contents, $filename, $mime, "{$this->appId}|{$this->appSecret}");
        }
    }

    private function resumableUpload(string $contents, string $filename, string $mime, string $token): string
    {
        $session = $this->send('POST', "{$this->appId}/uploads", $token, [], [
            'file_name' => $filename,
            'file_length' => strlen($contents),
            'file_type' => $mime,
        ]);
        $sessionId = $session['id'] ?? null;
        if (! is_string($sessionId) || $sessionId === '') {
            throw new MetaApiException('Meta did not start the file upload for the template header.', 400);
        }

        // The session id carries its own signature ("upload:<id>?sig=<signature>"). It must reach
        // Meta exactly as issued, so nothing may replace its query string — extra parameters are
        // appended to it instead of being passed as request options.
        $url = "{$this->version}/{$sessionId}".(str_contains($sessionId, '?') ? '&' : '?')
            .'appsecret_proof='.hash_hmac('sha256', $token, $this->appSecret);

        try {
            $response = Http::baseUrl($this->baseUrl)
                ->acceptJson()
                ->timeout(max($this->timeout, 120))
                ->withHeaders(['Authorization' => 'OAuth '.$token, 'file_offset' => '0'])
                ->withBody($contents, 'application/octet-stream')
                ->post($url);
        } catch (ConnectionException $e) {
            throw new MetaApiException('Could not reach Meta: '.$e->getMessage(), 503);
        }

        $body = $response->json();
        if ($response->failed() || ! is_array($body) || empty($body['h'])) {
            // This endpoint reports problems as {"debug_info": {"type", "message"}}, not {"error": …}.
            $debug = is_array($body) && is_array($body['debug_info'] ?? null) ? $body['debug_info'] : null;
            Log::warning('Template sample upload was rejected by Meta.', ['http_status' => $response->status(), 'body' => mb_substr($response->body(), 0, 500)]);

            throw $debug !== null
                ? new MetaApiException(trim(($debug['type'] ?? 'Upload error').': '.($debug['message'] ?? 'the file was rejected'), ': '), $response->status())
                : MetaApiException::fromResponse($response->status(), is_array($body) ? $body : null);
        }

        return (string) $body['h'];
    }

    /**
     * POST /<WABA_ID>/message_templates — create and submit a template for review.
     *
     * @param  array<string, mixed>  $template  name, language, category, components
     * @return array{id: string, status: ?string, category: ?string}
     */
    public function createTemplate(string $wabaId, array $template, string $token): array
    {
        $body = $this->send('POST', "{$wabaId}/message_templates", $token, $template);

        if (! isset($body['id'])) {
            throw new MetaApiException('Meta accepted the template but returned no template ID.', 200);
        }

        return [
            'id' => (string) $body['id'],
            'status' => isset($body['status']) ? (string) $body['status'] : null,
            'category' => isset($body['category']) ? (string) $body['category'] : null,
        ];
    }

    /**
     * POST /<TEMPLATE_ID> — edit a template (components and/or category); it goes back to review.
     *
     * @param  array<string, mixed>  $changes
     */
    public function updateTemplate(string $templateId, array $changes, string $token): bool
    {
        return (bool) ($this->send('POST', $templateId, $token, $changes)['success'] ?? false);
    }

    /** DELETE /<WABA_ID>/message_templates?name=…[&hsm_id=…] — hsm_id limits it to one language. */
    public function deleteTemplate(string $wabaId, string $name, ?string $templateId, string $token): bool
    {
        $query = array_filter(['name' => $name, 'hsm_id' => $templateId]);

        return (bool) ($this->send('DELETE', "{$wabaId}/message_templates", $token, [], $query)['success'] ?? false);
    }

    // ── Reads ──────────────────────────────────────────────────────────────────────────────

    public const WABA_FIELDS = ['id', 'name', 'currency', 'timezone_id', 'message_template_namespace', 'account_review_status', 'owner_business_info', 'health_status'];

    public const PHONE_FIELDS = [
        'id', 'display_phone_number', 'verified_name', 'name_status', 'quality_rating', 'code_verification_status',
        'is_official_business_account', 'platform_type', 'throughput', 'messaging_limit_tier', 'is_on_biz_app',
    ];

    /** @return array<string, mixed> GET /<WABA_ID> */
    public function getWaba(string $wabaId, string $token): array
    {
        return $this->getWithFieldFallback($wabaId, self::WABA_FIELDS, ['id', 'name', 'currency', 'timezone_id', 'message_template_namespace'], $token);
    }

    /** @return array<string, mixed> GET /<PHONE_NUMBER_ID> */
    public function getPhoneNumber(string $phoneNumberId, string $token): array
    {
        return $this->getWithFieldFallback($phoneNumberId, self::PHONE_FIELDS, ['id', 'display_phone_number', 'verified_name', 'quality_rating', 'code_verification_status'], $token);
    }

    /** @return list<array<string, mixed>> GET /<WABA_ID>/phone_numbers */
    public function listPhoneNumbers(string $wabaId, string $token): array
    {
        try {
            $body = $this->send('GET', "{$wabaId}/phone_numbers", $token, ['fields' => implode(',', self::PHONE_FIELDS)]);
        } catch (MetaApiException $e) {
            if (! $e->isUnknownField()) {
                throw $e;
            }
            $body = $this->send('GET', "{$wabaId}/phone_numbers", $token);
        }

        return array_values(array_filter((array) ($body['data'] ?? []), 'is_array'));
    }

    // ── Transport ──────────────────────────────────────────────────────────────────────────

    /**
     * @param  list<string>  $fields
     * @param  list<string>  $minimal
     * @return array<string, mixed>
     */
    private function getWithFieldFallback(string $node, array $fields, array $minimal, string $token): array
    {
        try {
            return $this->send('GET', $node, $token, ['fields' => implode(',', $fields)]);
        } catch (MetaApiException $e) {
            if (! $e->isUnknownField()) {
                throw $e;
            }
            Log::warning('Graph field set rejected; retrying with minimal fields.', ['node' => $node] + $e->context());

            return $this->send('GET', $node, $token, ['fields' => implode(',', $minimal)]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $query  extra query-string parameters (DELETE filters)
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, ?string $token, array $data = [], array $query = []): array
    {
        $request = $this->request($token);
        if ($query !== []) {
            $request = $request->withQueryParameters($query);
        }
        $url = "{$this->version}/{$path}";

        try {
            $response = match ($method) {
                'GET' => $request->get($url, $data),
                'POST' => $request->post($url, $data),
                'DELETE' => $request->delete($url, $data),
                default => throw new RuntimeException("Unsupported method {$method}"),
            };
        } catch (ConnectionException $e) {
            throw new MetaApiException('Could not reach Meta: '.$e->getMessage(), 503);
        }

        $body = $response->json();

        if ($response->failed() || isset($body['error'])) {
            $exception = MetaApiException::fromResponse($response->status(), is_array($body) ? $body : null);
            Log::warning('Graph API error', ['method' => $method, 'path' => $this->redactPath($path)] + $exception->context());

            // An authorisation error on a call made with a customer's token may mean they removed our
            // access at Meta. Announce it here, for every call, so no caller has to remember to check.
            $object = explode('/', ltrim($path, '/'))[0];
            if ($token !== null && ctype_digit($object) && AccessRevocation::looksRevoked($exception)) {
                event(new MetaAccessRejected($object, $exception->metaCode, $exception->metaSubcode));
            }

            throw $exception;
        }

        return is_array($body) ? $body : [];
    }

    private function request(?string $token): PendingRequest
    {
        $request = Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout)
            ->connectTimeout(5)
            ->withUserAgent('10X-Engage/1.0');

        if ($token !== null) {
            $this->assertConfigured();
            $request = $request->withToken($token)->withQueryParameters([
                'appsecret_proof' => hash_hmac('sha256', $token, $this->appSecret),
            ]);
        }

        return $request;
    }

    private function redactPath(string $path): string
    {
        return preg_replace('/\d{6,}/', '{id}', $path) ?? $path;
    }

    private function assertConfigured(): void
    {
        if ($this->appId === '' || $this->appSecret === '') {
            throw new RuntimeException('META_APP_ID / META_APP_SECRET are not configured.');
        }
    }
}
