<?php

declare(strict_types=1);

namespace App\Domain\Identity\Security;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\AdminLoginEvent;
use App\Domain\Identity\Models\AdminSession;
use App\Domain\Identity\Models\PlatformAdmin;
use App\Notifications\AdminTwoFactorCodeNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/**
 * Sign-in bookkeeping for platform admins: finishing a login, the login history, the list of
 * active sessions, and one-time email codes for email-based 2FA.
 */
final class AdminSecurity
{
    public const EMAIL_CODE_TTL = 600; // seconds

    public function __construct(private readonly AuditLogger $audit) {}

    public static function twoFactorRequired(): bool
    {
        return (bool) config('engage.admin.two_factor_required', true);
    }

    /** Signs the admin in after every required check passed, and records where it happened. */
    public function completeLogin(Request $request, PlatformAdmin $admin, bool $remember, string $method): void
    {
        Auth::guard('admin')->login($admin, $remember);
        $request->session()->regenerate();

        $admin->forceFill(['last_login_at' => now()])->save();
        $this->audit->record('admin.login', $admin);
        $this->record($request, 'login', $admin, $admin->email, $method);
        $this->touchSession($request, $admin, force: true);
    }

    public function record(Request $request, string $event, ?PlatformAdmin $admin, ?string $email = null, ?string $method = null): void
    {
        AdminLoginEvent::query()->create([
            'platform_admin_id' => $admin?->id,
            'email' => $email !== null ? mb_strtolower(mb_substr($email, 0, 190)) : $admin?->email,
            'event' => $event,
            'method' => $method,
        ] + $this->client($request));
    }

    /**
     * Keeps the row for this browser session current. Returns false when the session was ended
     * remotely (the caller must then sign the browser out).
     */
    public function touchSession(Request $request, PlatformAdmin $admin, bool $force = false): bool
    {
        $hash = hash('sha256', $request->session()->getId());
        $session = AdminSession::query()->where('session_hash', $hash)->first();

        if ($session === null) {
            AdminSession::query()->create(['platform_admin_id' => $admin->id, 'session_hash' => $hash, 'last_active_at' => now()] + $this->client($request));

            return true;
        }
        if ($session->revoked_at !== null || $session->platform_admin_id !== $admin->id) {
            return false;
        }
        if ($force || $session->last_active_at->lt(now()->subMinute())) {
            $session->forceFill(['last_active_at' => now()] + $this->client($request))->save();
        }

        return true;
    }

    public function endSession(Request $request): void
    {
        AdminSession::query()->where('session_hash', hash('sha256', $request->session()->getId()))->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    // ── Email codes ────────────────────────────────────────────────────────────────────────

    /** Emails a fresh 6-digit code. Only its hash is kept, for 10 minutes. */
    public function sendEmailCode(PlatformAdmin $admin, string $purpose = 'sign in'): void
    {
        $code = (string) random_int(100000, 999999);
        Cache::put($this->codeKey($admin), ['hash' => Hash::make($code), 'attempts' => 0], self::EMAIL_CODE_TTL);

        // Sent immediately (not queued): a mail problem must surface now, not after the admin is locked out.
        Notification::route('mail', $admin->email)->notifyNow(new AdminTwoFactorCodeNotification($code, $purpose, (int) (self::EMAIL_CODE_TTL / 60)));
    }

    public function verifyEmailCode(PlatformAdmin $admin, string $code): bool
    {
        $key = $this->codeKey($admin);
        $stored = Cache::get($key);
        if (! is_array($stored) || ($stored['attempts'] ?? 0) >= 5) {
            return false;
        }
        if (! Hash::check(preg_replace('/\D+/', '', $code) ?? '', (string) $stored['hash'])) {
            Cache::put($key, ['attempts' => (int) $stored['attempts'] + 1] + $stored, self::EMAIL_CODE_TTL);

            return false;
        }
        Cache::forget($key); // single use

        return true;
    }

    private function codeKey(PlatformAdmin $admin): string
    {
        return 'admin-2fa-email:'.$admin->id;
    }

    /** @return array{ip: ?string, country: ?string, user_agent: ?string, browser: string, os: string, device: string} */
    private function client(Request $request): array
    {
        $country = strtoupper((string) $request->header('CF-IPCountry'));

        return [
            'ip' => $request->ip(),
            'country' => preg_match('/^[A-Z]{2}$/', $country) === 1 && $country !== 'XX' ? $country : null,
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500) ?: null,
        ] + UserAgent::parse($request->userAgent());
    }
}
