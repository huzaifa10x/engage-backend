<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Domain\Tenancy\Models\Tenant;
use Tests\TestCase;

/**
 * Filtering contacts on custom fields: the Contacts list (and its export) and a campaign's own
 * audience conditions use the segment rule engine, so values are bound, fields come from this
 * workspace only, and each field type has the conditions that make sense for it.
 */
final class CustomFieldFiltersTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
        $this->subscribe($this->tenant, 'enterprise');
        $this->actingAsMember($this->addMember($this->tenant));

        $this->postJson('/api/v1/contact-fields', ['label' => 'City'])->assertCreated();
        $this->postJson('/api/v1/contact-fields', ['label' => 'Budget', 'type' => 'number'])->assertCreated();
        $this->postJson('/api/v1/contact-fields', ['label' => 'Move in', 'type' => 'date'])->assertCreated();
        $this->postJson('/api/v1/contact-fields', ['label' => 'Interests', 'type' => 'multi_select', 'options' => ['Villas', 'Offices', 'Plots']])->assertCreated();
        $this->postJson('/api/v1/contact-fields', ['label' => 'Newsletter', 'type' => 'boolean'])->assertCreated();

        $this->contact('+971501110001', 'Sara', ['city' => 'Dubai', 'budget' => '2500000', 'move_in' => '2026-12-01', 'interests' => ['Villas', 'Plots'], 'newsletter' => true]);
        $this->contact('+971501110002', 'Omar', ['city' => 'Abu Dhabi', 'budget' => '900000', 'move_in' => '2027-03-15', 'interests' => ['Offices'], 'newsletter' => false]);
        $this->contact('+971501110003', 'Lina', ['city' => 'dubai']);
    }

    /** @param array<string, mixed> $attributes */
    private function contact(string $phone, string $name, array $attributes): void
    {
        $this->postJson('/api/v1/contacts', ['phone' => $phone, 'name' => $name, 'attributes' => $attributes, 'opted_in' => true])->assertCreated();
    }

    /**
     * @param  list<array<string, mixed>>  $rules
     * @return list<string> names, sorted
     */
    private function names(array $rules, string $match = 'all'): array
    {
        $filter = json_encode(['match' => $match, 'rules' => $rules]);
        $names = collect($this->getJson('/api/v1/contacts?'.http_build_query(['filter' => $filter]))->assertOk()->json('data'))->pluck('display_name')->sort()->values()->all();

        return $names;
    }

    public function test_contacts_filter_on_each_type_of_custom_field(): void
    {
        $this->assertSame(['Lina', 'Sara'], $this->names([['field' => 'attr:city', 'op' => 'equals', 'value' => 'Dubai']])); // case-insensitive
        $this->assertSame(['Sara'], $this->names([['field' => 'attr:budget', 'op' => 'gt', 'value' => '1000000']]));
        $this->assertSame(['Sara'], $this->names([['field' => 'attr:move_in', 'op' => 'before', 'value' => '2027-01-01']]));
        $this->assertSame(['Omar'], $this->names([['field' => 'attr:move_in', 'op' => 'after', 'value' => '2027-01-01']]));
        $this->assertSame(['Omar', 'Sara'], $this->names([['field' => 'attr:interests', 'op' => 'includes', 'value' => 'Villas'], ['field' => 'attr:interests', 'op' => 'includes', 'value' => 'Offices']], 'any'));
        $this->assertSame(['Sara'], $this->names([['field' => 'attr:newsletter', 'op' => 'equals', 'value' => 'true']]));
        $this->assertSame(['Lina'], $this->names([['field' => 'attr:budget', 'op' => 'is_empty']]));
        // Combined with the other filters.
        $this->getJson('/api/v1/contacts?'.http_build_query(['q' => 'Sara', 'filter' => json_encode(['rules' => [['field' => 'attr:city', 'op' => 'equals', 'value' => 'Dubai']]])]))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.attributes.city', 'Dubai');

        // The export uses the same filter.
        $csv = $this->get('/api/v1/contacts/export?'.http_build_query(['filter' => json_encode(['rules' => [['field' => 'attr:city', 'op' => 'equals', 'value' => 'Abu Dhabi']]])]))->assertOk()->streamedContent();
        $this->assertStringContainsString('Omar', $csv);
        $this->assertStringNotContainsString('Sara', $csv);
    }

    public function test_bad_conditions_and_other_workspaces_fields_are_refused(): void
    {
        $this->getJson('/api/v1/contacts?filter='.urlencode((string) json_encode(['rules' => [['field' => 'attr:nope', 'op' => 'equals', 'value' => 'x']]])))
            ->assertStatus(422)->assertJsonPath('error.details.fields.filter.0', 'The custom field "nope" does not exist.');
        $this->getJson('/api/v1/contacts?filter='.urlencode((string) json_encode(['rules' => [['field' => 'attr:move_in', 'op' => 'before', 'value' => 'soon']]])))
            ->assertStatus(422);
        $this->getJson('/api/v1/contacts?filter='.urlencode((string) json_encode(['rules' => [['field' => 'password', 'op' => 'equals', 'value' => 'x']]])))
            ->assertStatus(422);

        // Another workspace with the same field key sees only its own contacts.
        $other = $this->createTenant();
        $this->subscribe($other, 'enterprise');
        $this->actingAsMember($this->addMember($other));
        $this->postJson('/api/v1/contact-fields', ['label' => 'City'])->assertCreated();
        $this->assertSame([], $this->names([['field' => 'attr:city', 'op' => 'equals', 'value' => 'Dubai']]));
    }
}
