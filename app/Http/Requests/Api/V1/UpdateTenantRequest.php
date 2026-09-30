<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // `can:settings.manage` route middleware
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'timezone' => ['sometimes', 'timezone:all'],
            'locale' => ['sometimes', 'string', 'in:en,ar'],
            'country' => ['sometimes', 'nullable', 'string', 'size:2'],
            'billing_email' => ['sometimes', 'nullable', 'email:rfc', 'max:190'],
        ];
    }
}
