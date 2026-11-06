<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Admin\ManagePlatformAdmins;
use App\Application\Admin\ManageUserAccounts;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Inertia\Inertia;
use Inertia\Response;

final class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100']]);

        $users = User::query()
            ->with(['memberships.tenant', 'memberships.role'])
            ->when($filters['q'] ?? null, fn (Builder $q, string $term) => $q->where(fn (Builder $w) => $w
                ->where('name', 'ilike', "%{$term}%")->orWhere('email', 'ilike', "%{$term}%")))
            ->orderByDesc('created_at')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'disabled' => $u->isDisabled(),
                'two_factor' => $u->hasTwoFactor(),
                'review_account' => $u->isReviewAccount(),
                'review_account_note' => $u->getAttribute('review_account_note'),
                'last_login_at' => $u->getAttribute('last_login_at')?->toIso8601String(),
                'created_at' => $u->created_at?->toIso8601String(),
                'memberships' => $u->memberships->map(fn (TenantMembership $m) => [
                    'tenant_id' => $m->tenant_id, 'tenant' => $m->tenant?->name, 'role' => $m->role?->name,
                ])->values(),
            ]);

        return Inertia::render('users/Index', [
            'users' => $users,
            'filters' => (object) $filters,
            'totals' => [
                'users' => User::query()->count(),
                'active_7d' => User::query()->where('last_login_at', '>=', now()->subDays(7))->count(),
                'disabled' => User::query()->whereNotNull('disabled_at')->count(),
            ],
        ]);
    }

    public function updateStatus(Request $request, User $user, ManagePlatformAdmins $manage): RedirectResponse
    {
        $data = $request->validate([
            'active' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $manage->setUserStatus($user, (bool) $data['active'], $data['reason']);

        return back()->with('success', $data['active'] ? 'User re-enabled.' : 'User disabled. Their sessions stop working immediately.');
    }

    public function resetTwoFactor(Request $request, User $user, ManagePlatformAdmins $manage): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $manage->resetUserTwoFactor($user, $data['reason']);

        return back()->with('success', "Two-factor sign-in reset for {$user->name}. They sign in with password + emailed code and set up a new authenticator.");
    }

    public function updateReviewAccount(Request $request, User $user, ManagePlatformAdmins $manage): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        $manage->setReviewAccount($user, (bool) $data['enabled'], $data['reason']);

        return back()->with('success', $data['enabled']
            ? "{$user->name} is a review account: email + password only, no authenticator or emailed code."
            : "{$user->name} is a normal account again: the usual sign-in checks apply.");
    }

    public function show(User $user): Response
    {
        $user->load(['memberships.tenant', 'memberships.role']);
        $audit = AuditLog::query()
            ->where(fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('entity_type', $user->getMorphClass())->where('entity_id', $user->id))
                ->orWhere(fn (Builder $w) => $w->where('actor_type', 'user')->where('actor_id', $user->id)))
            ->orderByDesc('created_at')->limit(25)->get()
            ->map(fn (AuditLog $a) => [
                'id' => $a->id, 'action' => $a->getAttribute('action'), 'actor_type' => $a->getAttribute('actor_type'),
                'ip' => $a->getAttribute('ip'), 'created_at' => $a->getAttribute('created_at')?->toIso8601String(),
            ]);

        return Inertia::render('users/Show', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'timezone' => $user->getAttribute('timezone'),
                'email_verified_at' => $user->getAttribute('email_verified_at')?->toIso8601String(),
                'disabled' => $user->isDisabled(),
                'two_factor' => $user->hasTwoFactor(),
                'two_factor_confirmed_at' => $user->getAttribute('two_factor_confirmed_at')?->toIso8601String(),
                'two_factor_required' => $user->twoFactorRequired(),
                'review_account' => $user->isReviewAccount(),
                'review_account_note' => $user->getAttribute('review_account_note'),
                'sessions_revoked_at' => $user->getAttribute('sessions_revoked_at')?->toIso8601String(),
                'last_login_at' => $user->getAttribute('last_login_at')?->toIso8601String(),
                'created_at' => $user->created_at?->toIso8601String(),
            ],
            'memberships' => $user->memberships->map(fn (TenantMembership $m) => [
                'id' => $m->id, 'tenant_id' => $m->tenant_id, 'tenant' => $m->tenant?->name, 'role' => $m->role?->name, 'status' => $m->status,
                'joined_at' => $m->created_at?->toIso8601String(),
            ])->values(),
            'audit' => $audit,
        ]);
    }

    public function update(Request $request, User $user, ManageUserAccounts $accounts): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:190'],
            'timezone' => ['nullable', 'string', 'timezone:all'],
            'email_verified' => ['required', 'boolean'],
        ]);
        $accounts->updateProfile($user, $data);

        return back()->with('success', 'Details saved.');
    }

    public function updatePassword(Request $request, User $user, ManageUserAccounts $accounts): RedirectResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'max:200', PasswordRule::min(10)->mixedCase()->numbers()],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        $accounts->setPassword($user, $data['password'], $data['reason']);

        return back()->with('success', "New password set. {$user->name} was signed out everywhere and told by email.");
    }

    public function sendPasswordReset(User $user, ManageUserAccounts $accounts): RedirectResponse
    {
        $accounts->sendPasswordResetLink($user);

        return back()->with('success', "Password reset link sent to {$user->email}.");
    }

    public function verifyEmail(User $user, ManageUserAccounts $accounts): RedirectResponse
    {
        $accounts->markEmailVerified($user);

        return back()->with('success', 'Email marked as verified.');
    }

    public function signOut(Request $request, User $user, ManageUserAccounts $accounts): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $accounts->signOutEverywhere($user, $data['reason']);

        return back()->with('success', "{$user->name} was signed out on every device.");
    }
}
