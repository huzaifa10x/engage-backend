<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Auth;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\PlatformAdmin;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step 1 of 2: password. A successful password check never logs in directly — it parks the
 * admin id in the session and hands over to the TOTP challenge (or first-time enrolment).
 */
final class LoginController extends Controller
{
    public const PENDING = 'admin.login';

    public function create(): Response
    {
        return Inertia::render('auth/Login');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $key = 'admin-login:'.mb_strtolower($data['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.']);
        }

        $admin = PlatformAdmin::query()->where('email', mb_strtolower($data['email']))->first();

        // Hash::check runs even without a match to keep response timing uniform.
        $valid = Hash::check($data['password'], $admin?->getAuthPassword() ?? '$2y$12$invalidinvalidinvalidinvalidinvalidinvalidinvalidinva');

        if (! $admin instanceof PlatformAdmin || ! $valid || ! $admin->isActive()) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();
        $request->session()->put(self::PENDING, ['id' => $admin->id, 'remember' => (bool) ($data['remember'] ?? false), 'at' => time()]);

        return redirect()->route($admin->hasTwoFactor() ? 'admin.two-factor.challenge' : 'admin.two-factor.setup');
    }

    public function destroy(Request $request, AuditLogger $audit): RedirectResponse
    {
        if ($admin = $request->user('admin')) {
            $audit->record('admin.logout', $admin);
        }

        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
