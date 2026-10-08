<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\WhatsApp\ManageChannels;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Domain\WhatsApp\Enums\WabaStatus;
use App\Domain\WhatsApp\Models\WabaAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Permanently removes a customer login and everything that belongs only to it.
 *
 *   php artisan engage:client:delete someone@example.com            (shows what would be removed)
 *   php artisan engage:client:delete someone@example.com --force    (removes it)
 *
 * What "everything" means:
 *   • the user account, its sessions, password-reset tokens and pending invitations;
 *   • website inquiries sent from that email address;
 *   • every workspace in which this user is the ONLY member: all its contacts, conversations,
 *     messages, templates, campaigns, numbers and settings. Its WhatsApp accounts are disconnected
 *     at Meta first.
 *   • in workspaces that have other members, only this user's membership is removed; the
 *     workspace and its data stay with the remaining members.
 *
 * It refuses to touch a workspace with a live paid subscription (cancel that in Stripe first, or
 * pass --ignore-billing), and never touches Super Admin accounts. There is no undo.
 */
final class DeleteClient extends Command
{
    protected $signature = 'engage:client:delete
        {email : The login email of the customer account to remove}
        {--force : Actually delete (without it, only a preview is shown)}
        {--ignore-billing : Delete workspaces even if they have a live paid subscription}';

    protected $description = 'Permanently delete a customer login and the workspaces only it belongs to (preview unless --force).';

    public function handle(TenantContext $context, ManageChannels $channels): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();
        $leads = (int) DB::table('sales_leads')->whereRaw('lower(email) = ?', [$email])->count();
        $invitations = (int) $context->bypass(fn () => DB::table('invitations')->whereRaw('lower(email) = ?', [$email])->count());

        if ($user === null && $leads === 0 && $invitations === 0) {
            $this->components->info("Nothing is stored for {$email}: no account, no invitations, no website inquiries.");

            return self::SUCCESS;
        }

        // ── What belongs to this person ──────────────────────────────────────────────────────
        $own = $shared = [];
        if ($user !== null) {
            $memberships = $context->bypass(fn () => DB::table('tenant_memberships')->where('user_id', $user->id)->pluck('tenant_id'));
            foreach ($memberships as $tenantId) {
                $tenant = $context->bypass(fn () => Tenant::query()->find($tenantId));
                if ($tenant === null) {
                    continue;
                }
                $others = (int) $context->bypass(fn () => DB::table('tenant_memberships')->where('tenant_id', $tenantId)->where('user_id', '!=', $user->id)->count());
                $others === 0 ? $own[] = $tenant : $shared[] = [$tenant, $others];
            }
        }

        $this->line('');
        $this->line("  <options=bold>{$email}</>");
        $this->line('  Account:            '.($user !== null ? "{$user->name} (created ".$user->getAttribute('created_at')?->toDateString().')' : 'none'));
        $this->line("  Website inquiries:  {$leads}");
        $this->line("  Pending invitations: {$invitations}");
        $blocked = [];
        foreach ($own as $tenant) {
            $counts = $context->bypass(fn () => [
                'contacts' => DB::table('contacts')->where('tenant_id', $tenant->id)->count(),
                'conversations' => DB::table('conversations')->where('tenant_id', $tenant->id)->count(),
                'messages' => DB::table('messages')->where('tenant_id', $tenant->id)->count(),
                'numbers' => DB::table('phone_numbers')->where('tenant_id', $tenant->id)->count(),
            ]);
            $paid = $this->livePaidSubscription($context, $tenant);
            if ($paid && ! $this->option('ignore-billing')) {
                $blocked[] = $tenant->name;
            }
            $this->line(sprintf('  Workspace to DELETE: "%s" — %s contacts, %s conversations, %s messages, %s number(s)%s', $tenant->name,
                number_format($counts['contacts']), number_format($counts['conversations']), number_format($counts['messages']), $counts['numbers'], $paid ? '  <fg=red>[live paid subscription]</>' : ''));
        }
        foreach ($shared as [$tenant, $others]) {
            $this->line(sprintf('  Workspace to KEEP:   "%s" — has %d other member(s); only this user\'s membership is removed', $tenant->name, $others));
        }
        $this->line('');

