<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Application\Identity\LoginVerification;
use App\Application\Notifications\WorkspaceMailer;
use App\Domain\Messaging\Models\MemberNotification;
use App\Domain\Messaging\Services\InboxTools;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\TenantContext;
use App\Notifications\TemplateRejectedNotification;
use Illuminate\Support\Facades\Context;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/** Settings › Email notifications: everything on by default, each category can be switched off, OTP never. */
final class EmailPreferencesTest extends TestCase
{
    private Tenant $tenant;

    private TenantMembership $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
        $this->owner = $this->addMember($this->tenant);
    }

    /** @return list<string> subjects of the emails sent to this address */
    private function mailTo(string $email): array
    {
        /** @var iterable<SentMessage> $messages */
        $messages = app('mailer')->getSymfonyTransport()->messages();
        $out = [];
        foreach ($messages as $m) {
            $original = $m->getOriginalMessage();
            if ($original instanceof Email && collect($original->getTo())->contains(fn ($a) => $a->getAddress() === $email)) {
                $out[] = (string) $original->getSubject();
            }
        }

        return $out;
    }

    private function inboxAlert(TenantMembership $member, string $type, string $title): void
    {
        app(TenantContext::class)->set($this->tenant, $member);
        app(InboxTools::class)->notify($member, $type, $title, null, '/inbox', email: true);
    }

    public function test_every_email_is_on_by_default_and_security_is_locked(): void
    {
        $data = $this->actingAsMember($this->owner)->getJson('/api/v1/me/email-preferences')->assertOk()->json('data');
        $this->assertSame(['security', 'inbox_assigned', 'inbox_mentions', 'inbox_alerts', 'whatsapp_numbers', 'templates', 'developer', 'billing'], array_column($data, 'key'));
        foreach ($data as $row) {
            $this->assertTrue($row['enabled'], $row['key']);
            $this->assertSame($row['key'] === 'security', $row['locked'], $row['key']);
        }
    }

    public function test_a_switched_off_category_is_not_emailed_and_the_others_still_are(): void
    {
        $this->actingAsMember($this->owner)->putJson('/api/v1/me/email-preferences', ['preferences' => ['inbox_mentions' => false]])->assertOk()
            ->assertJsonPath('data.2.key', 'inbox_mentions')->assertJsonPath('data.2.enabled', false)->assertJsonPath('data.1.enabled', true);
        $this->assertSame(['inbox_mentions' => false], $this->owner->user()->firstOrFail()->email_preferences);

        $this->inboxAlert($this->owner, 'mention', 'Sara mentioned you in a note');
        $this->inboxAlert($this->owner, 'assigned', 'A new conversation was assigned to you');
        $subjects = implode(' | ', $this->mailTo($this->owner->user()->firstOrFail()->email));
        $this->assertStringNotContainsString('mentioned you', $subjects);
        $this->assertStringContainsString('assigned to you', $subjects);

        // The bell still shows both: the preference is about email only.
        $this->assertSame(2, app(TenantContext::class)->bypass(fn () => MemberNotification::query()->where('membership_id', $this->owner->id)->count()));

        // Back on: emailed again.
        $this->actingAsMember($this->owner)->putJson('/api/v1/me/email-preferences', ['preferences' => ['inbox_mentions' => true]])->assertOk();
        $this->assertNull($this->owner->user()->firstOrFail()->email_preferences);
        $this->inboxAlert($this->owner, 'mention', 'Omar mentioned you in a note');
        $this->assertStringContainsString('Omar mentioned you', implode(' | ', $this->mailTo($this->owner->user()->firstOrFail()->email)));
    }

    public function test_workspace_emails_follow_each_owners_choice(): void
    {
        $other = $this->addMember($this->tenant);
        $this->actingAsMember($this->owner)->putJson('/api/v1/me/email-preferences', ['preferences' => ['templates' => false]])->assertOk();

        app(WorkspaceMailer::class)->toOwners($this->tenant, new TemplateRejectedNotification($this->tenant->name, 'spring_sale', 'en', 'Promotional content'));

        $this->assertSame([], $this->mailTo($this->owner->user()->firstOrFail()->email));
        $this->assertCount(1, $this->mailTo($other->user()->firstOrFail()->email));
    }

    public function test_sign_in_codes_and_security_emails_cannot_be_switched_off(): void
    {
        $this->actingAsMember($this->owner)->putJson('/api/v1/me/email-preferences', ['preferences' => ['security' => false]])->assertStatus(422)
            ->assertSee('Sign-in and security emails, including sign-in codes, cannot be turned off.');
        $this->actingAsMember($this->owner)->putJson('/api/v1/me/email-preferences', ['preferences' => ['marketing' => false]])->assertStatus(422);

        // Even if the stored value said otherwise, the sign-in code is sent.
        $user = $this->owner->user()->firstOrFail();
        $user->forceFill(['email_preferences' => array_fill_keys(['security', 'inbox_assigned', 'inbox_mentions', 'inbox_alerts', 'whatsapp_numbers', 'templates', 'developer', 'billing'], false)])->save();
        app(LoginVerification::class)->send($user);
        $this->assertCount(1, $this->mailTo($user->email));
    }

    public function test_support_staff_signed_in_as_the_user_cannot_change_them(): void
    {
        Context::addHidden('impersonator_id', 'admin-1');
        $this->actingAsMember($this->owner)->putJson('/api/v1/me/email-preferences', ['preferences' => ['billing' => false]])->assertStatus(422);
        Context::forgetHidden('impersonator_id');
        $this->assertNull($this->owner->user()->firstOrFail()->email_preferences);
    }
}
