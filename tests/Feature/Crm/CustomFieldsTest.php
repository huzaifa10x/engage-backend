<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Domain\Crm\Models\ContactField;
use App\Domain\Tenancy\Models\Tenant;
use Tests\TestCase;

final class CustomFieldsTest extends TestCase
{
    private function workspaceOn(string $plan): Tenant
    {
        $tenant = $this->createTenant();
        if ($plan !== 'free') {
            $this->subscribe($tenant, $plan);
        }
        $this->actingAsMember($this->addMember($tenant));

        return $tenant;
    }

    private function fill(Tenant $tenant, int $count): void
    {
        $this->tenantContext()->run($tenant, function () use ($count) {
            for ($i = 1; $i <= $count; $i++) {
                ContactField::query()->create(['key' => "f{$i}", 'label' => "Field {$i}", 'type' => 'text']);
            }
        });
    }

    public function test_each_plan_has_its_own_number_of_custom_fields(): void
    {
        foreach (['free' => 5, 'starter' => 25, 'growth' => 100] as $plan => $limit) {
            $tenant = $this->workspaceOn($plan);
            $this->fill($tenant, $limit - 1);
            $this->postJson('/api/v1/contact-fields', ['label' => 'Last one'])->assertCreated();
            $this->postJson('/api/v1/contact-fields', ['label' => 'One too many'])->assertStatus(402)->assertJsonPath('error.code', 'plan_limit_reached');
            $this->getJson('/api/v1/contact-fields')->assertJsonPath('meta.limit', $limit)->assertJsonPath('meta.used', $limit)
                ->assertJsonPath('meta.advanced', false)->assertJsonPath('meta.types', ['text', 'number', 'date']);
            $this->app['auth']->forgetGuards();
        }

        // Pro: unlimited, within fair use.
        $pro = $this->workspaceOn('pro');
        $this->getJson('/api/v1/contact-fields')->assertJsonPath('meta.limit', null)->assertJsonPath('meta.fair_use', 500);
        $this->fill($pro, 499);
        $this->postJson('/api/v1/contact-fields', ['label' => 'Number 500'])->assertCreated();
        $this->postJson('/api/v1/contact-fields', ['label' => 'Number 501'])->assertStatus(422)->assertJsonPath('error.details.fields.label.0', fn (string $m) => str_contains($m, 'fair-use'));
    }

