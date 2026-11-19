<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use App\Application\WhatsApp\NumberAccess;
use App\Domain\Access\SystemRole;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\MemberNotification;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Services\ConversationTracker;
use App\Domain\Templates\Models\MessageTemplate;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Timeline\Enquiry;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Notifications\MissedLeadsReportNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

/**
 * Inbox & CRM 3.3, A1 and A3: only a person's reply that WhatsApp accepted ends a wait; the waiting
 * limit in business hours; Needs reply sorted by time left; the 30/45/60 alert ladder and its log;
 * Retry only for temporary errors; the weekly missed-leads report; and the 20-message promise path.
 */
final class ReplyPromiseTest extends TestCase
{
    use InteractsWithWhatsapp;

    private const SEND = 'graph.facebook.com/v27.0/106540352242922/messages*';

    private Tenant $tenant;

    private TenantMembership $owner;

    private PhoneNumber $number;

    /** @var list<array<string, mixed>> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
        // Monday 23 November 2026, 09:00 in Dubai; open Monday–Friday 09:00–18:00.
        $this->travelTo(Carbon::parse('2026-11-23 09:00', 'Asia/Dubai'));
        $this->tenant = $this->createTenant(['timezone' => 'Asia/Dubai']);
        $this->subscribe($this->tenant, 'pro');
        $this->owner = $this->addMember($this->tenant);
        $this->number = $this->connectNumber($this->tenant);
        Http::fake([self::SEND => function ($r) {
            $this->sent[] = $r->data();
            // A reply whose text says FAIL is refused by Meta (131026, not retried).
            if (str_contains((string) ($r->data()['text']['body'] ?? ''), 'FAIL')) {
                return Http::response(['error' => ['message' => 'Message undeliverable', 'type' => 'OAuthException', 'code' => 131026, 'fbtrace_id' => 'x']], 400);
            }

            return Http::response(['messaging_product' => 'whatsapp', 'contacts' => [['wa_id' => '971501234567']], 'messages' => [['id' => 'wamid.OUT'.bin2hex(random_bytes(5))]]]);
        }]);
        $this->actingAsMember($this->owner);
        $week = [];
        foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $d) {
            $week[$d] = ['open' => ! in_array($d, ['sat', 'sun'], true), 'from' => '09:00', 'to' => '18:00'];
        }
        $this->putJson('/api/v1/tenant/inbox-settings', ['business_hours' => ['enabled' => true, 'days' => $week]])->assertOk();
    }

    private function at(int $day, int $hour, int $minute = 0): void
    {
        $this->travelTo(Carbon::parse('2026-11-23 00:00', 'Asia/Dubai')->addDays($day)->setTime($hour, $minute));
    }

    private function customer(string $text = 'Hi', string $waId = '971501234567'): void
    {
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue(['text' => ['body' => $text], 'timestamp' => (string) now()->timestamp], waId: $waId, bsuid: null)))->assertOk();
    }

    private function conversation(string $waId = '971501234567'): Conversation
    {
        return $this->tenantContext()->run($this->tenant, fn () => Conversation::query()->whereHas('contact', fn ($c) => $c->where('wa_id', $waId))->sole());
    }

    private function reply(Conversation $c, string $text = 'Yes, we have it.'): Message
    {
        $id = $this->postJson("/api/v1/conversations/{$c->id}/messages", ['type' => 'text', 'body' => $text])->assertSuccessful()->json('data.id');

        return $this->tenantContext()->run($this->tenant, fn () => Message::query()->findOrFail($id));
    }

    private function automated(Conversation $c, string $origin): void
    {
        $this->tenantContext()->run($this->tenant, function () use ($c, $origin) {
            $m = Message::query()->create([
                'conversation_id' => $c->id, 'phone_number_id' => $c->phone_number_id, 'contact_id' => $c->contact_id,
                'direction' => 'outbound', 'origin' => $origin, 'type' => 'text', 'status' => 'sent', 'body' => 'Automatic message', 'wamid' => 'wamid.'.Str::random(12),
            ]);
            app(ConversationTracker::class)->apply($m);
        });
    }

    private function state(Conversation $c): string
    {
        return (string) $this->getJson("/api/v1/conversations/{$c->id}")->assertOk()->json('data.state');
    }

    private function statusWebhook(string $wamid, string $status, ?int $code = null): void
    {
        $this->postWebhook($this->webhookBody('messages', ['messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => '106540352242922'],
            'statuses' => [array_filter(['id' => $wamid, 'status' => $status, 'timestamp' => (string) now()->timestamp, 'recipient_id' => '971501234567',
                'errors' => $code !== null ? [['code' => $code, 'title' => 'Error '.$code]] : null])]]))->assertOk();
    }

    private function firstResponses(): int
    {
        return $this->tenantContext()->run($this->tenant, fn () => DB::table('inbox_response_times')->where('kind', 'first_response')->count());
    }

    public function test_only_a_reply_whatsapp_accepted_ends_the_wait_and_a_failure_recalculates_the_state(): void
    {
        $this->at(0, 10);
        $this->customer('Is the SUV free on Friday?');
        $c = $this->conversation();

        // Refused straight away (131026): the chat still needs a reply, nothing is recorded.
        $this->at(0, 10, 5);
        $failed = $this->reply($c, 'FAIL this one');
        $this->assertSame('failed', $failed->status->value);
        $this->assertSame('needs_reply', $this->state($c));
        $this->assertSame(0, $this->firstResponses());

        // Accepted: the wait ends.
        $this->at(0, 10, 20);
        $ok = $this->reply($c);
        $this->assertSame('waiting', $this->state($c));
        $this->assertSame(1, $this->firstResponses());

        // Meta reports later that it failed: the state is worked out again from the messages (the
        // customer is still waiting since 10:00), the response time no longer stands, the alerts are back.
        $this->at(0, 10, 25);
        $this->statusWebhook((string) $ok->wamid, 'failed', 131000);
        $chat = $this->getJson("/api/v1/conversations/{$c->id}")->json('data');
        $this->assertSame('needs_reply', $chat['state']);
        $this->assertSame(Carbon::parse('2026-11-23 10:00', 'Asia/Dubai')->toIso8601String(), Carbon::parse($chat['needs_reply_since'])->setTimezone('Asia/Dubai')->toIso8601String());
        $this->assertSame(0, $this->firstResponses());
        $this->assertSame(25 * 60, $chat['promise']['waited_seconds']);

        // The failed message offers Retry (temporary error); a 131026 one does not.
        $messages = collect($this->getJson("/api/v1/conversations/{$c->id}/messages")->json('data'))->keyBy('id');
        $this->assertSame(['retry', true], [$messages[$ok->id]['error']['action'], $messages[$ok->id]['error']['known']]);
        $this->assertSame('WhatsApp had a temporary problem.', $messages[$ok->id]['error']['plain']);
        $this->assertNull($messages[$failed->id]['error']['action']);
        $this->postJson("/api/v1/conversations/{$c->id}/messages/{$failed->id}/retry")->assertUnprocessable();

        $this->at(0, 10, 30);
        $this->postJson("/api/v1/conversations/{$c->id}/messages/{$ok->id}/retry")->assertStatus(202);
        $this->assertSame('waiting', $this->state($c));
        $this->assertSame(1, $this->firstResponses());
    }

    public function test_failed_campaign_and_automation_messages_never_change_the_reply_state(): void
    {
        $this->at(0, 10);
        $this->customer();
        $c = $this->conversation();
        $this->automated($c, 'campaign');
        $wamid = $this->tenantContext()->run($this->tenant, fn () => Message::query()->where('origin', 'campaign')->value('wamid'));
        $this->statusWebhook((string) $wamid, 'failed', 131049);
        $this->assertSame('needs_reply', $this->state($c));

        $this->reply($c);
        $this->automated($c, 'automation');
        $wamid = $this->tenantContext()->run($this->tenant, fn () => Message::query()->where('origin', 'automation')->value('wamid'));
        $this->statusWebhook((string) $wamid, 'failed', 131026);
        $this->assertSame('waiting', $this->state($c));
    }

    public function test_the_limit_counts_business_hours_and_needs_reply_is_sorted_by_time_left(): void
    {
        $this->at(0, 21);                      // Monday evening: due Tuesday 10:00
        $this->customer('Evening question', '971500000001');
        $this->at(1, 9, 30);                   // Tuesday 09:30: due 10:30
        $this->customer('Morning question', '971500000002');
        $this->at(1, 9, 50);

        $list = $this->getJson('/api/v1/conversations?state=needs_reply')->json('data');
        $this->assertSame(['971500000001', '971500000002'], array_map(fn ($c) => $c['contact']['wa_id'], $list));
        $this->assertSame(Carbon::parse('2026-11-24 10:00', 'Asia/Dubai')->utc()->toIso8601String(), Carbon::parse($list[0]['promise']['due_at'])->utc()->toIso8601String());
        $this->assertSame([50 * 60, 10 * 60, false], [$list[0]['promise']['waited_seconds'], $list[0]['promise']['left_seconds'], $list[0]['promise']['over']]);

        // A longer limit moves the due times (the sweep applies it to running waits).
        $this->putJson('/api/v1/tenant/reply-promise', ['limit_minutes' => 90])->assertOk()->assertJsonPath('data.limit_minutes', 90);
        Artisan::call('engage:followups:sweep');
        $this->assertSame(40 * 60, $this->getJson('/api/v1/conversations?state=needs_reply')->json('data.0.promise.left_seconds'));
    }

    public function test_the_ladder_tells_the_agent_then_the_managers_then_the_owner_by_whatsapp_and_email(): void
    {
        $agent = $this->addMember($this->tenant, SystemRole::Agent);
        $manager = $this->addMember($this->tenant, SystemRole::Manager);
        $this->tenantContext()->run($this->tenant, function () use ($agent, $manager) {
            app(NumberAccess::class)->setGrants($agent, [$this->number->id]);
            app(NumberAccess::class)->setGrants($manager, [$this->number->id]);
        });
        $this->putJson('/api/v1/tenant/followups', ['reply_alert' => ['enabled' => false]])->assertOk(); // only the ladder here
        $this->putJson('/api/v1/tenant/reply-promise', ['owner_whatsapp' => ['phone' => '+971 50 999 0000', 'template' => ['name' => 'reply_promise_alert', 'language' => 'en']]])
            ->assertOk()->assertJsonPath('data.owner_whatsapp.ready', true);

        $this->at(0, 17, 40);                  // 20 business minutes today, the rest tomorrow morning
        $this->customer('Hello?');
        $c = $this->conversation();
        $this->tenantContext()->run($this->tenant, fn () => Conversation::query()->whereKey($c->id)->update(['assigned_membership_id' => $agent->id]));
        $bell = fn (TenantMembership $m) => $this->tenantContext()->run($this->tenant, fn () => MemberNotification::query()->where('membership_id', $m->id)->pluck('type')->all());
        $tick = function (int $day, int $h, int $m) {
            $this->at($day, $h, $m);
            Artisan::call('engage:inbox:wake-snoozed');
        };

        $tick(0, 23, 0);
        $this->assertSame([], $bell($agent), 'the clock is paused overnight');
        $tick(1, 9, 10);                       // 30 business minutes
        $this->assertSame(['reply_overdue'], $bell($agent));
        $this->assertSame([], $bell($manager));
        $tick(1, 9, 25);                       // 45
        $this->assertContains('reply_escalated', $bell($manager));
        $before = count($this->sent);
        $tick(1, 9, 40);                       // 60: the Owner, also by WhatsApp
        $this->assertContains('reply_escalated', $bell($this->owner));
        $this->assertCount($before + 1, $this->sent);
        $this->assertSame(['971509990000', 'reply_promise_alert', 'Sara Ahmed', '60'], [
            $this->sent[$before]['to'], $this->sent[$before]['template']['name'],
            $this->sent[$before]['template']['components'][0]['parameters'][0]['text'], $this->sent[$before]['template']['components'][0]['parameters'][1]['text'],
        ]);

        $log = $this->getJson("/api/v1/conversations/{$c->id}/wait-alerts")->assertOk()->json('data');
        $this->assertSame([[3, 'owner', ['app', 'email', 'whatsapp']], [2, 'manager', ['app', 'email']], [1, 'agent', ['app', 'email']]],
            array_map(fn ($a) => [$a['level'], $a['role'], $a['channels']], $log));
        $this->assertSame($manager->id, $log[1]['recipients'][0]['id']);

        // A reply ends the ladder for this wait.
        $this->reply($c);
        $this->customer('One more thing');
        $this->reply($c);
        $tick(1, 12, 0);
        $this->assertCount(3, $this->getJson("/api/v1/conversations/{$c->id}/wait-alerts")->json('data'));
    }

    public function test_the_weekly_missed_leads_report_with_aed_at_stake_protected_and_the_baseline(): void
    {
        $this->putJson('/api/v1/tenant/reply-promise', ['avg_booking_value' => 4500])->assertOk();
        $this->at(0, 10);
        $this->customer('Fast one', '971500000001');
        $this->at(0, 10, 20);
        $this->reply($this->conversation('971500000001'));

        $this->at(0, 11);
        $this->customer('Slow one', '971500000002');
        $this->at(0, 12, 30);
        $this->reply($this->conversation('971500000002'));

        $this->at(0, 14);
        $this->customer('Never answered', '971500000003');
        $this->at(0, 16, 30);
        $this->customer('Just now', '971500000004');
        $this->at(0, 16, 40);

        // A verified payment (a paid store order) on the fast enquiry.
        $fast = $this->tenantContext()->run($this->tenant, fn () => Enquiry::query()->where('conversation_id', $this->conversation('971500000001')->id)->sole());
        $this->tenantContext()->run($this->tenant, fn () => DB::table('commerce_orders')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'integration_id' => (string) Str::uuid(), 'store_name' => 'Store', 'external_id' => '1',
            'number' => '1001', 'status' => 'processing', 'currency' => 'AED', 'total' => '6500', 'paid' => true, 'paid_at' => now(), 'placed_at' => now(),
            'contact_id' => $fast->contact_id, 'enquiry_id' => $fast->id, 'match' => 'link', 'created_at' => now(), 'updated_at' => now(),
        ]));

        $r = $this->getJson('/api/v1/reports/missed-leads')->assertOk()->json('data');
        $this->assertSame([4, 1, 2, 1], [$r['enquiries'], $r['in_time'], $r['over'], $r['pending']]);
        $this->assertSame([90 * 60, true], [$r['waited_over'][0]['waited_seconds'], $r['waited_over'][0]['answered']]);
        $this->assertSame([160 * 60, false], [$r['waited_over'][1]['waited_seconds'], $r['waited_over'][1]['answered']]);
        $this->assertSame([9000, 6500.0, 1], [$r['aed_at_stake'], (float) $r['aed_protected'], $r['verified_payments']]);
        $this->assertSame([2, 1], [$r['staff'][0]['replies'], $r['staff'][0]['within_limit']]);
        $this->assertSame(4, collect($r['by_confidence'])->sum('enquiries'));

        $this->putJson('/api/v1/reports/missed-leads/baseline', ['enquiries' => 30, 'in_time' => 20, 'over' => 15])->assertUnprocessable();
        $this->putJson('/api/v1/reports/missed-leads/baseline', ['enquiries' => 30, 'in_time' => 12, 'over' => 18, 'note' => 'Audit of the last week of October'])->assertOk();
        $this->assertSame(18, $this->getJson('/api/v1/reports/missed-leads?weeks_ago=0')->json('data.baseline.over'));

        // Only Owners and Admins set the baseline; a Manager reads the report.
        $this->actingAsMember($this->addMember($this->tenant, SystemRole::Manager));
        $this->getJson('/api/v1/reports/missed-leads')->assertOk();
        $this->putJson('/api/v1/reports/missed-leads/baseline', ['enquiries' => 1])->assertForbidden();

        // Next Monday morning the Owners are emailed last week's report.
        Notification::fake();
        $this->at(7, 8, 37);
        Artisan::call('engage:reports:missed-leads');
        Notification::assertSentTo($this->owner->user, MissedLeadsReportNotification::class, fn ($n) => $n->report['over'] === 3 && $n->report['week']['from'] === '2026-11-23');
    }

    /** @return array<string, array{0: list<array{0: string, 1: int, 2: int, 3?: int}>, 1: ?array{0: int, 1: int, 2: int}, 2: bool, 3?: int}> */
    public static function promisePath(): array
    {
        // [kind, day, hour, minute]; day 0 = Monday 23 November 2026. Expected: answered within 60 business minutes.
        return [
            '1 reply after 20 min' => [[['in', 0, 10], ['out', 0, 10, 20]], null, true],
            '2 reply after 65 min' => [[['in', 0, 10], ['out', 0, 11, 5]], null, false],
            '3 17:30 message, reply 09:20 next day' => [[['in', 0, 17, 30], ['out', 1, 9, 20]], null, true],
            '4 evening 21:00, reply 09:45 next day' => [[['in', 0, 21], ['out', 1, 9, 45]], null, true],
            '5 evening 21:00, reply 10:10 next day' => [[['in', 0, 21], ['out', 1, 10, 10]], null, false],
            '6 Friday 17:45, template Monday 09:30' => [[['in', 4, 17, 45], ['tpl', 7, 9, 30]], null, true],
            '7 Saturday 11:00, template Monday 10:01' => [[['in', 5, 11], ['tpl', 7, 10, 1]], null, false],
            '8 bot answers at once, a person after 50 min' => [[['in', 0, 10], ['auto', 0, 10, 1], ['out', 0, 10, 50]], null, true],
            '9 an internal note does not count' => [[['in', 0, 10], ['note', 0, 10, 10], ['out', 0, 11, 10]], null, false],
            '10 reply fails at 10:20, sent again at 10:40' => [[['in', 0, 10], ['fail', 0, 10, 20], ['out', 0, 10, 40]], null, true],
            '11 reply fails, never sent again' => [[['in', 0, 10], ['fail', 0, 10, 30]], [0, 11, 30], false],
            '12 a second message before the reply' => [[['in', 0, 10], ['in', 0, 10, 30], ['out', 0, 10, 45]], null, true],
            '13 an agent sends a template after 30 min' => [[['in', 0, 10], ['tpl', 0, 10, 30]], null, true],
            '14 a campaign message does not count' => [[['in', 0, 10], ['campaign', 0, 10, 5]], [0, 11, 10], false],
            '15 08:30 message, reply 09:40' => [[['in', 0, 8, 30], ['out', 0, 9, 40]], null, true],
            '16 reply after 59 min' => [[['in', 0, 12], ['out', 0, 12, 59]], null, true],
            '17 reply at exactly 60 min' => [[['in', 0, 12], ['out', 0, 13]], null, true],
            '18 reply after 61 min' => [[['in', 0, 12], ['out', 0, 13, 1]], null, false],
            '19 17:50 message, reply 18:30 after closing' => [[['in', 0, 17, 50], ['out', 0, 18, 30]], null, true],
            '20 no reply: 3 alerts by 11:01 (agent, manager, owner)' => [[['in', 0, 10]], [0, 11, 1], false, 3],
        ];
    }

