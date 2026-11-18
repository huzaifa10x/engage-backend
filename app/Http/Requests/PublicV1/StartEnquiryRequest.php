<?php

declare(strict_types=1);

namespace App\Http\Requests\PublicV1;

use Illuminate\Foundation\Http\FormRequest;

/** POST /enquiries: the contact whose current enquiry to return or start. */
final class StartEnquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['contact_id' => ['required', 'uuid']];
    }
}