        if ($blocked !== []) {
            $this->components->error('Stopped: '.implode(', ', $blocked).' has a live paid subscription. Cancel it in Stripe first (otherwise the customer keeps being charged), or add --ignore-billing.');

            return self::FAILURE;
        }
        if (! $this->option('force')) {
            $this->components->warn('Preview only. Nothing was changed. Run again with --force to delete permanently (there is no undo).');

            return self::SUCCESS;
        }
        if ($this->input->isInteractive() && ! $this->confirm("Permanently delete everything listed above for {$email}?", false)) {
            $this->components->info('Cancelled. Nothing was changed.');

            return self::SUCCESS;
        }

        // ── Delete ───────────────────────────────────────────────────────────────────────────
        foreach ($own as $tenant) {
            // Stop Meta sending us this customer's events, and destroy the stored access tokens.
            $context->run($tenant, function () use ($channels): void {
                foreach (WabaAccount::query()->where('status', WabaStatus::Connected->value)->get() as $waba) {
                    try {
                        $channels->disconnect($waba, 'workspace deleted');
                    } catch (Throwable $e) {
                        $this->components->warn("Could not disconnect WhatsApp account {$waba->waba_id} at Meta ({$e->getMessage()}); continuing.");
                    }
                }
            });

            $left = $this->purgeWorkspace($context, $tenant);
            $left === []
                ? $this->line("  Deleted workspace \"{$tenant->name}\" and all its data.")
                : $this->components->warn("Workspace \"{$tenant->name}\": all customer data deleted, but an empty, renamed shell remains because these records are kept by design: ".implode(', ', $left).'.');
        }

        $context->bypass(function () use ($user, $email): void {
            DB::table('sales_leads')->whereRaw('lower(email) = ?', [$email])->delete();
            DB::table('invitations')->whereRaw('lower(email) = ?', [$email])->delete();
            DB::table('password_reset_tokens')->whereRaw('lower(email) = ?', [$email])->delete();
            if ($user !== null) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
                DB::table('impersonation_sessions')->where('user_id', $user->id)->delete();
                DB::table('tenant_memberships')->where('user_id', $user->id)->delete();
                DB::table('users')->where('id', $user->id)->delete();
            }
        });

        $this->components->info("Done. {$email} and its data have been removed.");

        return self::SUCCESS;
    }

    private function livePaidSubscription(TenantContext $context, Tenant $tenant): bool
    {
        return (bool) $context->bypass(fn () => DB::table('subscriptions')->where('tenant_id', $tenant->id)
            ->whereNotNull('provider_subscription_id')->whereIn('status', ['active', 'past_due', 'trialing'])->exists());
    }

    /**
     * Empties every table that holds this workspace's rows (found by their tenant_id column), in
     * whatever order the foreign keys allow, then removes the workspace itself.
     *
     * @return list<string> tables that would not let go (empty when everything was removed)
     */
    private function purgeWorkspace(TenantContext $context, Tenant $tenant): array
    {
        $tables = collect(DB::select("select table_name from information_schema.columns where table_schema = current_schema() and column_name = 'tenant_id'
            and table_name in (select table_name from information_schema.tables where table_schema = current_schema() and table_type = 'BASE TABLE')"))->pluck('table_name')->all();
        $partitions = collect(DB::select('select c.relname from pg_inherits i join pg_class c on c.oid = i.inhrelid'))->pluck('relname')->all();
        $remaining = array_values(array_diff($tables, $partitions));

        $context->bypass(function () use ($tenant, &$remaining): void {
            DB::table('users')->where('last_active_tenant_id', $tenant->id)->update(['last_active_tenant_id' => null]);

            for ($pass = 0; $pass < 12 && $remaining !== []; $pass++) {
                foreach ($remaining as $i => $table) {
                    try {
                        DB::transaction(fn () => DB::table($table)->where('tenant_id', $tenant->id)->delete());
                        unset($remaining[$i]);
                    } catch (Throwable) {
                        // another table still points at these rows: try again on the next pass
                    }
                }
                $remaining = array_values($remaining);
            }

            if ($remaining === []) {
                try {
                    DB::table('tenants')->where('id', $tenant->id)->delete();

                    return;
                } catch (Throwable) {
                    $remaining[] = 'tenants';
                }
            }
            // Something is kept on purpose (for example the append-only audit log): leave no personal
            // trace on the shell that has to stay.
            DB::table('tenants')->where('id', $tenant->id)->update(['name' => 'Deleted workspace', 'slug' => 'deleted-'.substr($tenant->id, 0, 13)]);
        });

        return $remaining;
    }
}
