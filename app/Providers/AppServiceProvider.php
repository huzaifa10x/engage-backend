<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Access\Models\Role;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Identity\Models\PlatformAdmin;
use App\Domain\Identity\Models\User;
use App\Domain\Plans\Models\Plan;
use App\Domain\Plans\Models\PlanVersion;
use App\Domain\Platform\Models\ImpersonationSession;
use App\Domain\Tenancy\Models\Invitation;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\WhatsApp\Models\EmbeddedSignupAttempt;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Domain\WhatsApp\Models\WabaAccount;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Short, stable morph keys — class names never leak into the database.
        Relation::enforceMorphMap([
            'user' => User::class,
            'platform_admin' => PlatformAdmin::class,
            'tenant' => Tenant::class,
            'membership' => TenantMembership::class,
            'invitation' => Invitation::class,
            'role' => Role::class,
            'plan' => Plan::class,
            'plan_version' => PlanVersion::class,
            'subscription' => Subscription::class,
            'impersonation' => ImpersonationSession::class,
            'waba_account' => WabaAccount::class,
            'phone_number' => PhoneNumber::class,
            'signup_attempt' => EmbeddedSignupAttempt::class,
        ]);

        // Strict mode minus preventAccessingMissingAttributes: freshly created models only hold the
        // columns they were inserted with, so nullable columns would throw on read after create().
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
        DB::prohibitDestructiveCommands($this->app->isProduction());

        $this->configureRateLimiting();
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            $key = $request->user()?->getAuthIdentifier()
                ? 'u:'.$request->user()->getAuthIdentifier().'|t:'.(Context::get('tenant_id') ?? '-')
                : 'ip:'.$request->ip();

            return Limit::perMinute((int) config('engage.api.rate_limit_per_minute', 300))->by($key);
        });

        RateLimiter::for('admin-login', fn (Request $request) => Limit::perMinute(10)->by('admin:'.$request->ip()));

        RateLimiter::for('auth', function (Request $request) {
            $perMinute = (int) config('engage.api.login_attempts_per_minute', 10);

            return [
                Limit::perMinute($perMinute)->by('ip:'.$request->ip()),
                Limit::perMinute($perMinute)->by('email:'.mb_strtolower((string) $request->input('email'))),
            ];
        });
    }
}
