<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Notifications\EmailPreferences;
use App\Domain\Identity\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Validation\ValidationException;

/**
 * Settings › Email notifications: the signed-in person's own email choices. All on by default;
 * sign-in and security emails (OTP codes included) are always on.
 */
final class EmailPreferenceController extends Controller
{
    public function show(Request $request, EmailPreferences $preferences): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $preferences->list($user)]);
    }

    public function update(Request $request, EmailPreferences $preferences): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if (Context::hasHidden('impersonator_id')) {
            // Support staff signed in as the user cannot change the user's own choices.
            throw ValidationException::withMessages(['preferences' => 'Email notifications can only be changed by the user.']);
        }
        $data = $request->validate(['preferences' => ['required', 'array', 'min:1'], 'preferences.*' => ['required', 'boolean']]);
        $preferences->update($user, array_map(fn ($v) => (bool) $v, $data['preferences']));

        return response()->json(['data' => $preferences->list($user->refresh())]);
    }
}
