<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\WhatsApp;

use App\Application\WhatsApp\ManageChannels;
use App\Application\WhatsApp\MarketingMessages;
use App\Domain\WhatsApp\Models\WabaAccount;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\WabaAccountResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

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
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $channels->disconnect($account, $data['reason'] ?? 'Disconnected by workspace');

        return response()->noContent();
    }
}
