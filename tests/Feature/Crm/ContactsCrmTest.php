<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Domain\Access\SystemRole;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

final class ContactsCrmTest extends TestCase
{
    private Tenant $tenant;

    private TenantMembership $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
        $this->subscribe($this->tenant, 'pro');
        $this->owner = $this->addMember($this->tenant);
        $this->actingAsMember($this->owner);
    }

    /** @param array<string, mixed> $extra */
    private function contact(string $phone, array $extra = []): string
    {
        return (string) $this->postJson('/api/v1/contacts', ['phone' => $phone] + $extra)->assertCreated()->json('data.id');
    }

    public function test_tags_are_saved_listed_filtered_and_removed_everywhere(): void
    {
        $sara = $this->contact('+971501110001', ['name' => 'Sara', 'tags' => ['VIP', 'Member']]);
        $this->contact('+971501110002', ['name' => 'Omar', 'tags' => ['vip']]); // same tag, different case
        $this->contact('+971501110003', ['name' => 'Lina']);

        $this->getJson("/api/v1/contacts/{$sara}")->assertJsonPath('data.tags', ['VIP', 'Member']);
        $this->getJson('/api/v1/contacts?tag=VIP')->assertOk()->assertJsonCount(2, 'data');

        $tags = $this->getJson('/api/v1/tags')->assertOk()->assertJsonCount(2, 'data');
        $vip = collect($tags->json('data'))->firstWhere('name', 'VIP');
        $this->assertSame(2, $vip['contacts']);

        $this->patchJson("/api/v1/contacts/{$sara}", ['tags' => ['Member']])->assertOk()->assertJsonPath('data.tags', ['Member']);
        $this->postJson('/api/v1/contacts', ['phone' => '+971501110009', 'tags' => ['bad,tag']])->assertStatus(422);

        $this->deleteJson("/api/v1/tags/{$vip['id']}")->assertNoContent();
        $this->getJson('/api/v1/contacts?tag=VIP')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/tags')->assertJsonCount(1, 'data');
    }

    public function test_the_plan_limits_tags_and_custom_fields(): void
    {
        $free = $this->createTenant();
        $this->actingAsMember($this->addMember($free)); // Free: 5 tags, 2 custom fields, no saved segments

        $this->postJson('/api/v1/contacts', ['phone' => '+971501110001', 'tags' => ['a', 'b', 'c', 'd', 'e']])->assertCreated();
        $this->postJson('/api/v1/contacts', ['phone' => '+971501110002', 'tags' => ['a', 'f']])
            ->assertStatus(402)->assertJsonPath('error.code', 'plan_limit_reached');

        $this->postJson('/api/v1/contact-fields', ['label' => 'Membership tier'])->assertCreated()->assertJsonPath('data.key', 'membership_tier');
        $this->postJson('/api/v1/contact-fields', ['label' => 'Renewal date', 'type' => 'date'])->assertCreated();
        $this->postJson('/api/v1/contact-fields', ['label' => 'City'])->assertStatus(402);

        $this->postJson('/api/v1/segments', ['name' => 'VIPs', 'match' => 'all', 'rules' => [['field' => 'tags', 'op' => 'has', 'value' => 'a']]])
            ->assertStatus(403)->assertJsonPath('error.code', 'feature_not_available');
    }

    public function test_a_segment_is_a_live_rule_not_a_stored_list(): void
    {
        $this->postJson('/api/v1/contact-fields', ['label' => 'Tier'])->assertCreated();
        $sara = $this->contact('+971501110001', ['name' => 'Sara', 'tags' => ['VIP'], 'attributes' => ['tier' => 'gold', 'spend' => '4200'], 'opted_in' => true]);
        $omar = $this->contact('+971501110002', ['name' => 'Omar', 'tags' => ['VIP'], 'attributes' => ['tier' => 'silver', 'spend' => '900']]);
        $this->contact('+971501110003', ['name' => 'Lina', 'attributes' => ['spend' => '5000']]);

        $segment = $this->postJson('/api/v1/segments', ['name' => 'VIP big spenders', 'match' => 'all', 'rules' => [
            ['field' => 'tags', 'op' => 'has', 'value' => 'VIP'],
            ['field' => 'attr:spend', 'op' => 'gt', 'value' => 3000],
        ]])->assertCreated()->assertJsonPath('data.counts.matched', 1)->assertJsonPath('data.counts.eligible_marketing', 1);
        $id = $segment->json('data.id');

        $this->getJson("/api/v1/contacts?segment_id={$id}")->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $sara);

        // Change a field and the contact moves in on its own.
        $this->patchJson("/api/v1/contacts/{$omar}", ['attributes' => ['tier' => 'silver', 'spend' => '3500']])->assertOk();
        $this->getJson("/api/v1/contacts?segment_id={$id}")->assertJsonCount(2, 'data');

        // "any" = OR; preview works without saving; consent decides who is eligible.
        $this->postJson('/api/v1/segments/preview', ['match' => 'any', 'rules' => [
            ['field' => 'attr:tier', 'op' => 'equals', 'value' => 'GOLD'],
            ['field' => 'name', 'op' => 'contains', 'value' => 'lin'],
        ]])->assertOk()->assertJsonPath('data.matched', 2)->assertJsonPath('data.eligible_marketing', 1)->assertJsonPath('data.eligible_utility', 2);

        // Unknown fields / operators never reach SQL.
        $this->postJson('/api/v1/segments/preview', ['match' => 'all', 'rules' => [['field' => 'tenant_id; drop table contacts', 'op' => 'is', 'value' => 'x']]])->assertStatus(422);
        $this->postJson('/api/v1/segments/preview', ['match' => 'all', 'rules' => [['field' => 'tags', 'op' => 'gt', 'value' => '1']]])->assertStatus(422);
    }

    public function test_csv_import_creates_updates_and_never_overrides_an_opt_out(): void
    {
        $this->postJson('/api/v1/contact-fields', ['label' => 'City'])->assertCreated();
        $existing = $this->contact('+971501110001', ['name' => 'Sara Ahmed', 'tags' => ['Member']]);
        $optedOut = $this->contact('+971501110002', ['name' => 'Omar']);
        $this->postJson("/api/v1/contacts/{$optedOut}/consent", ['state' => 'opted_out'])->assertOk();

        $csv = "Phone,Name,Email,Tags,City,opted_in\n"
            ."+971 50 111 0001,,sara@example.com,VIP;Member,Dubai,yes\n"   // existing: enriched, name kept
            ."00971501110002,Omar K,,VIP,Sharjah,yes\n"                      // opted out: stays opted out
            ."971501110003,Lina,lina@example.com,Lead,Abu Dhabi,\n"         // new
            ."not-a-number,Bad,,,,\n"                                        // skipped
            ."+971501110003,Lina again,,,,\n";                               // duplicate in file

        $this->post('/api/v1/contacts/import', ['file' => UploadedFile::fake()->createWithContent('contacts.csv', $csv), 'tags' => ['Imported']], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.created', 1)->assertJsonPath('data.updated', 2)->assertJsonPath('data.skipped', 2)
            ->assertJsonPath('data.errors.0.row', 5);

        $this->getJson("/api/v1/contacts/{$existing}")
            ->assertJsonPath('data.name', 'Sara Ahmed')->assertJsonPath('data.email', 'sara@example.com')
            ->assertJsonPath('data.tags', ['Member', 'VIP', 'Imported'])->assertJsonPath('data.attributes.city', 'Dubai')
            ->assertJsonPath('data.consent_state', 'opted_in');
        $this->getJson("/api/v1/contacts/{$optedOut}")->assertJsonPath('data.consent_state', 'opted_out')->assertJsonPath('data.name', 'Omar K');

        $lina = $this->tenantContext()->run($this->tenant, fn () => Contact::query()->where('wa_id', '971501110003')->firstOrFail());
        $this->assertSame('import', $lina->source);
        $this->assertSame(['Lead', 'Imported'], $lina->tags);
        $this->assertSame('unknown', $lina->consent_state->value); // no consent column value, no blanket confirmation

        // A file without a phone column is refused with a clear message.
        $this->post('/api/v1/contacts/import', ['file' => UploadedFile::fake()->createWithContent('x.csv', "Name,Email\nA,a@example.com\n")], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $export = $this->get('/api/v1/contacts/export')->assertOk();
        $this->assertStringContainsString('+971501110003,Lina,lina@example.com,Lead;Imported,unknown,"Abu Dhabi"', $export->streamedContent());
    }

    public function test_crm_data_is_isolated_and_agents_cannot_import(): void
    {
        $this->contact('+971501110001', ['tags' => ['VIP']]);
        $segment = $this->postJson('/api/v1/segments', ['name' => 'VIPs', 'match' => 'all', 'rules' => [['field' => 'tags', 'op' => 'has', 'value' => 'VIP']]])->json('data.id');

        $other = $this->createTenant();
        $this->subscribe($other, 'pro');
        $this->actingAsMember($this->addMember($other));
        $this->getJson('/api/v1/tags')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/segments')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/contacts?segment_id={$segment}")->assertNotFound();
        $this->patchJson("/api/v1/segments/{$segment}", ['name' => 'x', 'match' => 'all', 'rules' => [['field' => 'tags', 'op' => 'has', 'value' => 'a']]])->assertNotFound();

        $this->actingAsMember($this->addMember($this->tenant, SystemRole::Agent));
        $this->post('/api/v1/contacts/import', ['file' => UploadedFile::fake()->createWithContent('c.csv', "phone\n+971501110005\n")], ['Accept' => 'application/json'])->assertForbidden();
        $this->get('/api/v1/contacts/export')->assertForbidden();
    }
}
