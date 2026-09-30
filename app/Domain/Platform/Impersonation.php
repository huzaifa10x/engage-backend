<?php

declare(strict_types=1);

namespace App\Domain\Platform;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\PlatformAdmin;
use App\Domain\Platform\Exceptions\ImpersonationInvalid;
use App\Domain\Platform\Models\ImpersonationSession;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "Log in as" for support. Flow:
 *   1. Super Admin → start(): one-time token (2 min) + session row, audited on the tenant
 *   2. Browser → FRONTEND_URL/impersonate?token=… → Next.js POSTs /api/v1/auth/impersonation
 *   3. consume(): logs the web guard in as the member, session flagged with the impersonation id
 *   4. Every API request: current() re-validates (not ended, not expired, max 60 min); audit
 *      entries are attributed to the ADMIN with the impersonated user in meta.
 */
final class Impersonation
{
    public const SESSION_KEY = 'impersonation_id';

    public const TOKEN_TTL_SECONDS = 120;

    public const SESSION_TTL_MINUTES = 60;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{session: ImpersonationSession, token: string} */
    public function start(PlatformAdmin $admin, TenantMembership $membership, string $reason, ?string $ip): array
    {
        $token = Str::random(64);

        $session = $this->context->bypass(fn () => DB::transaction(function () use ($admin, $membership, $reason, $ip, $token) {
            $session = ImpersonationSession::query()->create([
                'platform_admin_id' => $admin->getKey(),
                'tenant_id' => $membership->tenant_id,
                'user_id' => $membership->user_id,
                'reason' => $reason,
                'token_hash' => hash('sha256', $token),
                'token_expires_at' => now()->addSeconds(self::TOKEN_TTL_SECONDS),
                'expires_at' => now()->addMinutes(self::SESSION_TTL_MINUTES),
                'ip' => $ip,
            ]);

            $this->audit->record('impersonation.started', $membership, meta: [
                'impersonation_id' => $session->getKey(),
                'reason' => $reason,
                'user_id' => $membership->user_id,
            ], tenantId: $membership->tenant_id);

            return $session;
        }));

        return ['session' => $session, 'token' => $token];
    }

    /** Exchange the one-time token (API side). */
    public function consume(string $token): ImpersonationSession
    {
        return $this->context->bypass(fn () => DB::transaction(function () use ($token) {
            /** @var ImpersonationSession|null $session */
            $session = ImpersonationSession::query()
                ->where('token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->first();

            if ($session === null || $session->consumed_at !== null || $session->token_expires_at->isPast() || $session->ended_at !== null) {
                throw new ImpersonationInvalid;
            }

            $session->forceFill(['consumed_at' => now()])->save();

            return $session->load(['admin', 'user', 'tenant']);
        }));
    }

    /** The live impersonation for this request, or null. Ends expired ones. */
    public function current(Request $request): ?ImpersonationSession
    {
        $id = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;
        if (! is_string($id)) {
            return null;
        }

        /** @var ImpersonationSession|null $session */
        $session = $this->context->bypass(fn () => ImpersonationSession::query()->with('admin')->find($id));

        if ($session === null || ! $session->isLive()) {
            // Never let an ended support session degrade into a normal login as the customer.
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw new ImpersonationInvalid('This support session has ended.');
        }

        // Attribute every audit entry during this request to the admin (AuditLogger reads these).
        Context::addHidden('impersonator_id', $session->platform_admin_id);
        Context::addHidden('impersonation_id', $session->getKey());

        return $session;
    }

    public function end(ImpersonationSession $session): void
    {
        $this->context->bypass(function () use ($session) {
            $session->forceFill(['ended_at' => now()])->save();

            $this->audit->record('impersonation.ended', $session->tenant()->first(), meta: [
                'impersonation_id' => $session->getKey(),
            ], tenantId: $session->tenant_id);
        });
    }
}
