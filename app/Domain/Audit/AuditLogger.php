<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\PlatformAdmin;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;

/**
 * Writes "who did what, when, to what" synchronously, inside the caller's transaction, so an
 * action and its audit entry commit or roll back together.
 */
final class AuditLogger
{
    private const REDACTED_KEYS = [
        'password', 'password_confirmation', 'remember_token', 'token', 'token_hash',
        'secret', 'access_token', 'refresh_token', 'two_factor_secret', 'two_factor_recovery_codes', 'pin',
    ];

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $meta
     */
    public function record(
        string $action,
        ?Model $subject = null,
        array $before = [],
        array $after = [],
        array $meta = [],
        ?string $tenantId = null,
    ): AuditLog {
        [$actorType, $actorId] = $this->actor();

        // During a support session the acting person is the admin, not the impersonated user.
        if (is_string($impersonator = Context::getHidden('impersonator_id'))) {
            $meta += ['impersonated_user_id' => $actorId, 'impersonation_id' => Context::getHidden('impersonation_id')];
            [$actorType, $actorId] = ['admin', $impersonator];
        }

        $activeTenantId = $this->context->tenantOrNull()?->getKey();
        $tenantId ??= $activeTenantId ?? $subject?->getAttribute('tenant_id');
        $request = app()->runningInConsole() ? null : request();

        $attributes = [
            'tenant_id' => $tenantId,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'action' => $action,
            'entity_type' => $subject?->getMorphClass(),
            'entity_id' => $subject !== null ? (string) $subject->getKey() : null,
            'before' => $this->redact($before) ?: null,
            'after' => $this->redact($after) ?: null,
            'meta' => $meta ?: null,
            'ip' => $request?->ip(),
            'user_agent' => $request !== null ? mb_substr((string) $request->userAgent(), 0, 512) : null,
            'request_id' => Context::get('request_id'),
        ];

        $write = fn (): AuditLog => AuditLog::query()->create($attributes);

        // Rows outside the active tenant (platform actions, pre-context flows) need platform privileges.
        return $tenantId !== null && $tenantId === $activeTenantId ? $write() : $this->context->bypass($write);
    }

    /** @return array{string, ?string} */
    private function actor(): array
    {
        $user = Auth::user();
        if ($user === null && Auth::guard('admin')->hasUser()) {
            $user = Auth::guard('admin')->user();
        }

        return match (true) {
            $user instanceof PlatformAdmin => ['admin', (string) $user->getKey()],
            $user instanceof User => ['user', (string) $user->getKey()],
            $this->context->userId() !== null => ['user', $this->context->userId()],
            default => ['system', null],
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function redact(array $data): array
    {
        foreach (Arr::dot($data) as $key => $value) {
            $leaf = str_contains($key, '.') ? substr($key, strrpos($key, '.') + 1) : $key;
            if (in_array(strtolower($leaf), self::REDACTED_KEYS, true)) {
                Arr::set($data, $key, '[redacted]');
            }
        }

        return $data;
    }
}
