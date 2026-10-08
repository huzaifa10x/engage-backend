<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\Billing\Models\SalesLead;
use App\Domain\Identity\Models\User;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

final class DeleteClientTest extends TestCase
{
    use InteractsWithWhatsapp;

    private function exists(string $table, string $column, string $value): bool
    {
        return (bool) $this->tenantContext()->bypass(fn () => DB::table($table)->where($column, $value)->exists());
    }

    public function test_it_previews_first_then_removes_the_login_its_own_workspace_and_nothing_else(): void
    {
        $this->configureMeta();
        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);

        // The person to remove: sole member of "Solo Co" (with a number, contacts, a conversation), and one of two members of "Shared Co".
        $solo = $this->createTenant(['name' => 'Solo Co']);
        $this->subscribe($solo, 'pro');
        $target = $this->addMember($solo);
        $user = User::query()->findOrFail($target->user_id);
        $user->forceFill(['email' => 'TeamDubai103@gmail.com'])->save(); // stored with capitals; matched regardless
        $this->connectNumber($solo);
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue()))->assertOk();

        $shared = $this->createTenant(['name' => 'Shared Co']);
        $this->subscribe($shared, 'pro');
        $colleague = $this->addMember($shared);
        $this->tenantContext()->bypass(fn () => DB::table('tenant_memberships')->insert(array_merge(
            (array) DB::table('tenant_memberships')->where('id', $colleague->id)->first(), ['id' => (string) Str::uuid7(), 'user_id' => $user->id])));
        $this->tenantContext()->run($shared, fn () => Contact::query()->create(['wa_id' => '971500000009', 'source' => 'manual']));

        // Someone else entirely.
        $other = $this->createTenant(['name' => 'Other Co']);
        $bystander = $this->addMember($other);
        SalesLead::query()->create(['name' => 'Team Dubai', 'email' => 'teamdubai103@gmail.com', 'topic' => 'demo']);
        SalesLead::query()->create(['name' => 'Someone Else', 'email' => 'else@example.com', 'topic' => 'demo']);

        // Without --force: a preview, and nothing changes.
        $this->artisan('engage:client:delete', ['email' => 'teamdubai103@gmail.com'])
            ->expectsOutputToContain('Workspace to DELETE: "Solo Co"')
            ->expectsOutputToContain('Workspace to KEEP:   "Shared Co"')
            ->expectsOutputToContain('Preview only')
            ->assertSuccessful();
        $this->assertTrue($this->exists('users', 'id', $user->id));
        $this->assertTrue($this->exists('tenants', 'id', $solo->id));

        // With --force.
        $this->artisan('engage:client:delete', ['email' => 'teamdubai103@gmail.com', '--force' => true, '--no-interaction' => true])->assertSuccessful();

        // The login, its sessions and memberships, and its website inquiry are gone.
        $this->assertFalse($this->exists('users', 'id', $user->id));
        $this->assertFalse($this->exists('tenant_memberships', 'user_id', $user->id));
        $this->assertSame(['else@example.com'], SalesLead::query()->pluck('email')->all());
        // Its own workspace's customer data is gone.
        foreach (['contacts', 'conversations', 'messages', 'phone_numbers', 'waba_accounts'] as $table) {
            $this->assertFalse($this->exists($table, 'tenant_id', $solo->id), "{$table} of the deleted workspace");
        }
        $left = $this->tenantContext()->bypass(fn () => Tenant::query()->find($solo->id));
        $this->assertTrue($left === null || $left->name === 'Deleted workspace', 'the workspace is removed, or only an anonymous shell remains');
        // Meta was told to stop sending this customer's events.
        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), 'subscribed_apps'));

        // The shared workspace and the bystander are untouched.
        $this->assertTrue($this->exists('tenants', 'id', $shared->id));
        $this->assertTrue($this->exists('contacts', 'tenant_id', $shared->id));
        $this->assertTrue($this->exists('tenant_memberships', 'id', $colleague->id));
        $this->assertTrue($this->exists('users', 'id', $bystander->user_id));
        $this->assertTrue($this->exists('tenants', 'id', $other->id));

        // Running it again finds nothing.
        $this->artisan('engage:client:delete', ['email' => 'teamdubai103@gmail.com'])->expectsOutputToContain('Nothing is stored')->assertSuccessful();
    }

    public function test_a_workspace_with_a_live_paid_subscription_is_not_deleted_by_accident(): void
    {
        $tenant = $this->createTenant(['name' => 'Paying Co']);
        $this->subscribe($tenant, 'pro');
        $member = $this->addMember($tenant);
        $email = (string) User::query()->findOrFail($member->user_id)->email;
        $this->tenantContext()->bypass(fn () => DB::table('subscriptions')->where('tenant_id', $tenant->id)->update(['provider' => 'stripe', 'provider_subscription_id' => 'sub_123', 'status' => 'active']));

        $this->artisan('engage:client:delete', ['email' => $email, '--force' => true, '--no-interaction' => true])->expectsOutputToContain('live paid subscription')->assertFailed();
        $this->assertTrue($this->exists('users', 'id', $member->user_id));
        $this->assertTrue($this->exists('tenants', 'id', $tenant->id));

        // Deliberately overridden.
        $this->artisan('engage:client:delete', ['email' => $email, '--force' => true, '--ignore-billing' => true, '--no-interaction' => true])->assertSuccessful();
        $this->assertFalse($this->exists('users', 'id', $member->user_id));
    }
}
