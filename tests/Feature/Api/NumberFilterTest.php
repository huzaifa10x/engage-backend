<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Access\Models\Role;
use App\Domain\Access\SystemRole;
use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\WhatsApp\Models\PhoneNumber;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

/** CN-02: the number chosen in the top bar filters Dashboard, Campaigns, Leads, Orders and Insights. */
final class NumberFilterTest extends TestCase
{
    use InteractsWithWhatsapp;

    private Tenant $tenant;

    private TenantMembership $owner;

    private PhoneNumber $sales;

    private PhoneNumber $support;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
        $this->tenant = $this->createTenant();
        $this->subscribe($this->tenant, 'pro');
        $this->owner = $this->addMember($this->tenant);
        $this->sales = $this->connectNumber($this->tenant);
        $this->support = $this->connectNumber($this->tenant, wabaId: '102290129340399', phoneNumberId: '106540352242999', display: '+971 58 000 0000');
        // Two chats on the sales number, one on the support number.
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue(['id' => 'wamid.A'], waId: '971501111111', bsuid: null)))->assertOk();
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue(['id' => 'wamid.B'], waId: '971502222222', bsuid: null)))->assertOk();
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue(['id' => 'wamid.C'], waId: '971503333333', bsuid: null, phoneNumberId: '106540352242999'), '102290129340399'))->assertOk();
        $this->actingAsMember($this->owner);
    }

    public function test_dashboard_figures_follow_the_selected_number(): void
    {
        $this->getJson('/api/v1/dashboard/summary')->assertOk()->assertJsonPath('data.conversations_today', 3)->assertJsonPath('data.messages_received_today', 3);
        $this->getJson("/api/v1/dashboard/summary?phone_number_id={$this->sales->id}")->assertOk()->assertJsonPath('data.conversations_today', 2);
        $this->getJson("/api/v1/dashboard/summary?phone_number_id={$this->support->id}")->assertOk()->assertJsonPath('data.messages_received_today', 1);
    }

    public function test_campaigns_follow_the_selected_number(): void
    {
        $this->tenantContext()->run($this->tenant, function (): void {
            foreach ([[$this->sales, 'Sales promo'], [$this->support, 'Support notice']] as [$number, $name]) {
                Campaign::query()->forceCreate(['tenant_id' => $this->tenant->id, 'name' => $name, 'phone_number_id' => $number->id, 'template_name' => 'promo', 'template_language' => 'en', 'status' => 'draft']);
            }
        });

        $this->getJson('/api/v1/campaigns')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/api/v1/campaigns?phone_number_id={$this->support->id}")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Support notice');
    }

    public function test_the_marketing_role_gets_campaigns_templates_and_growth_but_not_settings(): void
    {
        $marketer = $this->addMember($this->tenant, SystemRole::Marketing);
        $this->assertSame('marketing', Role::system(SystemRole::Marketing)->key);
        $this->actingAsMember($marketer);
        $this->getJson('/api/v1/campaigns')->assertOk();
        $this->getJson('/api/v1/templates')->assertOk();
        $this->getJson('/api/v1/tenant/followups')->assertForbidden();
        $this->getJson('/api/v1/analytics/overview')->assertForbidden();
    }

    public function test_dashboard_needs_reply_is_the_inbox_needs_reply(): void
    {
        // One chat whose last customer message came with the imported history: not in Needs reply.
        $this->tenantContext()->run($this->tenant, fn () => Conversation::query()
            ->whereHas('contact', fn ($c) => $c->where('wa_id', '971503333333'))->update(['reply_state' => null, 'needs_reply_since' => null]));

        $inbox = $this->getJson('/api/v1/conversations/counts')->assertOk()->json('data');
        $this->getJson('/api/v1/dashboard/summary')->assertOk()
            ->assertJsonPath('data.needs_reply', $inbox['needs_reply'])
            ->assertJsonPath('data.needs_reply', 2)
            ->assertJsonPath('data.needs_reply_unassigned', 2)
            ->assertJsonPath('data.older_no_reply', 1)
            ->assertJsonPath('data.waiting_for_reply', 2);
    }
}