    /**
     * The 20-message promise path (A3): every step goes through the real webhook, send job, scheduler
     * and business-hours maths, evening and weekend messages included.
     *
     * @param  list<array{0: string, 1: int, 2: int, 3?: int}>  $script
     * @param  ?array{0: int, 1: int, 2: int}  $asOf
     */
    #[DataProvider('promisePath')]
    public function test_the_promise_path(array $script, ?array $asOf, bool $within, ?int $alerts = null): void
    {
        $this->tenantContext()->run($this->tenant, fn () => MessageTemplate::query()->create(['waba_account_id' => $this->number->waba_account_id, 'name' => 'follow_up',
            'language' => 'en', 'status' => 'APPROVED', 'category' => 'UTILITY', 'components' => [['type' => 'BODY', 'text' => 'Sorry for the wait, we are on it.']]]));
        $c = null;
        foreach ($script as $step) {
            [$kind, $day, $hour] = $step;
            $this->at($day, $hour, $step[3] ?? 0);
            Artisan::call('engage:inbox:wake-snoozed');
            if ($kind === 'in') {
                $this->customer();
                $c = $this->conversation();

                continue;
            }
            match ($kind) {
                'out' => $this->reply($c),
                'fail' => $this->reply($c, 'FAIL'),
                'tpl' => $this->postJson("/api/v1/conversations/{$c->id}/messages", ['type' => 'template', 'template' => ['name' => 'follow_up', 'language' => 'en']])->assertSuccessful(),
                'note' => $this->postJson("/api/v1/conversations/{$c->id}/notes", ['body' => 'Called them, no answer'])->assertSuccessful(),
                'auto' => $this->automated($c, 'automation'),
                'campaign' => $this->automated($c, 'campaign'),
            };
        }
        if ($asOf !== null) {
            $this->at(...$asOf);
            Artisan::call('engage:inbox:wake-snoozed');
        }

        $first = $this->tenantContext()->run($this->tenant, fn () => DB::table('inbox_response_times')->where('kind', 'first_response')->orderBy('started_at')->first());
        $answeredInTime = $first !== null && (int) $first->business_seconds <= 3600;
        $this->assertSame($within, $answeredInTime, $first !== null ? 'waited '.intdiv((int) $first->business_seconds, 60).' business minutes' : 'no reply recorded');
        if ($first === null) {
            $this->assertSame('needs_reply', $this->state($c));
        }
        if ($alerts !== null) {
            $this->assertCount($alerts, $this->getJson("/api/v1/conversations/{$c->id}/wait-alerts")->json('data'));
        }
    }
}
