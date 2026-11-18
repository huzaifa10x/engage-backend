<?php

declare(strict_types=1);

namespace App\Http\Requests\PublicV1;

use Illuminate\Foundation\Http\FormRequest;

/** POST /enquiries/{id}/stage: the stage, and the version the caller last read (item 4's check). */
final class MoveEnquiryStageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'stage_id' => ['required', 'uuid'],
            'version' => ['required', 'integer', 'min:0'],
            'reason' => ['nullable', 'string', 'max:200'],
        ];
    }
}
