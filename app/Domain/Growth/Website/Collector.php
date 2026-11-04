<?php

declare(strict_types=1);

namespace App\Domain\Growth\Website;

use App\Domain\Growth\GrowthAccess;
use App\Domain\Growth\Jobs\ReconcileWebsiteClick;
use App\Domain\Growth\Models\GrowthSite;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * The click collector behind e.10xengage.com/v1/c (Technical Specification v1.2 §3, §8): turns a
 * tracker beacon into an accepted click and its reference, atomically, or refuses it. The browser's
 * code is an untrusted claim until it is bound here to (workspace, site, mapping, number, event).
 *
 * Never calls the CRM or the chatbot: matching a waiting message runs later on the queue. WhatsApp
 * has already opened by the time this runs, so every refusal is invisible to the visitor.
 */
final class Collector
{
    public const MAX_BYTES = 4096;

    private const KEYS = ['site_key', 'config_version', 'event_id', 'reference', 'mapping_id', 'page_path', 'landing_path', 'language', 'campaign', 'collection_mode', 'source_of_campaign_params', 'occurred_at', 'referrer_host', 'sdk_version'];

    private const BOT_UA = '/bot\b|crawl|spider|slurp|headless|phantomjs|puppeteer|playwright|selenium|lighthouse|pagespeed|gtmetrix|facebookexternalhit|whatsapp\/|preview|prefetch|curl\/|wget\/|python-requests|httpclient|okhttp|java\//i';

    public function __construct(private readonly TenantContext $context) {}

    /**
     * The site for a public key, with its workspace; null when unknown.
     *
     * @return array{0: GrowthSite, 1: Tenant}|null
     */
    public function site(?string $key): ?array
    {
        if (! is_string($key) || preg_match('/^pk_site_[a-z0-9]{24}$/', $key) !== 1) {
            return null;
        }

        return $this->context->bypass(function () use ($key) {
            $site = GrowthSite::query()->withoutGlobalScopes()->where('site_key_hash', hash('sha256', $key))->first();
            $tenant = $site !== null ? Tenant::query()->find($site->tenant_id) : null;

            return $site !== null && $tenant !== null ? [$site, $tenant] : null;
        });
    }

    /** Whether the workspace may track right now: Growth Hub on at the full tier and the site enabled. */
    public static function active(Tenant $tenant, GrowthSite $site): bool
    {
        return $site->enabled && GrowthAccess::full($tenant);
    }

    /** The Origin (or, failing that, Referer) host, if it is one of the site's allowed hosts. */
    public static function allowedOrigin(GrowthSite $site, ?string $origin, ?string $referer): ?string
    {
        $host = fn (?string $url) => $url !== null && $url !== '' && $url !== 'null' ? WebsiteSource::host((string) parse_url($url, PHP_URL_HOST)) : null;
        $scheme = fn (?string $url) => $url !== null ? parse_url($url, PHP_URL_SCHEME) : null;
        $o = $host($origin);
        if ($o !== null) {
            // Origin is the browser's statement; a Referer from another host contradicts it.
            $r = $host($referer);
            if ($scheme($origin) !== 'https' || ! in_array($o, $site->allowedHosts(), true) || ($r !== null && $r !== $o)) {
                return null;
            }

            return $o;
        }
        $r = $host($referer);

        return $r !== null && $scheme($referer) === 'https' && in_array($r, $site->allowedHosts(), true) ? $r : null;
    }

    /** The tracker loaded on the site: remembered at most once a minute (site health). */
    public function seen(GrowthSite $site, Tenant $tenant): void
    {
        if (! Cache::add('growth:sdk-seen:'.$site->id, 1, 60)) {
            return;
        }
        $this->context->run($tenant, fn () => DB::table('growth_site_health')
            ->updateOrInsert(['site_id' => $site->id], ['tenant_id' => $site->tenant_id, 'sdk_last_seen_at' => now(), 'config_version' => $site->config_version]));
    }

    /** "Open my site": the tracker found ?xg_verify=… on the site's own page. */
    public function verify(GrowthSite $site, Tenant $tenant, string $challenge): bool
    {
        if ($site->challenge_hash === null || $site->challenge_expires_at === null || $site->challenge_expires_at->isPast()
            || ! hash_equals($site->challenge_hash, hash('sha256', $challenge))) {
            return false;
        }

        $this->context->run($tenant, function () use ($site) {
            GrowthSite::query()->whereKey($site->id)->update(['verified_at' => now(), 'verification_method' => 'sdk_challenge', 'challenge_hash' => null, 'challenge_expires_at' => null]);
            DB::table('growth_site_health')->updateOrInsert(['site_id' => $site->id], ['tenant_id' => $site->tenant_id, 'sdk_last_seen_at' => now()]);
        });

        return true;
    }

