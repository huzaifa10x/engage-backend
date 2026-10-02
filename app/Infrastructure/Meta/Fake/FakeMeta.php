<?php

declare(strict_types=1);

namespace App\Infrastructure\Meta\Fake;

use App\Domain\Messaging\Jobs\SimulateFakeDelivery;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
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

        // POST /{phone-number-id}/messages — a send, or a read receipt.
        if (str_ends_with($path, '/messages') && $request->method() === 'POST') {
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

        return Http::response(['success' => true]);
    }
}
