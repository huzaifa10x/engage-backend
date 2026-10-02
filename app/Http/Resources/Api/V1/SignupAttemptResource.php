<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\WhatsApp\Models\EmbeddedSignupAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EmbeddedSignupAttempt */
final class SignupAttemptResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'flow' => $this->flow,
            'status' => $this->status->value,
            'event' => $this->event,
            'waba_id' => $this->waba_id,
            'phone_number_id' => $this->phone_number_id,
            'waba_account_id' => $this->waba_account_id,
            'steps' => (object) ($this->steps ?? []),
            'error' => $this->error_message ? [
                'code' => $this->error_code,
                'message' => $this->error_message,
                // Present only when the number is still registered with another application.
                ...array_filter(['apps' => $this->session_payload['conflicting_apps'] ?? null], fn ($v) => $v !== null),
                ...array_filter(['same_app' => $this->session_payload['conflict_same_app'] ?? null], fn ($v) => $v !== null),
            ] : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];
    }
}
