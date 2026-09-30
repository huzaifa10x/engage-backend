<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\WhatsApp;

use App\Application\WhatsApp\NumberAccess;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\TenantContext;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PhoneNumberResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class PhoneNumberController extends Controller
{
    /** Numbers the current member may use — drives the inbox filter and channel switcher. */
    public function index(TenantContext $context, NumberAccess $access): AnonymousResourceCollection
    {
        /** @var TenantMembership $membership */
        $membership = $context->membership();

        return PhoneNumberResource::collection(
            $access->scope(PhoneNumber::query(), $membership->loadMissing('role'))->orderBy('created_at')->get()
        );
    }

    public function show(PhoneNumber $phoneNumber, TenantContext $context, NumberAccess $access): PhoneNumberResource
    {
        $access->ensureCanAccess($context->membership()?->loadMissing('role') ?? abort(403), $phoneNumber);

        return PhoneNumberResource::make($phoneNumber);
    }

    /** GET /team/members/{member}/numbers */
    public function grants(TenantMembership $member, NumberAccess $access): JsonResponse
    {
        $membership = $member->loadMissing('role');

        return response()->json(['data' => [
            'all_numbers' => $access->hasAllNumbers($membership),
            'phone_number_ids' => $access->grantedIds($membership) ?? PhoneNumber::query()->pluck('id')->all(),
        ]]);
    }

    /** PUT /team/members/{member}/numbers */
    public function updateGrants(Request $request, TenantMembership $member, NumberAccess $access): JsonResponse
    {
        $data = $request->validate([
            'phone_number_ids' => ['present', 'array', 'max:100'],
            'phone_number_ids.*' => ['uuid', 'distinct'],
        ]);

        $access->setGrants($member->loadMissing('role'), $data['phone_number_ids']);

        return $this->grants($member, $access);
    }
}
