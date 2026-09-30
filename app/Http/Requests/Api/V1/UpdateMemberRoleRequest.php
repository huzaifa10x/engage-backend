<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Domain\Access\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class UpdateMemberRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // `can:team.manage` route middleware
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['role_id' => ['required', 'uuid']];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isEmpty() && $this->role() === null) {
                $validator->errors()->add('role_id', 'The selected role is invalid.');
            }
        }];
    }

    public function role(): ?Role
    {
        return Role::query()->find($this->string('role_id')->toString());
    }
}
