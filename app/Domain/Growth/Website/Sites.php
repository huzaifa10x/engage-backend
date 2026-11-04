<?php

declare(strict_types=1);

namespace App\Domain\Growth\Website;

use App\Domain\Growth\Models\GrowthSite;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\WhatsApp\Models\PhoneNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tracking Setup › Websites (Technical Specification v1.2 §4, §5, §8; Blueprint v1.4): a site, the
 * WhatsApp numbers its buttons open (one mapping per number), the public configuration the tracker
 * reads, the "Open my site" check and the site's health. Runs in the workspace's context.
 */
final class Sites
{
    public const COLLECTOR_URL = 'https://e.10xengage.com';

    /** Exact prefilled messages (§1). {reference} is replaced by the browser. */
    public const TEMPLATES = [
        'en' => "Hi, I'd like to know more about your services. (Ref: {reference})",
        'ar' => 'مرحبًا، أود معرفة المزيد عن خدماتكم. (المرجع: {reference})',
    ];

    public const SDK_VERSION = '1.0.0';

    public const PRIVACY_LINE = 'We measure taps on our WhatsApp buttons (which page and campaign brought you) to see which of our pages and ads work. No cookies or personal identifiers are used for this.';

    public static function collectorUrl(): string
    {
        return rtrim((string) (config('engage.growth.collector_url') ?: self::COLLECTOR_URL), '/');
    }

    /** "https://www.Example.ae/path" → "www.example.ae"; null when it is not a public host name. */
    public static function normalizeDomain(string $input): ?string
    {
        $v = mb_strtolower(trim($input));
        $v = (string) preg_replace('~^[a-z][a-z0-9+.-]*://~', '', $v);
        $v = (string) preg_replace('~[/?#].*$~', '', $v);
        $v = (string) preg_replace('/:\d+$/', '', $v);
        $v = rtrim($v, '.');
        if (function_exists('idn_to_ascii') && preg_match('/[^\x00-\x7F]/', $v) === 1) {
            $v = (string) idn_to_ascii($v);
        }

        return WebsiteSource::host($v) !== null && ! in_array($v, ['localhost'], true) && preg_match('/\.(local|internal|test|invalid)$/', $v) !== 1 ? $v : null;
    }

    /**
     * @param  list<string>  $phoneIds
     */
    public function create(Tenant $tenant, string $domain, ?string $name, array $phoneIds): GrowthSite
    {
        $host = self::normalizeDomain($domain);
        if ($host === null) {
            throw new WebsiteException('Enter the website address, for example www.example.ae.');
        }
        $bare = (string) preg_replace('/^www\./', '', $host);
        if (GrowthSite::query()->whereIn('domain', [$bare, 'www.'.$bare])->exists()) {
            throw new WebsiteException('This website is already in Tracking Setup.');
        }
        $limit = $tenant->settings['growth']['max_sites'] ?? null;
        if (is_numeric($limit) && GrowthSite::query()->count() >= (int) $limit) {
            throw new WebsiteException('Your workspace has reached its number of websites.');
        }

        $key = 'pk_site_'.Str::lower(Str::random(24));

        return DB::transaction(function () use ($tenant, $host, $name, $key, $phoneIds) {
            /** @var GrowthSite $site */
            $site = GrowthSite::query()->create([
                'domain' => $host,
                'display_name' => $name !== null && trim($name) !== '' ? mb_substr(trim($name), 0, 120) : null,
                'timezone' => $tenant->timezone ?: 'UTC',
                'site_key' => $key,
                'site_key_hash' => hash('sha256', $key),
                'consent_mode' => 'click_only',
                'session_landing_mode' => true,
                'enabled' => true,
                'config_version' => 1,
            ]);
            DB::table('growth_site_health')->insert(['site_id' => $site->id, 'tenant_id' => $site->tenant_id, 'config_version' => 1]);
            $this->setNumbers($site, $phoneIds);

            return $site->refresh();
        });
    }

