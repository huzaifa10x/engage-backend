<?php

declare(strict_types=1);

namespace App\Http\Requests\PublicV1;

use Illuminate\Foundation\Http\FormRequest;

/** GET /enquiries: newest first, optionally one contact's or those in one stage. */
final class ListEnquiriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'contact_id' => ['nullable', 'uuid'],
            'stage_id' => ['nullable', 'uuid'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string'],
        ];
    }
}
