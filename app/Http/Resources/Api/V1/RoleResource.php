<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Access\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Role */
final class RoleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'name' => $this->name,
            'is_system' => $this->is_system,
            'permissions' => $this->permissions,
        ];
    }
}
