<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Admin\ManagePlatformAdmins;
use App\Application\Admin\ManageTenant;
use App\Application\Tenancy\RegisterWorkspace;
use App\Domain\Audit\AuditLogger;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Conversions\ConversionsAccess;
use App\Domain\Conversions\Models\AdClick;
use App\Domain\Conversions\Models\ConversionEvent;
use App\Domain\Conversions\Models\ConversionRule;
use App\Domain\Conversions\Models\MetaDataset;
use App\Domain\Identity\Models\User;
use App\Domain\Integrations\Models\Integration;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Plans\Models\Plan;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Domain\WhatsApp\Models\WabaAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Conversions to Meta, App Review: the separate reviewer workspace on our own business.
 *
 *   engage:conversions:reviewer --create --email=reviewer@… [--workspace="engageX App Review"]
 *       Creates the workspace (Pro, active), its only login (a review account: email and password, no
 *       authenticator or emailed code, no personal accounts), switches Conversions to Meta and Growth
 *       Hub on for it, and prints the password once.
 *   engage:conversions:reviewer [--email=…]
 *       The checklist: what the workspace still needs before App Review (connected test number with
 *       the permission, dataset, card on, rules, an ad-started chat, the test WooCommerce store), with
 *       the values to paste into the App Review instructions.
 *
 * The test number, the ad-started chat and the store are connected by us in the app (Meta's popup and
 * a real ad click cannot be scripted); this command never touches any other workspace.
 */
final class ConversionsReviewer extends Command
{
    public const MARK = 'app_review';

    protected $signature = 'engage:conversions:reviewer
        {--create : Create the reviewer workspace and login}
        {--email= : The reviewer login (a new address used only for App Review)}
        {--workspace=engageX App Review : Workspace name}
        {--name=Meta App Reviewer : The login\'s display name}
        {--plan=pro : Plan key for the workspace}';

    protected $description = 'Create the Meta App Review workspace for Conversions to Meta, or show what it still needs';

    public function handle(TenantContext $context, RegisterWorkspace $register, ManagePlatformAdmins $admins, ManageTenant $tenants, AuditLogger $audit): int
    {
        $tenant = $context->bypass(fn () => Tenant::query()->whereRaw("(settings->'meta_conversions'->>'".self::MARK."')::boolean IS TRUE")->first());

        if ($this->option('create')) {
            if ($tenant !== null) {
                $this->error("The reviewer workspace already exists: {$tenant->name}. Run without --create to see its checklist.");

                return self::FAILURE;
            }
            $email = strtolower(trim((string) $this->option('email')));
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->error('Give the reviewer login with --email (a new address used only for App Review).');

                return self::FAILURE;
            }
            if ($context->bypass(fn () => User::query()->where('email', $email)->exists())) {
                $this->error("{$email} already has a login. Use a new address: the reviewer login must have no other workspaces or personal accounts.");

                return self::FAILURE;
            }

            $password = Str::password(20, symbols: false);
            ['user' => $user, 'tenant' => $tenant] = $register([
                'name' => (string) $this->option('name'), 'email' => $email, 'password' => $password,
                'company_name' => (string) $this->option('workspace'), 'timezone' => 'Asia/Dubai', 'country' => 'AE',
            ]);
            $context->bypass(function () use ($user, $tenant, $admins, $tenants) {
                $user->forceFill(['email_verified_at' => now()])->save();
                $admins->setReviewAccount($user, true, 'Meta App Review: Conversions to Meta (whatsapp_business_manage_events)');
                $tenants->changePlan($tenant, Plan::byKey((string) $this->option('plan'))->activeVersionOrFail(), SubscriptionStatus::Active, null, 'Meta App Review workspace');
                $tenants->setGrowth($tenant, ['enabled' => true], 'Meta App Review workspace: Growth Hub results per ad');
                $tenant->refresh();
                ConversionsAccess::set($tenant, true);
                $settings = (array) $tenant->refresh()->settings;
                $settings['meta_conversions'][self::MARK] = true;
                $tenant->forceFill(['settings' => $settings])->save();
            });
            $context->run($tenant, fn () => $audit->record('conversions.reviewer_workspace_created', $tenant, meta: ['login' => $email]));

            $this->info("Created \"{$tenant->name}\" with one login.");
            $this->line("  Login:    {$email}");
            $this->line("  Password: {$password}");
            $this->warn('The password is shown only this once. Put it in the App Review form; it is not stored anywhere readable.');
            $this->newLine();
        }

        if ($tenant === null) {
            $this->error('No reviewer workspace yet. Create it with --create --email=<reviewer login>.');

            return self::FAILURE;
        }

        return $this->checklist($context, $tenant);
    }

