<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Tenancy\SwitchActiveTenant;
use App\Domain\Identity\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SwitchActiveTenantRequest;
use Illuminate\Http\JsonResponse;

final class ActiveTenantController extends Controller
{
    public function update(SwitchActiveTenantRequest $request, SwitchActiveTenant $switch, MeController $me): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $membership = $switch($request, $user, $request->string('tenant_id')->toString());

        return $me->payload($user, $membership->tenant_id);
    }
}
