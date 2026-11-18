<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Domain\Data\DataExports;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST data/exports: what to export. */
final class StartDataExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Owner only: checked by the controller
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['kind' => ['required', 'string', Rule::in(array_keys(DataExports::KINDS))]];
    }
}
