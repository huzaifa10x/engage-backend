<?php

declare(strict_types=1);

namespace Tests\Feature\Local;

use App\Application\Tenancy\RegisterWorkspace;
use App\Domain\Messaging\Enums\ConsentState;
use App\Domain\Messaging\Enums\MessageOrigin;
use App\Domain\Messaging\Enums\MessageStatus;
use App\Domain\Messaging\Jobs\SimulateFakeDelivery;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\WhatsApp\Models\PhoneNumber;
use Database\Seeders\DemoWorkspaceSeeder;
use Database\Seeders\PlanCatalogSeeder;
use Database\Seeders\SystemRoleSeeder;
use Tests\TestCase;

final class DemoDataTest extends TestCase
{
    private function demoTenant(): Tenant
    {
        $this->seed([SystemRoleSeeder::class, PlanCatalogSeeder::class]);

        return app(RegisterWorkspace::class)([
            'name' => 'Demo Owner', 'email' => 'owner@engage.test', 'password' => 'Password123!',
            'company_name' => 'Demo Workspace', 'timezone' => 'Asia/Dubai', 'country' => 'AE',
        ])['tenant'];
    }

    public function test_demo_workspace_has_a_number_contacts_and_realistic_threads(): void
    {
        $tenant = $this->demoTenant();
        $this->seed(DemoWorkspaceSeeder::class);
        $this->seed(DemoWorkspaceSeeder::class); // idempotent

        $this->tenantContext()->run($tenant, function () {
            $this->assertSame(1, PhoneNumber::query()->count());
            $this->assertSame(12, Contact::query()->count());
            $this->assertSame(8, Conversation::query()->count());

            $sara = Conversation::query()->whereHas('contact', fn ($q) => $q->where('name', 'Sara Ahmed'))->sole();
            $this->assertTrue($sara->isWindowOpen());
            $this->assertSame(1, $sara->unread_count);
            $this->assertNotNull($sara->assigned_membership_id);

            $priya = Conversation::query()->whereHas('contact', fn ($q) => $q->where('name', 'Priya Sharma'))->sole();
            $this->assertFalse($priya->isWindowOpen(), 'last customer message is older than 24h');

            $this->assertSame(ConsentState::OptedOut, Contact::query()->where('name', 'Ali Hassan')->sole()->consent_state);
            $this->assertNull(Contact::query()->where('username', 'layla.designs')->sole()->wa_id);
            $this->assertSame(1, Message::query()->where('status', MessageStatus::Failed)->count());
        });
    }

    public function test_fake_delivery_walks_a_message_to_read(): void
    {
        $tenant = $this->demoTenant();
        $this->seed(DemoWorkspaceSeeder::class);

        $message = $this->tenantContext()->run($tenant, function () {
            $conversation = Conversation::query()->whereHas('contact', fn ($q) => $q->where('name', 'Sara Ahmed'))->sole();

            return Message::query()->create([
                'conversation_id' => $conversation->id, 'phone_number_id' => $conversation->phone_number_id,
                'contact_id' => $conversation->contact_id, 'direction' => Message::OUTBOUND, 'origin' => MessageOrigin::Agent,
                'type' => 'text', 'status' => MessageStatus::Accepted, 'wamid' => 'wamid.LOCALTEST1', 'body' => 'Tomorrow works!',
            ]);
        });

        // Sync queue: the three steps run back to back (delays are ignored).
        $this->tenantContext()->run($tenant, fn () => SimulateFakeDelivery::dispatch('wamid.LOCALTEST1'));

        $this->tenantContext()->run($tenant, function () use ($message) {
            $fresh = Message::query()->findOrFail($message->id);
            $this->assertSame(MessageStatus::Read, $fresh->status);
            $this->assertNotNull($fresh->getAttribute('delivered_at'));
        });
    }
}
