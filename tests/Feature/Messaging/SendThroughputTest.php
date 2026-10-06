<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Services\SendThroughput;
use App\Domain\Plans\Entitlements\EntitlementService;
use App\Domain\Plans\Models\Feature;
use App\Domain\Plans\Models\TenantEntitlementOverride;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\WhatsApp\Enums\OnboardingType;
use App\Domain\WhatsApp\Models\PhoneNumber;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

/**
 * Messages-per-second limits. The rule under test, enforced on the server where messages are
 * actually sent: per connected number, the lowest of the plan's limit, Meta's cap for the number,
 * and 20 for a WhatsApp Business app (coexistence) number — which always wins.
 */
final class SendThroughputTest extends TestCase
{
    use InteractsWithWhatsapp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
    }

    /** @return array{0: Tenant, 1: PhoneNumber} */
    private function workspace(string $plan, bool $coexistence = false, int $metaMps = 80): array
    {
        $tenant = $this->createTenant();
        $this->subscribe($tenant, $plan);
        $number = $this->connectNumber($tenant);
        $this->tenantContext()->run($tenant, fn () => $number->forceFill([
            'max_mps' => $metaMps, 'onboarding_type' => $coexistence ? OnboardingType::Coexistence : OnboardingType::NewNumber,
        ])->save());

        return [$tenant, $number];
    }

    private function limit(PhoneNumber $number): int
    {
        $fresh = $this->tenantContext()->bypass(fn () => PhoneNumber::query()->findOrFail($number->id));

        return app(SendThroughput::class)->limitFor($fresh);
    }

    /** @return array<string, array{0: string, 1: bool, 2: int}> */
    public static function matrix(): array
    {
        return [
            'Starter, API number' => ['starter', false, 20],
            'Growth, API number' => ['growth', false, 30],
            'Pro, API number' => ['pro', false, 40],
            'Starter, coexistence' => ['starter', true, 20],
            'Growth, coexistence' => ['growth', true, 20],     // not 30
            'Pro, coexistence' => ['pro', true, 20],           // not 40
            'Enterprise, coexistence' => ['enterprise', true, 20],
        ];
    }

    #[DataProvider('matrix')]
    public function test_the_limit_for_each_plan_and_number_type(string $plan, bool $coexistence, int $expected): void
    {
        [, $number] = $this->workspace($plan, $coexistence);

        $this->assertSame($expected, $this->limit($number));
        fwrite(STDOUT, PHP_EOL.sprintf('  [mps] %-11s %-12s → %d messages/second', $plan, $coexistence ? 'coexistence' : 'API number', $expected));
    }

    public function test_coexistence_cannot_be_lifted_by_an_upgrade_an_override_or_metas_own_cap(): void
    {
        [$tenant, $number] = $this->workspace('starter', coexistence: true, metaMps: 1000);
        $this->assertSame(20, $this->limit($number));

        // Upgrade Starter → Pro: still 20 on the coexistence number.
        $this->subscribe($tenant, 'pro');
        app(EntitlementService::class)->forget($tenant);
        $this->assertSame(20, $this->limit($number));

        // A Super Admin override to 500, then to unlimited: still 20.
        $feature = $this->tenantContext()->bypass(fn () => Feature::query()->where('key', 'messages_per_second')->firstOrFail());
        $override = $this->tenantContext()->bypass(fn () => TenantEntitlementOverride::query()->create(['tenant_id' => $tenant->id, 'feature_id' => $feature->id, 'enabled' => true, 'limit_value' => 500, 'reason' => 'test']));
        app(EntitlementService::class)->forget($tenant);
        $this->assertSame(20, $this->limit($number));
        $this->tenantContext()->bypass(fn () => $override->forceFill(['limit_value' => null, 'unlimited' => true])->save());
        app(EntitlementService::class)->forget($tenant);
        $this->assertSame(20, $this->limit($number));

        // The limit is per number: a normal API number in the same Pro workspace gets the plan's 40 …
        $this->tenantContext()->bypass(fn () => $override->delete());
        app(EntitlementService::class)->forget($tenant);
        $api = $this->connectNumber($tenant, '102290129340399', '999000111222333', '+971 58 000 0001');
        $this->assertSame(40, $this->limit($api));
        $this->assertSame(20, $this->limit($number));

        // … and Meta's own cap for a number is never exceeded either.
        $this->tenantContext()->run($tenant, fn () => $api->forceFill(['max_mps' => 10])->save());
        $this->assertSame(10, $this->limit($api));

        // The API reports the effective value; nothing a client sends can change it.
        $this->actingAsMember($this->addMember($tenant));
        $this->getJson("/api/v1/phone-numbers/{$number->id}")->assertOk()->assertJsonPath('data.max_mps', 20)->assertJsonPath('data.onboarding_type', 'coexistence');
        // There is no endpoint that accepts a speed or a number type: attempts are refused and change nothing.
        foreach (['patch', 'put', 'post'] as $verb) {
            $this->json($verb, "/api/v1/phone-numbers/{$number->id}", ['max_mps' => 80, 'onboarding_type' => 'new_number'])->assertStatus(405);
        }
        $this->assertSame(20, $this->limit($number));
    }

    public function test_sends_are_spaced_so_no_one_second_window_ever_exceeds_the_limit(): void
    {
        [, $number] = $this->workspace('pro', coexistence: true); // limit 20/s, paced at 18/s → one slot every ~55.6 ms
        $throughput = app(SendThroughput::class);

        $started = microtime(true) * 1000;
        $slots = [];
        for ($i = 0; $i < 30; $i++) {
            $wait = $throughput->reserve($number, 60_000);
            $this->assertNotNull($wait);
            $slots[] = (microtime(true) * 1000 - $started) + $wait; // when this message may go out
        }

        // Every gap is a full interval (2 ms tolerance for integer rounding of the wait).
        for ($i = 1; $i < count($slots); $i++) {
            $this->assertGreaterThanOrEqual(53, $slots[$i] - $slots[$i - 1], "slot {$i} is too close to the previous one");
        }
        // And in any sliding one-second window there are never more than 20 sends.
        foreach ($slots as $from) {
            $inWindow = count(array_filter($slots, fn (float $t) => $t >= $from && $t < $from + 1000));
            $this->assertLessThanOrEqual(20, $inWindow);
        }
        $this->assertGreaterThanOrEqual(29 * 53, end($slots) - $slots[0]); // 30 messages need ~1.6 s

        // When the queue for the number is already further ahead than the caller will wait, it is refused.
        $this->assertNull($throughput->reserve($number, 100));
    }

    public function test_every_message_goes_through_the_gate_when_it_is_sent(): void
    {
        [$tenant, $number] = $this->workspace('pro', coexistence: true);
        Http::fake(['graph.facebook.com/*/messages*' => fn () => Http::response([
            'messaging_product' => 'whatsapp', 'contacts' => [['wa_id' => '971501234567']], 'messages' => [['id' => 'wamid.T'.bin2hex(random_bytes(6))]],
        ])]);
        $this->actingAsMember($this->addMember($tenant));
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue()))->assertOk(); // the customer writes: window open
        $conversation = $this->tenantContext()->run($tenant, fn () => Conversation::query()->firstOrFail());

        $sentAt = [];
        Http::globalRequestMiddleware(function ($request) use (&$sentAt) {
            if (str_contains((string) $request->getUri(), '/messages')) {
                $sentAt[] = microtime(true) * 1000;
            }

            return $request;
        });

        for ($i = 1; $i <= 6; $i++) {
            $this->postJson("/api/v1/conversations/{$conversation->id}/messages", ['type' => 'text', 'body' => "Message {$i}"])->assertStatus(202);
        }

        Http::assertSentCount(6);
        for ($i = 1; $i < count($sentAt); $i++) {
            $this->assertGreaterThanOrEqual(50, $sentAt[$i] - $sentAt[$i - 1], 'two messages left closer together than 20 per second allows');
        }
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/messages'));
    }
}
