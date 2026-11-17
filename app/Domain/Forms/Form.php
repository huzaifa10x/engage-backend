<?php

declare(strict_types=1);

namespace App\Domain\Forms;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A WhatsApp form (Meta "Flow") made in Chatbots & Flows, for one WhatsApp Business Account.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $waba_account_id
 * @property string $name
 * @property string $kind booking | lead | custom
 * @property ?string $current_version_id
 * @property ?string $created_by_membership_id
 * @property ?Carbon $archived_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property ?FormVersion $currentVersion
 */
class Form extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'waba_account_id', 'name', 'kind', 'current_version_id', 'created_by_membership_id', 'archived_at'];

    protected function casts(): array
    {
        return ['archived_at' => 'datetime'];
    }

    /** @return BelongsTo<FormVersion, $this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(FormVersion::class, 'current_version_id');
    }

    /** @return HasMany<FormVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(FormVersion::class)->orderByDesc('version');
    }

    /** The newest published version: what a "Send form" step sends now. */
    public function liveVersion(): ?FormVersion
    {
        return FormVersion::query()->where('form_id', $this->id)->where('meta_status', 'PUBLISHED')->orderByDesc('version')->first();
    }
}
