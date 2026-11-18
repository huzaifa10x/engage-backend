<?php

declare(strict_types=1);

namespace App\Http\Requests\PublicV1;

use Illuminate\Foundation\Http\FormRequest;

/** POST /flows/{id}/start: who it starts for, and values the flow can use as {{var.…}}. */
final class StartFlowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'contact_id' => ['required', 'uuid'],
            'vars' => ['nullable', 'array', 'max:30'],
            'vars.*' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, string> only names a flow can read: a letter, then letters, digits or _ */
    public function vars(): array
    {
        return array_filter((array) ($this->validated('vars') ?? []), fn ($v, $k) => is_string($k) && preg_match('/^[A-Za-z][A-Za-z0-9_]{0,39}$/', $k) === 1, ARRAY_FILTER_USE_BOTH);
    }
}