    /**
     * One beacon. Returns [HTTP status, body].
     *
     * @return array{0: int, 1: array<string, mixed>, 2?: int}
     */
    public function register(string $body, ?string $origin, ?string $referer, ?string $userAgent, string $ip, ?string $purpose): array
    {
        if (strlen($body) > self::MAX_BYTES) {
            return [413, ['error' => 'payload_too_large']];
        }
        $data = json_decode($body, true);
        if (! is_array($data) || array_is_list($data) || array_diff(array_keys($data), self::KEYS) !== []) {
            return [422, ['error' => 'invalid_payload']];
        }

        $found = $this->site($data['site_key'] ?? null);
        if ($found === null) {
            return [403, ['error' => 'unknown_site']];
        }
        [$site, $tenant] = $found;

        $host = self::allowedOrigin($site, $origin, $referer);
        if ($host === null) {
            $this->error($site, $tenant, 'origin_not_allowed');

            return [403, ['error' => 'origin_not_allowed']];
        }
        if (! self::active($tenant, $site)) {
            return [403, ['error' => 'site_disabled']];
        }

        // Abuse limits (beta): per site 10/s steady with bursts of 30 per 5 s; per visitor IP 3/s,
        // bursts of 12, 300 an hour. The IP is only ever a short-lived hashed limiter key.
        $ipKey = hash('sha256', $ip.'|'.config('app.key'));
        foreach ([["s5:{$site->id}", 30, 5], ["s60:{$site->id}", 600, 60], ["i4:{$ipKey}", 12, 4], ["i3600:{$ipKey}", 300, 3600]] as [$key, $max, $decay]) {
            if (RateLimiter::tooManyAttempts('growth-c:'.$key, $max)) {
                return [429, ['error' => 'rate_limited'], max(1, RateLimiter::availableIn('growth-c:'.$key))];
            }
        }
        foreach ([["s5:{$site->id}", 5], ["s60:{$site->id}", 60], ["i4:{$ipKey}", 4], ["i3600:{$ipKey}", 3600]] as [$key, $decay]) {
            RateLimiter::hit('growth-c:'.$key, $decay);
        }

        $eventId = $data['event_id'] ?? null;
        $reference = $data['reference'] ?? null;
        $mappingId = $data['mapping_id'] ?? null;
        if (! is_string($eventId) || ! Str::isUuid($eventId) || ! is_string($reference) || ! Reference::valid($reference) || ! is_string($mappingId) || ! Str::isUuid($mappingId)) {
            $this->error($site, $tenant, 'invalid_payload');

            return [422, ['error' => 'invalid_payload']];
        }

        return $this->context->run($tenant, function () use ($site, $tenant, $data, $eventId, $reference, $mappingId, $userAgent, $purpose) {
            $mapping = DB::table('growth_site_numbers as m')->join('phone_numbers as p', 'p.id', '=', 'm.phone_number_id')
                ->where('m.site_id', $site->id)->where('m.mapping_id', $mappingId)->where('p.status', 'connected')
                ->first(['m.phone_number_id']);
            if ($mapping === null) {
                $this->error($site, $tenant, 'unknown_mapping');

                return [403, ['error' => 'unknown_mapping']];
            }

            $campaign = WebsiteSource::sanitize($data['campaign'] ?? null);
            $origin = ($data['source_of_campaign_params'] ?? null) === 'session_landing' ? 'session_landing' : 'current_page';
            $mode = ($data['collection_mode'] ?? null) === 'consent' ? 'consent' : 'click_only';
            $path = self::path($data['page_path'] ?? null);
            $landing = self::path($data['landing_path'] ?? null);
            $referrer = WebsiteSource::host($data['referrer_host'] ?? null);
            $language = is_string($data['language'] ?? null) && preg_match('/^[a-z]{2}(-[A-Za-z]{2})?$/', $data['language']) === 1 ? $data['language'] : null;
            $occurred = null;
            if (is_string($data['occurred_at'] ?? null)) {
                try {
                    $occurred = Carbon::parse($data['occurred_at']);
                    if ($occurred->diffInMinutes(now(), true) > 24 * 60) {
                        $occurred = null; // informational only, and only within a day of the server clock
                    }
                } catch (\Throwable) {
                    $occurred = null;
                }
            }
            // The hash covers what the browser claims, so an exact retry is recognised and an altered one is not.
            $hash = hash('sha256', json_encode([$eventId, $reference, $mappingId, $path, $campaign], JSON_THROW_ON_ERROR));

            $filtered = $this->botReason($userAgent, $purpose);
            $now = now();
            $clickId = (string) Str::uuid7();
            $row = [
                'id' => $clickId, 'tenant_id' => $tenant->id, 'site_id' => $site->id, 'client_event_id' => $eventId,
                'mapping_id' => $mappingId, 'payload_hash' => DB::raw("decode('{$hash}', 'hex')"), 'event_time' => $now,
                'client_occurred_at' => $occurred, 'received_at' => $now, 'page_path' => $path, 'landing_path' => $landing,
                'referrer_host' => $referrer, 'language' => $language,
                'campaign_json' => json_encode($campaign, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'campaign_params_origin' => $origin, 'collection_mode' => $mode,
                'status' => $filtered !== null ? 'filtered' : 'registered', 'filtered_reason' => $filtered,
            ];

            try {
                $result = DB::transaction(function () use ($row, $site, $tenant, $reference, $mapping, $now, $filtered) {
                    DB::table('growth_click_events')->insert($row);
                    if ($filtered !== null) {
                        return 'filtered';
                    }
                    DB::table('growth_references')->insert([
                        'id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id, 'site_id' => $site->id, 'click_id' => $row['id'],
                        'token_digest' => DB::raw("decode('".Reference::digest($reference)."', 'hex')"),
                        'destination_phone_number_id' => $mapping->phone_number_id,
                        'issued_at' => $now, 'expires_at' => $now->copy()->addDay(),
                    ]);
                    DB::table('growth_site_health')->updateOrInsert(['site_id' => $site->id], ['tenant_id' => $tenant->id, 'last_click_at' => $now]);

                    return 'registered';
                });
            } catch (QueryException $e) {
                if ($e->getCode() !== '23505') {
                    throw $e;
                }

                return $this->duplicate($site, $tenant, $eventId, $hash);
            }

            if ($result === 'filtered') {
                return [202, ['accepted' => false, 'status' => 'filtered']];
            }

            ReconcileWebsiteClick::dispatch($clickId)->afterCommit(); // a message may already be waiting for this code

            return [201, ['accepted' => true, 'click_id' => 'clk_'.substr($clickId, -6), 'status' => 'registered']];
        });
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function duplicate(GrowthSite $site, Tenant $tenant, string $eventId, string $hash): array
    {
        $existing = DB::table('growth_click_events')->where('site_id', $site->id)->where('client_event_id', $eventId)
            ->first(['id', 'status', DB::raw("encode(payload_hash, 'hex') AS h")]);
        if ($existing !== null) {
            if (hash_equals((string) $existing->h, $hash)) {
                return [200, ['accepted' => $existing->status !== 'filtered', 'click_id' => 'clk_'.substr((string) $existing->id, -6), 'status' => $existing->status]];
            }
            $this->error($site, $tenant, 'event_id_conflict');

            return [409, ['error' => 'event_id_conflict']];
        }

        // Same code, another event: only the first is accepted. Never expected (49 bits of randomness).
        Log::warning('Growth Hub: reference collision on a website click.', ['tenant_id' => $tenant->id, 'site_id' => $site->id]);
        Cache::increment('growth:collisions:'.now()->toDateString());
        $this->error($site, $tenant, 'reference_collision');

        return [409, ['error' => 'reference_collision']];
    }

    private function botReason(?string $ua, ?string $purpose): ?string
    {
        if ($purpose !== null && preg_match('/prefetch|prerender|preview/i', $purpose) === 1) {
            return 'prefetch';
        }
        if ($ua === null || trim($ua) === '') {
            return 'no_user_agent';
        }

        return preg_match(self::BOT_UA, $ua) === 1 ? 'bot_user_agent' : null;
    }

    /** A path only: no query, no fragment, no host, limited characters. */
    public static function path(mixed $value): ?string
    {
        if (! is_string($value) || $value === '' || $value[0] !== '/') {
            return null;
        }
        $p = (string) preg_replace('/[?#].*$/s', '', $value);
        $p = (string) preg_replace('/[^\p{L}\p{N}\/\-._~%!$&\'()*+,;=:@]/u', '', $p);

        return mb_substr($p, 0, 512) ?: '/';
    }

    /** Remembered for the site card ("errors in the last 24 h", last error code). */
    private function error(GrowthSite $site, Tenant $tenant, string $code): void
    {
        try {
            $this->context->run($tenant, function () use ($site, $code) {
                $today = now()->toDateString();
                $h = DB::table('growth_site_health')->where('site_id', $site->id)->first(['errors_date', 'errors_today']);
                $count = $h !== null && $h->errors_date === $today ? ((int) $h->errors_today) + 1 : 1;
                DB::table('growth_site_health')->updateOrInsert(['site_id' => $site->id], [
                    'tenant_id' => $site->tenant_id, 'last_error_code' => $code, 'last_error_at' => now(), 'errors_date' => $today, 'errors_today' => $count,
                ]);
            });
        } catch (\Throwable) {
            // health bookkeeping never fails a request
        }
    }
}