    public function test_extra_types_and_rules_are_for_enterprise(): void
    {
        $this->workspaceOn('growth');
        $this->postJson('/api/v1/contact-fields', ['label' => 'Plan', 'type' => 'select', 'options' => ['Gold', 'Silver']])->assertStatus(422)->assertJsonValidationErrors('type', 'error.details.fields');
        $this->postJson('/api/v1/contact-fields', ['label' => 'Code', 'rules' => ['required' => true]])->assertStatus(422)->assertJsonValidationErrors('rules', 'error.details.fields');
        $this->app['auth']->forgetGuards();

        $this->workspaceOn('enterprise');
        $this->getJson('/api/v1/contact-fields')->assertJsonPath('meta.limit', null)->assertJsonPath('meta.advanced', true)->assertJsonPath('meta.fair_use', null);
        $this->postJson('/api/v1/contact-fields', ['label' => 'Tier', 'type' => 'select', 'options' => ['Gold']])->assertStatus(422); // at least 2 choices
        $this->postJson('/api/v1/contact-fields', ['label' => 'Tier', 'type' => 'select', 'options' => ['Gold', 'Silver', 'Gold'], 'rules' => ['required' => true]])->assertCreated()
            ->assertJsonPath('data.options', ['Gold', 'Silver'])->assertJsonPath('data.rules.required', true);
        $this->postJson('/api/v1/contact-fields', ['label' => 'Age', 'type' => 'number', 'rules' => ['min' => 18, 'max' => 120]])->assertCreated();
        $this->postJson('/api/v1/contact-fields', ['label' => 'Member code', 'rules' => ['pattern' => 'M-[0-9]{4}']])->assertCreated();
        $this->postJson('/api/v1/contact-fields', ['label' => 'Bad', 'rules' => ['pattern' => '([a-z']])->assertStatus(422);
        $this->postJson('/api/v1/contact-fields', ['label' => 'Interests', 'type' => 'multi_select', 'options' => ['Villas', 'Offices', 'Plots']])->assertCreated();
        $this->postJson('/api/v1/contact-fields', ['label' => 'Website', 'type' => 'url'])->assertCreated();
        $this->postJson('/api/v1/contact-fields', ['label' => 'Newsletter', 'type' => 'boolean'])->assertCreated();

        // Values are checked against every rule, each problem reported on its own field.
        $bad = $this->postJson('/api/v1/contacts', ['phone' => '+971501110001', 'attributes' => [
            'age' => '12', 'member_code' => 'X-1', 'interests' => ['Boats'], 'website' => 'not a url', 'newsletter' => 'maybe',
        ]])->assertStatus(422);
        $this->assertEqualsCanonicalizing(['attributes.tier', 'attributes.age', 'attributes.member_code', 'attributes.interests', 'attributes.website', 'attributes.newsletter'], array_keys($bad->json('error.details.fields')));
        $this->assertSame('Tier is required.', $bad->json('error.details.fields')['attributes.tier'][0]);

        $created = $this->postJson('/api/v1/contacts', ['phone' => '+971501110001', 'attributes' => [
            'tier' => 'Gold', 'age' => '34', 'member_code' => 'M-0042', 'interests' => ['Villas', 'Plots'], 'website' => 'https://palm.example', 'newsletter' => 'yes',
            'from_api' => 'kept as sent',
        ]])->assertCreated()->assertJsonPath('data.attributes.age', 34)->assertJsonPath('data.attributes.newsletter', true)
            ->assertJsonPath('data.attributes.interests', ['Villas', 'Plots'])->assertJsonPath('data.attributes.from_api', 'kept as sent');
        $id = $created->json('data.id');

        // Editing: the required field must stay filled.
        $this->patchJson("/api/v1/contacts/{$id}", ['attributes' => ['age' => '40']])->assertStatus(422)->assertJsonValidationErrors('attributes.tier', 'error.details.fields');
        $this->patchJson("/api/v1/contacts/{$id}", ['name' => 'Sara'])->assertOk(); // attributes untouched: nothing to re-check
        $this->patchJson("/api/v1/contacts/{$id}", ['attributes' => ['tier' => 'Silver', 'age' => 40]])->assertOk()->assertJsonPath('data.attributes.tier', 'Silver');
    }

    public function test_fields_can_be_renamed_and_their_values_are_checked_on_every_plan(): void
    {
        $this->workspaceOn('starter');
        $field = $this->postJson('/api/v1/contact-fields', ['label' => 'Spend', 'type' => 'number'])->assertCreated()->json('data');
        $this->postJson('/api/v1/contact-fields', ['label' => 'Renewal', 'type' => 'date'])->assertCreated();

        $this->patchJson("/api/v1/contact-fields/{$field['id']}", ['label' => 'Total spend'])->assertOk()
            ->assertJsonPath('data.label', 'Total spend')->assertJsonPath('data.key', 'spend')->assertJsonPath('data.type', 'number');
        $this->patchJson("/api/v1/contact-fields/{$field['id']}", ['rules' => ['min' => 0]])->assertStatus(422); // rules: Enterprise

        $this->postJson('/api/v1/contacts', ['phone' => '+971501110002', 'attributes' => ['spend' => 'a lot', 'renewal' => '31/12/2026']])->assertStatus(422)
            ->assertJsonValidationErrors(['attributes.spend', 'attributes.renewal'], 'error.details.fields');
        $this->postJson('/api/v1/contacts', ['phone' => '+971501110002', 'attributes' => ['spend' => '4200.50', 'renewal' => '2026-12-31', 'note' => '']])->assertCreated()
            ->assertJsonPath('data.attributes.spend', 4200.5)->assertJsonPath('data.attributes.renewal', '2026-12-31');
    }
}
