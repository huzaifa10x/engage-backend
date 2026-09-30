<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\WhatsApp;

use App\Application\WhatsApp\EmbeddedSignup;
use App\Domain\WhatsApp\Enums\SignupEvent;
use App\Domain\WhatsApp\Models\EmbeddedSignupAttempt;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\SignupAttemptResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Next.js "Connect WhatsApp" flow:
 *   1. POST /whatsapp/signups                → launch config for FB.login()
 *   2. popup finishes → POST /whatsapp/signups/{id}/complete with code + session-logging data
 *      (send immediately: the code expires in 30 seconds)
 *   3. poll GET /whatsapp/signups/{id} until status = completed | failed
 *   abandoned / error → POST /whatsapp/signups/{id}/cancel
 */
final class SignupController extends Controller
{
    public function store(Request $request, EmbeddedSignup $signup): JsonResponse
    {
        $data = $request->validate(['coexistence' => ['sometimes', 'boolean']]);
        $result = $signup->start((bool) ($data['coexistence'] ?? false));

        return response()->json(['data' => [
            'attempt' => SignupAttemptResource::make($result['attempt']),
            'numbers' => $result['numbers'],
            'launch' => $result['launch'],
        ]], 201);
    }

    public function show(EmbeddedSignupAttempt $attempt): SignupAttemptResource
    {
        return SignupAttemptResource::make($attempt);
    }

    public function complete(Request $request, EmbeddedSignupAttempt $attempt, EmbeddedSignup $signup): SignupAttemptResource
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:2048'],
            'event' => ['required', Rule::enum(SignupEvent::class)],
            'waba_id' => ['required', 'string', 'regex:/^\d{5,32}$/'],
            'phone_number_id' => ['nullable', 'string', 'regex:/^\d{5,32}$/'],
            'business_id' => ['nullable', 'string', 'regex:/^\d{5,32}$/'],
            'meta_user_id' => ['nullable', 'string', 'regex:/^\d{1,32}$/'],
            'session_id' => ['nullable', 'string', 'max:64'],
        ]);

        return SignupAttemptResource::make($signup->complete($attempt, $data));
    }

    public function cancel(Request $request, EmbeddedSignupAttempt $attempt, EmbeddedSignup $signup): SignupAttemptResource
    {
        $data = $request->validate([
            'current_step' => ['nullable', 'string', 'max:64'],
            'error_code' => ['nullable', 'string', 'max:32'],
            'error_message' => ['nullable', 'string', 'max:1000'],
            'session_id' => ['nullable', 'string', 'max:64'],
        ]);

        return SignupAttemptResource::make($signup->cancel($attempt, $data));
    }
}
