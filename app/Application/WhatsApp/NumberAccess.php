<?php

declare(strict_types=1);

namespace App\Application\WhatsApp;

use App\Domain\Access\Permission;
use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\WhatsApp\Exceptions\WhatsappException;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Support\Api\ErrorCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Per-number access (blueprint "Roles & number permissions"). The role decides WHAT a member
 * can do; grants decide on WHICH numbers. Owners, Admins and any role with channels.manage hold
 * every number implicitly; everyone else only their user_number_access rows. Every inbox,
 * contact and analytics query must go through scope() — never rely on hiding UI.
 */
final class NumberAccess
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function hasAllNumbers(TenantMembership $membership): bool
    {
        $role = $membership->role;

        if ($role?->systemRole()?->hasAllNumbers()) {
            return true;
        }

        return in_array('*', (array) $role?->getAttribute('permissions'), true)
            || in_array(Permission::ChannelsManage->value, (array) $role?->getAttribute('permissions'), true);
    }

    /** @return list<string>|null internal phone_numbers.id values; null = all numbers */
    public function grantedIds(TenantMembership $membership): ?array
    {
        if ($this->hasAllNumbers($membership)) {
            return null;
        }

        return DB::table('user_number_access')->where('membership_id', $membership->id)->pluck('phone_number_id')->all();
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function scope(Builder $query, TenantMembership $membership, string $column = 'id'): Builder
    {
        $ids = $this->grantedIds($membership);

        return $ids === null ? $query : $query->whereIn($column, $ids);
    }

    public function ensureCanAccess(TenantMembership $membership, PhoneNumber $number): void
    {
        $ids = $this->grantedIds($membership);

        if ($ids !== null && ! in_array($number->id, $ids, true)) {
            throw new WhatsappException('You do not have access to this WhatsApp number.', ErrorCode::NumberAccessDenied, 403);
        }
    }

    /** @param list<string> $phoneNumberIds */
    public function setGrants(TenantMembership $membership, array $phoneNumberIds): void
    {
        $valid = PhoneNumber::query()->whereIn('id', $phoneNumberIds)->pluck('id')->all(); // tenant-scoped
        if (count($valid) !== count(array_unique($phoneNumberIds))) {
            throw ValidationException::withMessages(['phone_number_ids' => 'One or more numbers do not belong to this workspace.']);
        }

        DB::transaction(function () use ($membership, $valid) {
            $before = DB::table('user_number_access')->where('membership_id', $membership->id)->pluck('phone_number_id')->all();

            DB::table('user_number_access')->where('membership_id', $membership->id)->delete();
            DB::table('user_number_access')->insert(array_map(fn (string $id) => [
                'tenant_id' => $membership->tenant_id,
                'membership_id' => $membership->id,
                'phone_number_id' => $id,
                'created_at' => now(),
            ], $valid));

            $this->audit->record('membership.numbers_updated', $membership, before: ['phone_number_ids' => $before], after: ['phone_number_ids' => $valid]);
        });
    }
}
