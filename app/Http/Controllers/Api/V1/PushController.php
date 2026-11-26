<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Identity\LoginVerification;
use App\Domain\Notifications\Jobs\SendPush;
use App\Domain\Notifications\Models\PushSubscription;
use App\Domain\Notifications\Services\PushPreferences;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Infrastructure\Push\WebPush;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

/**
 * Phone notifications for the signed-in person in this workspace: the server's public key, turning
 * this phone on or off, a test notification (counts for "mobile-ready" only once it is tapped), the
 * phones that have notifications on, and what kinds they want.
 */
final class PushController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function show(Request $request): JsonResponse
    {
        $member = $this->context->membership() ?? abort(403);
        $user = $request->user() ?? abort(401);
        $endpoint = (string) $request->query('endpoint', '');
        $devices = PushSubscription::query()->where('membership_id', $member->id)->orderByDesc('created_at')->get();
        $mine = $endpoint !== '' ? $devices->firstWhere('endpoint_hash', PushSubscription::hash($endpoint)) : null;

        return response()->json(['data' => [
            'enabled' => WebPush::fromConfig() !== null,
            'public_key' => (string) config('engage.push.vapid_public_key') ?: null,
            'this_device' => $mine !== null ? $this->device($mine) : null,
            'devices' => $devices->map(fn (PushSubscription $s) => $this->device($s))->values(),
            'preferences' => PushPreferences::describe($user),
        ]]);
    }

    public function subscribe(Request $request): JsonResponse
    {
        $member = $this->context->membership() ?? abort(403);
        $user = $request->user() ?? abort(401);
        abort_if(WebPush::fromConfig() === null, 503, 'Phone notifications are not set up on this server yet.');
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'url', 'starts_with:https://', 'max:1000'],
            'keys.p256dh' => ['required', 'string', 'max:120'],
            'keys.auth' => ['required', 'string', 'max:60'],
            'label' => ['nullable', 'string', 'max:120'],
        ]);
        if (strlen(WebPush::unb64($data['keys']['p256dh'])) !== 65 || strlen(WebPush::unb64($data['keys']['auth'])) !== 16) {
            abort(422, 'These notification keys are not valid.');
        }

        // One row per browser: switching workspace or person on the same phone moves it.
        $subscription = PushSubscription::query()->updateOrCreate(['endpoint_hash' => PushSubscription::hash($data['endpoint'])], [
            'user_id' => $user->getKey(), 'tenant_id' => $member->tenant_id, 'membership_id' => $member->id,
            'endpoint' => $data['endpoint'], 'p256dh' => $data['keys']['p256dh'], 'auth' => $data['keys']['auth'],
            'device_id' => LoginVerification::appDevice($request), 'label' => $data['label'] ?? Str::limit((string) $request->userAgent(), 110, ''),
            'failures' => 0,
        ]);

        return response()->json(['data' => $this->device($subscription)], 201);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $user = $request->user() ?? abort(401);
        $data = $request->validate(['endpoint' => ['nullable', 'string', 'max:1000'], 'id' => ['nullable', 'uuid']]);
        PushSubscription::query()->where('user_id', $user->getKey())
            ->when($data['endpoint'] ?? null, fn ($q, string $e) => $q->where('endpoint_hash', PushSubscription::hash($e)))
            ->when($data['id'] ?? null, fn ($q, string $id) => $q->whereKey($id))
            ->when(! isset($data['endpoint']) && ! isset($data['id']), fn ($q) => $q->whereRaw('false'))
            ->delete();

        return response()->json(['data' => ['ok' => true]]);
    }

    /** A real notification from the server to this phone. Tapping it opens /inbox?n=test&s=… which confirms it. */
    public function test(Request $request): JsonResponse
    {
        $member = $this->context->membership() ?? abort(403);
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:1000']]);
        $subscription = PushSubscription::query()->where('membership_id', $member->id)->where('endpoint_hash', PushSubscription::hash($data['endpoint']))->firstOrFail();
        $subscription->forceFill(['test_sent_at' => now()])->save();
        Bus::dispatchSync(new SendPush($member->id, [
            'title' => 'engageX notifications work ✓',
            'body' => 'Tap this to finish setting up your phone.',
            'url' => "/inbox?n=test&s={$subscription->id}",
            'tag' => 'test',
            'kind' => 'test',
        ], $subscription->id));

        return response()->json(['data' => $this->device($subscription->refresh())]);
    }

    public function tapped(Request $request): JsonResponse
    {
        $member = $this->context->membership() ?? abort(403);
        $data = $request->validate(['id' => ['required', 'uuid']]);
        $subscription = PushSubscription::query()->where('membership_id', $member->id)->whereKey($data['id'])->firstOrFail();
        if ($subscription->test_sent_at !== null && $subscription->test_tapped_at === null) {
            $subscription->forceFill(['test_tapped_at' => now()])->save();
        }

        return response()->json(['data' => $this->device($subscription)]);
    }

    public function preferences(Request $request): JsonResponse
    {
        $user = $request->user() ?? abort(401);
        $data = $request->validate(['message_text' => ['sometimes', 'boolean']] + collect(PushPreferences::CATEGORIES)->mapWithKeys(fn ($m, $k) => [$k => ['sometimes', 'boolean']])->all());
        PushPreferences::update($user, $data);

        return response()->json(['data' => PushPreferences::describe($user->refresh())]);
    }

    /** @return array<string, mixed> */
    private function device(PushSubscription $s): array
    {
        return [
            'id' => $s->id,
            'label' => $s->label,
            'app' => $s->device_id !== null,
            'test_sent_at' => $s->test_sent_at?->toIso8601String(),
            'test_tapped_at' => $s->test_tapped_at?->toIso8601String(),
            'last_sent_at' => $s->last_sent_at?->toIso8601String(),
            'created_at' => $s->created_at?->toIso8601String(),
        ];
    }
}
