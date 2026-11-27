<?php

declare(strict_types=1);

namespace Tests\Feature\WhatsApp;

use App\Application\WhatsApp\ManageChannels;
use App\Domain\Access\SystemRole;
use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Messaging\Models\MemberNotification;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\WhatsApp\Exceptions\WhatsappException;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Domain\WhatsApp\Models\WabaAccount;
use App\Notifications\NumberQualityNotification;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

/**
 * Meta's error code 200 reads as plain words with a "Reconnect" action, and a drop in a number's
 * standing (quality Yellow or Red, flagged, a lower messaging limit) reaches the workspace's Owners.
 */
final class PermissionErrorAndQualityAlertTest extends TestCase
{
    use InteractsWithWhatsapp;

    private Tenant $tenant;

    private TenantMembership $owner;

    private TenantMembership $agent;

    private PhoneNumber $number;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
        $this->tenant = $this->createTenant();
        $this->subscribe($this->tenant, 'pro');
        $this->owner = $this->addMember($this->tenant);
        $this->agent = $this->addMember($this->tenant, SystemRole::Agent);
        $this->number = $this->connectNumber($this->tenant);
        $this->actingAsMember($this->owner);
        Http::preventStrayRequests();
    }

    private function waba(): WabaAccount
    {
        return $this->tenantContext()->run($this->tenant, fn () => WabaAccount::query()->firstOrFail());
    }

    /** @return list<string> bell titles for a member */
    private function bell(TenantMembership $m): array
    {
        return $this->tenantContext()->bypass(fn () => MemberNotification::query()->where('membership_id', $m->id)->where('type', 'number_quality')->pluck('title')->all());
    }

    public function test_error_200_is_plain_words_with_reconnect_until_meta_answers_again(): void
    {
        $waba = $this->waba();
        $granted = false;
        Http::fake(['graph.facebook.com/*' => function (Request $r) use (&$granted) {
            if (str_contains($r->url(), 'debug_token')) {
                return Http::response(['data' => ['is_valid' => true, 'scopes' => []]]);
            }
            if (! $granted) {
                return Http::response(['error' => [
                    'message' => '(#200) Requires whatsapp_business_management permission to manage the object', 'type' => 'OAuthException',
                    'code' => 200, 'error_subcode' => 2494013, 'fbtrace_id' => 'AbCdEf123',
                ]], 403);
            }

            return Http::response(str_contains($r->url(), 'phone_numbers') ? ['data' => []] : ['id' => '102290129340398', 'name' => 'Nova Fitness']);
        }]);

        $error = $this->postJson("/api/v1/whatsapp/accounts/{$waba->id}/refresh")->assertStatus(403)
            ->assertJsonPath('error.code', 'meta_permission_missing')
            ->assertJsonPath('error.details.action', 'reconnect')
            ->assertJsonPath('error.details.meta_code', 200)
            ->assertJsonPath('error.details.meta_subcode', 2494013)
            ->json('error.message');
        $this->assertStringStartsWith('Meta hasn’t given engageX permission to manage this WhatsApp account. Reconnect it', $error);
        $this->assertStringNotContainsString('#200', $error, 'Meta’s raw text is not shown');
        $this->assertStringNotContainsString('OAuthException', $error);

        // Channels keeps showing "Reconnect" after a reload.
        $this->getJson('/api/v1/whatsapp/accounts')->assertOk()
            ->assertJsonPath('data.0.needs_reconnect.reason', 'permission_missing')
            ->assertJsonPath('data.0.needs_reconnect.meta_code', 200);

        // Once Meta answers for the account again (after reconnecting), it goes away.
        $granted = true;
        $this->postJson("/api/v1/whatsapp/accounts/{$waba->id}/refresh")->assertOk();
        $this->getJson('/api/v1/whatsapp/accounts')->assertOk()->assertJsonPath('data.0.needs_reconnect', null);
    }

    public function test_every_permission_code_gets_the_same_plain_message_and_other_errors_keep_theirs(): void
    {
        foreach ([10, 200, 294] as $code) {
            $e = WhatsappException::meta('Meta rejected the signup. Please run Connect WhatsApp again.', $code, 'trace');
            $this->assertSame('meta_permission_missing', $e->errorCode()->value, "code {$code}");
            $this->assertSame(403, $e->status());
        }
        $other = WhatsappException::meta('Meta rejected the signup. Please run Connect WhatsApp again.', 100, 'trace');
        $this->assertSame('meta_api_error', $other->errorCode()->value);
        $this->assertSame('Meta rejected the signup. Please run Connect WhatsApp again.', $other->getMessage());
    }

    public function test_a_flagged_number_alerts_owners_once_by_bell_and_email_and_agents_get_nothing(): void
    {
        Notification::fake();
        Http::fake(['graph.facebook.com/*' => Http::response(['id' => '106540352242922', 'quality_rating' => 'GREEN'])]);
        $body = $this->webhookBody('phone_number_quality_update', ['display_phone_number' => '971585496310', 'event' => 'FLAGGED', 'current_limit' => 'TIER_1K']);

        $this->postWebhook($body)->assertOk();
        $this->postWebhook($this->webhookBody('phone_number_quality_update', ['display_phone_number' => '971585496310', 'event' => 'FLAGGED', 'current_limit' => 'TIER_1K']))->assertOk(); // Meta repeats itself

        $this->assertSame(['Meta flagged +971 58 549 6310 for low quality'], $this->bell($this->owner));
        $this->assertSame([], $this->bell($this->agent));
        $owner = $this->tenantContext()->bypass(fn () => $this->owner->user()->firstOrFail()->email);
        Notification::assertSentOnDemandTimes(NumberQualityNotification::class, 1);
        Notification::assertSentOnDemand(NumberQualityNotification::class, fn (NumberQualityNotification $n, array $channels, AnonymousNotifiable $to) => $to->routes['mail'] === mb_strtolower($owner)
            && str_contains($n->body, 'Customers are blocking or reporting messages'));

        // A lower messaging limit is its own alert.
        $this->postWebhook($this->webhookBody('phone_number_quality_update', ['display_phone_number' => '971585496310', 'event' => 'DOWNGRADE', 'current_limit' => 'TIER_250']))->assertOk();
        $this->assertContains('Meta lowered the messaging limit of +971 58 549 6310', $this->bell($this->owner));
    }

    public function test_quality_falling_to_red_alerts_owners_and_pauses_running_campaigns_and_a_recovery_is_quiet(): void
    {
        Notification::fake();
        $campaign = $this->tenantContext()->run($this->tenant, fn () => Campaign::query()->create(['name' => 'Big sale', 'phone_number_id' => $this->number->id, 'template_name' => 'x', 'template_language' => 'en', 'status' => 'sending']));
        Http::fake(['graph.facebook.com/*/106540352242922*' => Http::sequence()
            ->push(['id' => '106540352242922', 'quality_rating' => 'YELLOW'])
            ->push(['id' => '106540352242922', 'quality_rating' => 'RED'])
            ->push(['id' => '106540352242922', 'quality_rating' => 'GREEN']),
        ]);
        $refresh = fn () => $this->tenantContext()->run($this->tenant, fn () => app(ManageChannels::class)->refreshNumber($this->number->fresh()));

        $refresh();
        $this->assertSame(['Quality rating dropped to Yellow on +971 58 549 6310'], $this->bell($this->owner));
        $this->assertSame('sending', $this->tenantContext()->bypass(fn () => $campaign->fresh()->status->value), 'Yellow alerts, but does not pause');
        $refresh();
        $this->assertContains('Quality rating dropped to Red on +971 58 549 6310', $this->bell($this->owner));
        $paused = $this->tenantContext()->bypass(fn () => $campaign->fresh());
        $this->assertSame('paused', $paused->status->value);
        $this->assertSame('Paused automatically: the number’s quality rating dropped to Red', $paused->pause_reason);
        $refresh(); // back to Green: good news needs no alert
        $this->assertCount(2, $this->bell($this->owner));
        Notification::assertSentOnDemandTimes(NumberQualityNotification::class, 2);
        $this->assertSame('GREEN', $this->tenantContext()->bypass(fn () => $this->number->fresh()->quality_rating));
    }
}