    /**
     * @param  array{display_name?: ?string, connected_phone_ids?: list<string>, consent_mode?: string, session_landing_mode?: bool, enabled?: bool}  $changes
     */
    public function update(GrowthSite $site, array $changes): GrowthSite
    {
        return DB::transaction(function () use ($site, $changes) {
            if (array_key_exists('connected_phone_ids', $changes)) {
                $this->setNumbers($site, $changes['connected_phone_ids']);
            }
            $site->fill(array_intersect_key($changes, array_flip(['display_name', 'consent_mode', 'session_landing_mode', 'enabled'])));
            $site->config_version++; // the tracker re-reads its configuration (ETag)
            $site->save();
            DB::table('growth_site_health')->where('site_id', $site->id)->update(['config_version' => $site->config_version]);

            return $site->refresh();
        });
    }

    /**
     * The WhatsApp numbers the site's buttons open. Only Cloud API numbers of this workspace; a
     * number keeps its mapping ID while it stays on the site (so links already rewritten still match).
     *
     * @param  list<string>  $phoneIds
     */
    public function setNumbers(GrowthSite $site, array $phoneIds): void
    {
        $phoneIds = array_values(array_unique(array_filter($phoneIds, 'is_string')));
        $valid = PhoneNumber::query()->whereIn('id', $phoneIds)->pluck('id')->all(); // RLS: this workspace only
        if (count($valid) !== count($phoneIds)) {
            throw new WebsiteException('Choose WhatsApp numbers connected to this workspace.');
        }
        if ($valid === []) {
            throw new WebsiteException('Choose at least one WhatsApp number for this website.');
        }

        DB::table('growth_site_numbers')->where('site_id', $site->id)->whereNotIn('phone_number_id', $valid)->delete();
        $have = DB::table('growth_site_numbers')->where('site_id', $site->id)->pluck('phone_number_id')->all();
        foreach (array_diff($valid, $have) as $id) {
            DB::table('growth_site_numbers')->insert([
                'mapping_id' => (string) Str::uuid7(), 'tenant_id' => $site->tenant_id, 'site_id' => $site->id,
                'phone_number_id' => $id, 'created_at' => now(),
            ]);
        }
    }

    /** @return list<array{mapping_id: string, phone_number_id: string, public_number: string, display_phone_number: ?string, verified_name: ?string, status: string}> */
    public function numbers(GrowthSite $site): array
    {
        return DB::table('growth_site_numbers as m')->join('phone_numbers as p', 'p.id', '=', 'm.phone_number_id')
            ->where('m.site_id', $site->id)->orderBy('m.created_at')
            ->get(['m.mapping_id', 'p.id', 'p.display_phone_number', 'p.verified_name', 'p.status'])
            ->map(fn (object $r) => [
                'mapping_id' => (string) $r->mapping_id,
                'phone_number_id' => (string) $r->id,
                'public_number' => (string) preg_replace('/\D/', '', (string) $r->display_phone_number),
                'display_phone_number' => $r->display_phone_number,
                'verified_name' => $r->verified_name,
                'status' => (string) $r->status,
            ])->values()->all();
    }

    /**
     * Public configuration (§8), advisory only, no secrets. Failure to load it means ordinary,
     * untracked WhatsApp links.
     *
     * @return array<string, mixed>
     */
    public function config(GrowthSite $site, bool $active): array
    {
        return [
            'config_version' => $site->config_version,
            'sdk_min_version' => self::SDK_VERSION,
            'enabled' => $active && $site->enabled,
            'mode' => $site->consent_mode === 'consent' ? 'consent' : 'click_only',
            'session_landing_params_enabled' => $site->session_landing_mode,
            'allowed_hosts' => $site->allowedHosts(),
            'destinations' => array_values(array_map(fn (array $n) => [
                'mapping_id' => $n['mapping_id'],
                'public_number' => $n['public_number'],
                'url_types' => ['wa.me', 'api.whatsapp.com', 'whatsapp'],
            ], array_filter($this->numbers($site), fn (array $n) => $n['public_number'] !== '' && $n['status'] === 'connected'))),
            'templates' => self::TEMPLATES,
            'endpoint' => self::collectorUrl().'/v1/c',
        ];
    }

    /**
     * "Open my site": a short-lived challenge the tracker reports back from the site itself.
     *
     * @return array{url: string, expires_at: string}
     */
    public function startVerification(GrowthSite $site): array
    {
        $challenge = Str::random(32);
        $site->forceFill(['challenge_hash' => hash('sha256', $challenge), 'challenge_expires_at' => now()->addMinutes(15)])->save();

        return ['url' => 'https://'.$site->domain.'/?xg_verify='.$challenge, 'expires_at' => $site->challenge_expires_at?->toIso8601String() ?? ''];
    }

