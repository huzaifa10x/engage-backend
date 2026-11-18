<?php

declare(strict_types=1);

namespace App\Http\Requests\PublicV1;

use Illuminate\Foundation\Http\FormRequest;

/** POST or DELETE /contacts/{id}/tags: the tags to add or remove. */
final class ContactTagsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the API key's permission is checked by the route
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'tags' => ['required', 'array', 'min:1', 'max:50'],
            'tags.*' => ['required', 'string', 'max:40'],
        ];
    }

    /** @return list<string> */
    public function tags(): array
    {
        return array_values(array_unique(array_map(fn (string $t) => trim($t), (array) $this->validated('tags'))));
    }
}
