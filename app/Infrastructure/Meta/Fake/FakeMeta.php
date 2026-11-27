<?php

declare(strict_types=1);

namespace App\Infrastructure\Meta\Fake;

use App\Domain\Messaging\Jobs\SimulateFakeDelivery;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * LOCAL DEVELOPMENT ONLY (APP_ENV=local + META_FAKE=true): answers Graph API calls in-process so
 * the whole product works offline without Meta credentials — sending messages, uploading media,
 * read receipts. Sent messages then progress sent → delivered → read like real ones.
 * Other HTTP calls are untouched (Laravel executes requests that match no fake pattern).
 */
final class FakeMeta
{
    public static function enabled(): bool
    {
        return app()->environment('local') && (bool) config('engage.meta.fake');
    }

    public static function register(): void
    {
        $host = (string) parse_url((string) config('engage.meta.graph_url'), PHP_URL_HOST);

        Http::fake([$host.'/*' => fn (Request $request) => self::respond($request)]);
    }

    private static function respond(Request $request): PromiseInterface
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        // POST /{phone-number-id}/messages — a send, or a read receipt (/marketing_messages: an MM API send).
        if ((str_ends_with($path, '/messages') || str_ends_with($path, '/marketing_messages')) && $request->method() === 'POST') {
            if (($request['status'] ?? null) === 'read') {
                return Http::response(['success' => true]);
            }

            $wamid = 'wamid.LOCAL'.Str::upper(Str::random(24));
            $to = (string) ($request['to'] ?? '');
            SimulateFakeDelivery::dispatch($wamid)->delay(now()->addSeconds(2));

            return Http::response([
                'messaging_product' => 'whatsapp',
                'contacts' => [array_filter(['input' => $to, 'wa_id' => $to ?: null, 'user_id' => $request['recipient'] ?? null])],
                'messages' => [['id' => $wamid]],
            ]);
        }

        // POST /{phone-number-id}/media — attachment upload.
        if (str_ends_with($path, '/media') && $request->method() === 'POST') {
            return Http::response(['id' => 'local-media-'.Str::lower(Str::random(16))]);
        }

        // GET /{waba-id}/subscribed_apps — our app is subscribed (Refresh / Reconnect webhooks check it).
        if (str_ends_with($path, '/subscribed_apps') && $request->method() === 'GET') {
            return Http::response(['data' => [['whatsapp_business_api_data' => ['id' => (string) config('engage.meta.app_id'), 'name' => 'engageX (local)']]]]);
        }

        // Marketing Messages: local accounts count as onboarded, so the MM API route can be tried offline.
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        if ($request->method() === 'GET' && str_contains((string) ($query['fields'] ?? ''), 'marketing_messages')) {
            return Http::response(['marketing_messages_onboarding_status' => 'ONBOARDED']);
        }
        // Business profile checklist: a complete local profile, except the website, on a verified business.
        if (str_ends_with($path, '/whatsapp_business_profile') && $request->method() === 'GET') {
            return Http::response(['data' => [['about' => 'Local demo', 'description' => 'A local demo business', 'websites' => [],
                'profile_picture_url' => 'https://example.test/profile.jpg', 'vertical' => 'RETAIL']]]);
        }
        if ($request->method() === 'GET' && str_contains((string) ($query['fields'] ?? ''), 'business_verification_status')) {
            return Http::response(['business_verification_status' => 'VERIFIED']);
        }
        // WhatsApp forms (Flows API): created as drafts, updated, published and read back.
        if (str_ends_with($path, '/flows') && $request->method() === 'POST') {
            $id = (string) random_int(100000000000000, 999999999999999);
            Cache::put("fake-meta:flow:{$id}", 'DRAFT', 86400);

            return Http::response(['id' => $id, 'success' => true, 'validation_errors' => []]);
        }
        if (str_ends_with($path, '/assets') && $request->method() === 'POST') {
            return Http::response(['success' => true, 'validation_errors' => []]);
        }
        if ((str_ends_with($path, '/publish') || str_ends_with($path, '/deprecate')) && $request->method() === 'POST') {
            Cache::put('fake-meta:flow:'.basename(dirname('/'.$path)), str_ends_with($path, '/publish') ? 'PUBLISHED' : 'DEPRECATED', 86400);

            return Http::response(['success' => true]);
        }
        if ($request->method() === 'GET' && str_contains((string) ($query['fields'] ?? ''), 'validation_errors')) {
            return Http::response(['id' => basename($path), 'status' => Cache::get('fake-meta:flow:'.basename($path), 'DRAFT'), 'validation_errors' => []]);
        }

        // Welcome message and ice breakers: accepted, and read back as last set.
        if (str_ends_with($path, '/conversational_automation') && $request->method() === 'POST') {
            Cache::put('fake-meta:conversational-automation:'.basename(dirname('/'.$path)), $request->data(), 3600);

            return Http::response(['success' => true]);
        }
        if ($request->method() === 'GET' && ($query['fields'] ?? null) === 'conversational_automation') {
            $set = (array) Cache::get('fake-meta:conversational-automation:'.basename($path), []);

            return Http::response(['id' => basename($path), 'conversational_automation' => ['enable_welcome_message' => (bool) ($set['enable_welcome_message'] ?? false), 'prompts' => $set['prompts'] ?? []]]);
        }
        if (str_ends_with($path, '/onboard_partners_to_mm_lite')) {
            return Http::response(['request_id' => 'local-mm-request']);
        }

        return Http::response(['success' => true]);
    }
}
