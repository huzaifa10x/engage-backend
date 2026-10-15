<?php

declare(strict_types=1);

namespace App\Http\Controllers\Chatbots;

use App\Domain\Chatbots\Models\ChatbotFlow;
use App\Domain\Chatbots\Services\Chatbots;
use App\Domain\Messaging\Services\ContactIdentity;
use App\Domain\Messaging\Services\ContactResolver;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Incoming webhook" trigger: another system (a booking tool, a form, Zapier) starts a flow for a
 * phone number. The secret token in the URL is the authentication; it can be rotated or removed
 * in the flow's Start block. The flow should begin with a Template block, since the customer may
 * not have written in the last 24 hours.
 *
 *   POST /api/chatbots/hooks/{token}  {"phone": "+971501234567", "name": "Sara", "vars": {"order": "1042"}}
 */
final class ChatbotHookController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function receive(Request $request, string $token, Chatbots $chatbots, ContactResolver $contacts): JsonResponse
    {
        /** @var ?ChatbotFlow $flow */
        $flow = $this->context->bypass(fn () => ChatbotFlow::query()->where('webhook_token', $token)->first());
        $tenant = $flow !== null ? $this->context->bypass(fn () => Tenant::query()->find($flow->tenant_id)) : null;
        if ($flow === null || $tenant === null) {
            return response()->json(['error' => ['code' => 'not_found', 'message' => 'Unknown webhook.']], 404);
        }
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'name' => ['nullable', 'string', 'max:120'],
            'vars' => ['nullable', 'array', 'max:30'],
            'vars.*' => ['nullable'],
        ]);
        $digits = (string) preg_replace('/\D/', '', $data['phone']);
        if (strlen($digits) < 8 || strlen($digits) > 15) {
            return response()->json(['error' => ['code' => 'validation_failed', 'message' => 'Send the phone number with its country code, e.g. +971501234567.']], 422);
        }
        $vars = [];
        foreach ((array) ($data['vars'] ?? []) as $key => $value) {
            if (is_string($key) && preg_match('/^[A-Za-z][A-Za-z0-9_]{0,39}$/', $key) === 1 && (is_scalar($value) || $value === null)) {
                $vars[$key] = is_string($value) ? mb_substr($value, 0, 1000) : $value;
            }
        }

        $result = $this->context->run($tenant, function () use ($flow, $digits, $data, $vars, $chatbots, $contacts): string {
            $flow->refresh();
            $start = $flow->published_version !== null ? ($chatbots->version($flow->id, $flow->published_version)[0] ?? null)?->start() : null;
            if ($start === null || ! $start['config']['webhook']) {
                return 'skipped:webhook_off';
            }
            $contact = $contacts->resolve(new ContactIdentity($digits, null), 'api');
            if ($contact === null) {
                return 'skipped:no_contact';
            }
            if ($contact->trashed()) {
                return 'skipped:deleted_contact';
            }
            if (($data['name'] ?? null) !== null && $contact->name === null) {
                $contact->forceFill(['name' => mb_substr(trim((string) $data['name']), 0, 120)])->save();
            }

            return $chatbots->startFor($flow, $contact, $vars, 'webhook');
        });

        return response()->json(['data' => ['result' => $result]], $result === 'started' ? 202 : 200);
    }
}
