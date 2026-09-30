<?php

declare(strict_types=1);

namespace App\Http\Controllers\Meta;

use App\Domain\Privacy\Jobs\ProcessDataDeletion;
use App\Domain\Privacy\Models\DataDeletionRequest;
use App\Domain\Privacy\SignedRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Meta "Data Deletion Request Callback" and "Deauthorize Callback" (App Settings › Basic /
 * Facebook Login for Business › Settings). Verify, record, queue, answer fast.
 */
final class DataDeletionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $deletion = $this->record($request, 'meta');
        if ($deletion === null) {
            return response()->json(['error' => 'invalid_signed_request'], 400);
        }

        return response()->json([
            'url' => $this->statusUrl($deletion->confirmation_code),
            'confirmation_code' => $deletion->confirmation_code,
        ]);
    }

    public function deauthorize(Request $request): JsonResponse
    {
        return $this->record($request, 'deauthorize') === null
            ? response()->json(['error' => 'invalid_signed_request'], 400)
            : response()->json(['success' => true]);
    }

    /** JSON status for the Next.js status page. */
    public function show(string $code): JsonResponse
    {
        $deletion = DataDeletionRequest::query()->where('confirmation_code', strtoupper($code))->firstOrFail();

        return response()->json(['data' => [
            'confirmation_code' => $deletion->confirmation_code,
            'status' => $deletion->status,
            'requested_at' => $deletion->requested_at->toIso8601String(),
            'completed_at' => $deletion->completed_at?->toIso8601String(),
        ]]);
    }

    /** Server-rendered status page (the URL Meta shows the user). */
    public function page(Request $request): View
    {
        $code = strtoupper((string) $request->query('code', ''));
        $deletion = $code !== '' ? DataDeletionRequest::query()->where('confirmation_code', $code)->first() : null;

        return view('meta.deletion-status', ['code' => $code, 'deletion' => $deletion]);
    }

    private function record(Request $request, string $source): ?DataDeletionRequest
    {
        try {
            $data = SignedRequest::parse((string) $request->input('signed_request', ''), (string) config('engage.meta.app_secret'));
        } catch (InvalidArgumentException $e) {
            Log::warning('Rejected Meta signed_request.', ['source' => $source, 'reason' => $e->getMessage(), 'ip' => $request->ip()]);

            return null;
        }

        $deletion = DataDeletionRequest::query()->create([
            'confirmation_code' => strtoupper(Str::random(12)),
            'source' => $source,
            'meta_user_id' => (string) $data['user_id'],
            'status' => 'received',
            'requested_at' => now(),
        ]);

        ProcessDataDeletion::dispatch($deletion->id);

        return $deletion;
    }

    private function statusUrl(string $code): string
    {
        $base = (string) (config('engage.meta.deletion_status_url') ?: rtrim((string) config('app.url'), '/').'/deletion-status');

        return $base.(str_contains($base, '?') ? '&' : '?').'code='.$code;
    }
}