    private function checklist(TenantContext $context, Tenant $tenant): int
    {
        $rows = $context->run($tenant, function () use ($tenant): array {
            $owners = $tenant->memberships()->with('user')->get();
            $waba = WabaAccount::query()->where('status', 'connected')->with('phoneNumbers')->orderByDesc('connected_at')->first();
            $dataset = $waba !== null ? MetaDataset::query()->where('waba_id', $waba->waba_id)->first() : null;
            $click = AdClick::query()->whereNotNull('ctwa_clid')->orderByDesc('clicked_at')->first();
            $contact = $click?->contact_id !== null ? Contact::query()->find($click->contact_id) : null;
            $store = Integration::query()->where('provider', Integration::WOOCOMMERCE)->where('status', 'active')->first();
            $rules = ConversionRule::query()->where('enabled', true)->get();
            $number = $waba?->phoneNumbers->first();

            return [
                ['Single login, review account', $owners->count() === 1 && $owners->first()?->user?->isReviewAccount(), $owners->map(fn ($m) => $m->user?->email)->implode(', ')],
                ['Conversions to Meta switched on', ConversionsAccess::allowed($tenant), ''],
                ['Test number connected', $waba !== null, $number !== null ? (string) ($number->getAttribute('display_phone_number') ?? $number->phone_number_id).' · WABA '.$waba->waba_id : 'Channels › Connect a WhatsApp number'],
                ['Permission whatsapp_business_manage_events', (bool) $waba?->events_permission, $waba === null ? '' : 'Channels › Reconnect to enable, if missing'],
                ['Dataset created, card on', $dataset !== null && $dataset->enabled, $dataset !== null ? 'Dataset '.$dataset->dataset_id : 'Integrations › Meta conversion reporting › switch the account on'],
                ['Purchase rule on', $rules->contains('trigger_type', ConversionRule::ORDER), 'Integrations › Meta conversion reporting › Report purchases'],
                ['Ad-started chat (real ad click)', $click !== null, $click !== null ? 'Ad "'.($click->headline ?? $click->source_id).'" · contact '.($contact?->displayName() ?? '').($contact?->getAttribute('wa_id') ? ' +'.$contact->getAttribute('wa_id') : '') : 'Tap a Click-to-WhatsApp ad for the test number and send a message'],
                ['Test WooCommerce store connected', $store !== null, $store !== null ? (string) $store->name : 'Integrations › Stores › WooCommerce'],
                ['Test event sent', ConversionEvent::query()->where('is_test', true)->where('status', 'sent')->exists(), 'Integrations › Send test event'],
                ['A Purchase sent', ConversionEvent::query()->where('event_name', ConversionRule::PURCHASE)->where('status', 'sent')->exists(),
                    'Order on the test store with the ad chat\'s phone number, paid (processing)'],
            ];
        });

        $this->info("Reviewer workspace: {$tenant->name} ({$tenant->id})");
        $this->table(['Check', 'Ready', 'Detail'], array_map(fn ($r) => [$r[0], $r[1] ? 'yes' : 'NO', $r[2]], $rows));
        $missing = count(array_filter($rows, fn ($r) => ! $r[1]));
        $missing === 0 ? $this->info('Ready for App Review.') : $this->warn("{$missing} item(s) still to do.");

        return self::SUCCESS;
    }
}
