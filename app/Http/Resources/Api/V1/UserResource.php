<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
final class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified' => $this->getAttribute('email_verified_at') !== null,
            'locale' => $this->getAttribute('locale'),
            'timezone' => $this->getAttribute('timezone'),
        ];
    }
}
