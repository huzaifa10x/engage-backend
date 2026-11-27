<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\WhatsApp;

use App\Application\WhatsApp\ManageChannels;
use App\Application\WhatsApp\MarketingMessages;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Domain\WhatsApp\Models\WabaAccount;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\WabaAccountResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/** Channels screen: connected WhatsApp Business Accounts and their numbers. */
final class AccountController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return WabaAccountResource::collection(
            WabaAccount::query()->with('phoneNumbers')->orderByDesc('connected_at')->get()
        );
    }

    public function refresh(WabaAccount $account, ManageChannels $channels): WabaAccountResource
    {
        return WabaAccountResource::make($channels->refresh($account));
    }

    /** "Reconnect webhooks": subscribe our app to the account's webhooks again. */
    public function reconnectWebhooks(WabaAccount $account, ManageChannels $channels): WabaAccountResource
    {
        return WabaAccountResource::make($channels->reconnectWebhooks($account));
    }

    /** "Register now": register a number on the Cloud API (Meta's 14-day rule). */
    public function registerNumber(WabaAccount $account, PhoneNumber $number, ManageChannels $channels): WabaAccountResource
    {
        abort_unless($number->waba_account_id === $account->id, 404);
        $channels->register($number);

        return WabaAccountResource::make($account->refresh()->load('phoneNumbers'));
    }

    /**
     * Marketing Messages API: check the status with Meta and, when the account is eligible, ask the
     * business to accept the terms. `result`: onboarded | request_sent | awaiting_client | not_eligible.
     */
    public function enrollMarketingMessages(WabaAccount $account, MarketingMessages $mm): JsonResponse
    {
        $result = $mm->enroll($account, 'client_prompt');

        return WabaAccountResource::make($account->refresh()->load('phoneNumbers'))->additional(['meta' => ['result' => $result]])->response();
    }

    public function destroy(Request $request, WabaAccount $account, ManageChannels $channels): Response
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500'], 'confirm' => ['nullable', 'string', 'max:20']]);
        // Audit APP-013: never one click. The person types the last 4 digits of one of the account's
        // numbers (or "disconnect" when it has none) before the numbers stop reaching engageX.
        $answers = $account->phoneNumbers()->pluck('display_phone_number')
            ->map(fn ($n) => substr((string) preg_replace('/\D/', '', (string) $n), -4))->filter()->values();
        $typed = strtolower(trim((string) ($data['confirm'] ?? '')));
        if ($answers->isEmpty() ? $typed !== 'disconnect' : ! $answers->contains($typed)) {
            throw ValidationException::withMessages(['confirm' => $answers->isEmpty()
                ? 'Type "disconnect" to confirm.'
                : 'Type the last 4 digits of the number to confirm.']);
        }
        $channels->disconnect($account, $data['reason'] ?? 'Disconnected by workspace');

        return response()->noContent();
    }
}
