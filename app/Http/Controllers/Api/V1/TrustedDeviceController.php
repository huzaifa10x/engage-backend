<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Identity\LoginVerification;
use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\ValidationException;

/**
 * Remembered devices (Settings › Security): whether this browser is remembered and until when, and
 * "Forget remembered devices", after which every browser must enter an emailed code at its next
 * sign-in. The browser in use stays signed in for the normal 24-hour window.
 */
final class TrustedDeviceController extends Controller
{
    public function show(Request $request, LoginVerification $verification): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $trusted = $verification->trusted($request, $user);
        $remembered = $trusted !== null && $trusted['remembered'];

        return response()->json(['data' => [
            'enabled' => LoginVerification::enabled(),
            'remember_days' => LoginVerification::rememberDays(),
            'this_device' => [
                'remembered' => $remembered,
                'until' => $remembered ? Carbon::createFromTimestamp($trusted['until'])->toIso8601String() : null,
            ],
            'forgotten_at' => $user->getAttribute('login_trust_reset_at')?->toIso8601String(),
        ]]);
    }

    public function destroy(Request $request, LoginVerification $verification, AuditLogger $audit): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if (Context::hasHidden('impersonator_id')) {
            // Support staff signed in as the user cannot change the user's own choices.
            throw ValidationException::withMessages(['preferences' => 'Remembered devices can only be forgotten by the user.']);
        }
        $verification->forgetDevices($user);
        $audit->record('user.remembered_devices_forgotten', $user);
        if ($request->hasSession()) {
            // This browser: signed in for the normal window from now on, no longer remembered.
            LoginVerification::stamp($request, now()->timestamp);
        }

        return $this->show($request, $verification)->withCookie(Cookie::forget(LoginVerification::COOKIE));
    }
}
