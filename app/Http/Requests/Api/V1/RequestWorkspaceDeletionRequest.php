<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/** POST tenant/deletion: the workspace's name, typed to confirm. */
final class RequestWorkspaceDeletionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Owner only: checked by the controller
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['confirm' => ['required', 'string', 'max:200']];
    }
}
