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
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:190'],
            'website' => ['sometimes', 'nullable', 'url:http,https', 'max:190'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^[+\d][\d\s().-]{5,}$/'],
            'industry' => ['sometimes', 'nullable', 'string', 'max:60'],
            'company_size' => ['sometimes', 'nullable', 'in:1,2-10,11-50,51-200,201-500,500+'],
            'address_line1' => ['sometimes', 'nullable', 'string', 'max:190'],
            'address_line2' => ['sometimes', 'nullable', 'string', 'max:190'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'region' => ['sometimes', 'nullable', 'string', 'max:100'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
        ];
    }
}
