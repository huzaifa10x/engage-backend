<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Identity\Models\User;
use App\Domain\Messaging\Enums\MessageOrigin;
use App\Domain\Messaging\Services\ContactIdentity;
use App\Domain\Messaging\Services\ContactResolver;
use App\Domain\Messaging\Services\MessageRecorder;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Domain\WhatsApp\Enums\PhoneNumberStatus;
use App\Domain\WhatsApp\Models\PhoneNumber;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * LOCAL DEVELOPMENT ONLY: pretends a customer sent a WhatsApp message, through the same code
 * path as a real webhook — contact, conversation, 24h window, unread count and live update.
 */
final class DemoInbound extends Command
{
    protected $signature = 'engage:demo:inbound
        {text=Hi! Is this still available? : Message text}
        {--from=971501112233 : Customer phone (digits)}
        {--name=Test Customer : Customer profile name}
        {--workspace=owner@engage.test : Email of a user whose active workspace receives it}';

    protected $description = '[local only] Simulate an incoming WhatsApp message from a customer.';

    public function handle(TenantContext $context, ContactResolver $contacts, MessageRecorder $recorder): int
    {
        if (! app()->environment('local')) {
            $this->components->error('This command only runs in local development.');

            return self::FAILURE;
        }

        $tenant = $context->bypass(function () {
            $user = User::query()->where('email', $this->option('workspace'))->first();

            return $user?->last_active_tenant_id ? Tenant::query()->find($user->last_active_tenant_id) : null;
        });

        if ($tenant === null) {
            $this->components->error('Workspace not found. Run ./dev reset to recreate the demo data.');

            return self::FAILURE;
        }

        return $context->run($tenant, function () use ($contacts, $recorder): int {
            $number = PhoneNumber::query()->where('status', PhoneNumberStatus::Connected)->oldest()->first();
            if ($number === null) {
                $this->components->error('This workspace has no connected WhatsApp number. Run ./dev reset.');

                return self::FAILURE;
            }

            $from = preg_replace('/\D+/', '', (string) $this->option('from')) ?: '971501112233';

            DB::transaction(function () use ($contacts, $recorder, $number, $from) {
                $contact = $contacts->resolve(new ContactIdentity($from, null, profileName: (string) $this->option('name')), 'inbound');
                $recorder->record($number, $contact, [
                    'from' => $from,
                    'id' => 'wamid.LOCALIN'.Str::upper(Str::random(20)),
                    'timestamp' => (string) now()->timestamp,
                    'type' => 'text',
                    'text' => ['body' => (string) $this->argument('text')],
                ], MessageOrigin::Customer);
            });

            $this->components->info("Message from +{$from} delivered to {$number->display_phone_number}. Check the Team Inbox.");

            return self::SUCCESS;
        });
    }
}
