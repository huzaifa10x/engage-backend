<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Chatbots\Models\ChatbotFlow;
use App\Domain\Chatbots\Services\WelcomeAutomation;
use App\Domain\WhatsApp\Enums\PhoneNumberStatus;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Bot settings › Welcome & ice breakers, per number. */
final class ChatbotWelcomeController extends Controller
{
    public function __construct(private readonly WelcomeAutomation $welcome) {}

    public function index(Request $request): JsonResponse
    {
        $check = $request->boolean('check');
        $numbers = PhoneNumber::query()->where('status', PhoneNumberStatus::Connected->value)->orderBy('created_at')->get();
        $flows = ChatbotFlow::query()->where('kind', 'bot')->whereNotNull('published_version')->whereNull('archived_at')
            ->whereIn('phone_number_id', $numbers->modelKeys())->orderBy('name')->get(['id', 'name', 'phone_number_id', 'is_active']);

        return response()->json(['data' => $numbers->map(fn (PhoneNumber $n) => [
            'phone_number_id' => $n->id,
            'display_phone_number' => $n->display_phone_number,
            'verified_name' => $n->verified_name,
            'settings' => WelcomeAutomation::settings($n),
            // Published flows of this number that a welcome or an ice breaker can start (paused ones do not answer).
            'flows' => $flows->where('phone_number_id', $n->id)->values()->map(fn (ChatbotFlow $f) => ['id' => $f->id, 'name' => $f->name, 'live' => $f->is_active]),
            // What WhatsApp shows now, when asked: changes made in WhatsApp Manager show up here.
            'on_meta' => $check ? $this->welcome->onMeta($n) : null,
        ])->values()]);
    }

    public function update(Request $request, PhoneNumber $phoneNumber): JsonResponse
    {
        $data = $request->validate([
            'welcome_enabled' => ['required', 'boolean'],
            'welcome_flow_id' => ['nullable', 'uuid'],
            'ice_breakers' => ['nullable', 'array', 'max:8'],
            'ice_breakers.*.text' => ['nullable', 'string', 'max:200'],
            'ice_breakers.*.flow_id' => ['nullable', 'uuid'],
        ]);

        return response()->json(['data' => $this->welcome->save($phoneNumber, $data)]);
    }
}