    /** @return array<string, mixed> */
    public function health(GrowthSite $site): array
    {
        $h = DB::table('growth_site_health')->where('site_id', $site->id)->first();
        $since = now()->subDay();
        $accepted = DB::table('growth_click_events')->where('site_id', $site->id)->where('received_at', '>=', $since)->where('status', '!=', 'filtered')->count();
        $filtered = DB::table('growth_click_events')->where('site_id', $site->id)->where('received_at', '>=', $since)->where('status', 'filtered')->count();
        $matched = DB::table('growth_click_events')->where('site_id', $site->id)->where('received_at', '>=', $since)->where('status', 'matched')->count();
        $seen = $h?->sdk_last_seen_at !== null ? Carbon::parse((string) $h->sdk_last_seen_at) : null;
        $errorsToday = $h !== null && $h->errors_date === now()->toDateString() ? (int) $h->errors_today : 0;

        $status = match (true) {
            ! $site->enabled => 'paused',
            $seen === null => 'not_installed',
            $site->verified_at === null => 'awaiting_verification',
            $seen->lt(now()->subHours(48)) => 'not_seen',
            default => 'active',
        };

        return [
            'tracking_status' => $status,
            'sdk_last_seen_at' => $seen?->toIso8601String(),
            'last_click_at' => $h?->last_click_at !== null ? Carbon::parse((string) $h->last_click_at)->toIso8601String() : null,
            'last_match_at' => $h?->last_match_at !== null ? Carbon::parse((string) $h->last_match_at)->toIso8601String() : null,
            'accepted_clicks_24h' => $accepted,
            'matched_24h' => $matched,
            'filtered_24h' => $filtered,
            'errors_24h' => $errorsToday,
            'last_error_code' => $h->last_error_code ?? null,
            'last_error_at' => $h?->last_error_at !== null ? Carbon::parse((string) $h->last_error_at)->toIso8601String() : null,
            'config_version' => $site->config_version,
        ];
    }

    /**
     * The live test after "Test tracking": each stage on its own, from the server's records only.
     *
     * @return array<string, mixed>
     */
    public function testStatus(GrowthSite $site): array
    {
        $start = $site->test_started_at;
        if ($start === null) {
            return ['started_at' => null, 'tracker_seen' => false, 'tap_received' => false, 'message_received' => false, 'matched' => false];
        }
        $h = DB::table('growth_site_health')->where('site_id', $site->id)->first();
        $clicks = DB::table('growth_click_events')->where('site_id', $site->id)->where('received_at', '>=', $start)->where('status', '!=', 'filtered');
        $numbers = DB::table('growth_site_numbers')->where('site_id', $site->id)->pluck('phone_number_id')->all();
        $pending = DB::table('growth_pending_references')->whereIn('destination_phone_number_id', $numbers)->where('received_at', '>=', $start)->exists();
        $evidence = DB::table('growth_evidence')->whereIn('evidence_type', ['website_reference', 'website_unregistered'])
            ->where('created_at', '>=', $start)->whereIn('phone_number_id', $numbers);
        $matched = (clone $evidence)->where('evidence_type', 'website_reference')->where('confidence', 'confirmed')->exists();

        return [
            'started_at' => $start->toIso8601String(),
            'tracker_seen' => $h?->sdk_last_seen_at !== null && Carbon::parse((string) $h->sdk_last_seen_at)->gte($start),
            'tap_received' => (clone $clicks)->exists(),
            'message_received' => $pending || (clone $evidence)->exists(),
            'matched' => $matched,
        ];
    }

    /** The snippet to paste before </head>. */
    public function snippet(GrowthSite $site): string
    {
        return '<script async src="'.self::collectorUrl().'/sdk/v1/e.min.js?key='.$site->site_key.'"></script>';
    }

    /** Google Tag Manager › Custom HTML tag, fired on All Pages. */
    public function gtmSnippet(GrowthSite $site): string
    {
        return "<script>\n(function(){var s=document.createElement('script');s.async=true;\ns.src='".self::collectorUrl().'/sdk/v1/e.min.js?key='.$site->site_key."';\ndocument.head.appendChild(s);})();\n</script>";
    }
}
