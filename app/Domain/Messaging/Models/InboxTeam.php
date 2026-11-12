<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Tenancy\Models\TenantMembership;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;

/**
 * A team in the shared inbox ("Leasing", "Sales"): a named group of teammates. A conversation can be
 * given to a team (conversations.assigned_team_id); the inbox's own picker then chooses the least busy
 * team member who can reply on that number (InboxTools::pickAgent).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 */
class InboxTeam extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'name'];

    /** @return BelongsToMany<TenantMembership, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(TenantMembership::class, 'inbox_team_members', 'team_id', 'membership_id');
    }

    /** @return list<string> */
    public function memberIds(): array
    {
        return DB::table('inbox_team_members')->where('team_id', $this->id)->pluck('membership_id')->map(fn ($id) => (string) $id)->values()->all();
    }

    /** @param list<string> $membershipIds */
    public function syncMembers(array $membershipIds): void
    {
        DB::table('inbox_team_members')->where('team_id', $this->id)->delete();
        foreach (array_values(array_unique($membershipIds)) as $id) {
            DB::table('inbox_team_members')->insert(['tenant_id' => $this->tenant_id, 'team_id' => $this->id, 'membership_id' => $id]);
        }
    }
}
