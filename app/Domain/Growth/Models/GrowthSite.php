<?php

declare(strict_types=1);

namespace App\Domain\Growth\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A client website with the tracker installed (Tracking Setup › Websites). Its WhatsApp numbers are
 * in growth_site_numbers (one mapping per number); its health in growth_site_health.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $domain
 * @property ?string $display_name
 * @property ?string $timezone
 * @property string $site_key
 * @property string $site_key_hash
 * @property ?Carbon $verified_at
 * @property ?string $verification_method
 * @property string $consent_mode
 * @property bool $session_landing_mode
 * @property bool $enabled
 * @property int $config_version
 * @property ?string $challenge_hash
 * @property ?Carbon $challenge_expires_at
 * @property ?Carbon $test_started_at
 * @property ?string $greeting
 * @property ?string $greeting_ar
 * @property ?Carbon $created_at
 */
class GrowthSite extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'growth_sites';

    protected $fillable = ['tenant_id', 'domain', 'display_name', 'timezone', 'site_key', 'site_key_hash', 'verified_at', 'verification_method', 'consent_mode', 'session_landing_mode', 'enabled', 'config_version', 'challenge_hash', 'challenge_expires_at', 'test_started_at', 'greeting', 'greeting_ar'];

    protected $hidden = ['site_key_hash', 'challenge_hash'];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime', 'challenge_expires_at' => 'datetime', 'test_started_at' => 'datetime',
            'session_landing_mode' => 'boolean', 'enabled' => 'boolean', 'config_version' => 'integer',
        ];
    }

    /**
     * The hosts the tracker may run on: the domain and its www. twin.
     *
     * @return list<string>
     */
    public function allowedHosts(): array
    {
        $bare = (string) preg_replace('/^www\./', '', $this->domain);

        return array_values(array_unique([$this->domain, $bare, 'www.'.$bare]));
    }
}
