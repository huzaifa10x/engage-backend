<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Billing\Models\SalesLead;
use App\Domain\Identity\Enums\PlatformRole;
use App\Notifications\SalesLeadNotification;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class InquiriesTest extends TestCase
{
    private function lead(array $overrides = []): SalesLead
    {
        return SalesLead::query()->create($overrides + ['name' => 'Sara Ahmed', 'email' => 'sara@palmestates.ae', 'company' => 'Palm Estates', 'topic' => 'demo', 'team_size' => '2–5', 'source' => '/demo']);
    }

    public function test_a_website_inquiry_is_stored_emailed_to_the_default_inbox_and_listed_for_the_super_admin(): void
    {
        Notification::fake();
        // No server setting needed: the default inbox is info@10xdigital.ae.
        $this->assertSame('info@10xdigital.ae', config('engage.sales_email'));

        $this->postJson('/api/v1/public/leads', ['name' => 'Omar Khan', 'email' => 'omar@oasis-spa.ae', 'phone' => '+971 50 111 2233', 'company' => 'Oasis Spa', 'topic' => 'contact', 'message' => 'Do you support two numbers?', 'source' => '/contact'])
            ->assertCreated();
        Notification::assertSentOnDemand(SalesLeadNotification::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'info@10xdigital.ae');

        $this->lead();
        $this->actingAsAdmin(PlatformRole::SuperAdmin);
        $this->get('/admin/inquiries')->assertOk()->assertInertia(fn (Assert $page) => $page->component('inquiries/Index')
            ->where('counts.new', 2)->has('inquiries.data', 2)
            ->where('newInquiries', 2)
            ->where('inquiries.data.0.status', 'new'));

        // Search and filters.
        $this->get('/admin/inquiries?q=oasis')->assertInertia(fn (Assert $page) => $page->has('inquiries.data', 1)->where('inquiries.data.0.email', 'omar@oasis-spa.ae')
            ->where('inquiries.data.0.phone', '+971 50 111 2233')->where('inquiries.data.0.message', 'Do you support two numbers?'));
        $this->get('/admin/inquiries?topic=demo')->assertInertia(fn (Assert $page) => $page->has('inquiries.data', 1)->where('inquiries.data.0.company', 'Palm Estates'));
        $this->get('/admin/inquiries?status=closed')->assertInertia(fn (Assert $page) => $page->has('inquiries.data', 0));
    }

    public function test_status_and_notes_are_tracked_and_audited(): void
    {
        $lead = $this->lead();
        $admin = $this->actingAsAdmin(PlatformRole::Support);

        $this->patch("/admin/inquiries/{$lead->id}", ['status' => 'nonsense'])->assertSessionHasErrors('status');
        $this->patch("/admin/inquiries/{$lead->id}", ['status' => 'contacted', 'admin_note' => 'Called, demo booked for Thursday.'])->assertRedirect();

        $lead->refresh();
        $this->assertSame('contacted', $lead->status);
        $this->assertSame('Called, demo booked for Thursday.', $lead->admin_note);
        $this->assertSame($admin->id, $lead->getAttribute('handled_by_admin_id'));
        $this->assertNotNull($lead->handled_at);
        $this->assertNotNull($this->tenantContext()->bypass(fn () => AuditLog::query()->where('action', 'inquiry.updated')->first()));

        $this->get('/admin/inquiries')->assertInertia(fn (Assert $page) => $page->where('counts.new', 0)->where('counts.contacted', 1)->where('newInquiries', 0));
    }

    public function test_export_respects_filters_and_only_permitted_roles_get_in(): void
    {
        $this->lead();
        $this->lead(['name' => '=HYPERLINK("http://x")', 'email' => 'other@example.com', 'company' => 'Other Co', 'topic' => 'contact']);

        $this->actingAsAdmin(PlatformRole::SuperAdmin);
        $csv = $this->get('/admin/inquiries/export?topic=demo')->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8')->streamedContent();
        $this->assertStringContainsString('sara@palmestates.ae', $csv);
        $this->assertStringNotContainsString('other@example.com', $csv);
        // A cell that starts like a formula is neutralised.
        $this->assertStringContainsString("'=HYPERLINK", $this->get('/admin/inquiries/export')->streamedContent());

        // Finance has no business with sales inquiries.
        $lead = SalesLead::query()->firstOrFail();
        $this->actingAsAdmin(PlatformRole::Finance);
        $this->get('/admin/inquiries')->assertForbidden();
        $this->get('/admin/inquiries/export')->assertForbidden();
        $this->patch("/admin/inquiries/{$lead->id}", ['status' => 'closed'])->assertForbidden();
        $this->get('/admin')->assertInertia(fn (Assert $page) => $page->where('newInquiries', 0));
    }

    public function test_possible_spam_is_kept_out_of_the_way_but_never_lost(): void
    {
        $this->lead();
        $this->lead(['name' => 'Bot', 'email' => 'bot@example.com', 'status' => 'spam']);
        $this->actingAsAdmin(PlatformRole::SuperAdmin);

        // The normal list and the "new" badge leave it out; choosing the status shows it.
        $this->get('/admin/inquiries')->assertInertia(fn (Assert $page) => $page->has('inquiries.data', 1)->where('counts.new', 1)->where('counts.spam', 1)->where('newInquiries', 1));
        $this->get('/admin/inquiries?status=spam')->assertInertia(fn (Assert $page) => $page->has('inquiries.data', 1)->where('inquiries.data.0.email', 'bot@example.com'));

        // A real inquiry caught by mistake can be put back.
        $bot = SalesLead::query()->where('status', 'spam')->firstOrFail();
        $this->patch("/admin/inquiries/{$bot->id}", ['status' => 'new'])->assertRedirect();
        $this->get('/admin/inquiries')->assertInertia(fn (Assert $page) => $page->has('inquiries.data', 2));
    }
}
