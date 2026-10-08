<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Billing\SubscriptionService;
use App\Domain\Plans\Models\Plan;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Webhooks\WebhookInbox;
use App\Domain\WhatsApp\Enums\CoexistenceStatus;
use App\Domain\WhatsApp\Enums\OnboardingType;
use App\Domain\WhatsApp\Enums\PhoneNumberStatus;
use App\Domain\WhatsApp\Enums\WabaStatus;
use App\Domain\WhatsApp\Models\MetaAccessToken;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Domain\WhatsApp\Models\WabaAccount;
use App\Infrastructure\Secrets\SecretStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Load test for the WhatsApp Business app history import.
 *
 *   php artisan engage:simulate-history --messages=10000 --chats=50
 *
 * It builds history webhooks shaped exactly like Meta's and hands them to the same entry point the
 * real webhook endpoint uses (after its signature check), so the test travels the real path:
 * webhook log → "webhooks" queue → waiting list → "sync" queue → messages. Nothing is mocked.
 *
 * Everything happens inside a dedicated workspace called "ZZ History load test", with a made-up
 * phone number that exists nowhere at Meta. No customer workspace is touched and nothing is sent
 * to WhatsApp. While the import runs, a "live" customer message is pushed through as well, to
 * measure that live traffic is not held up. At the end it checks the result and prints a verdict.
 */
final class SimulateHistory extends Command
{
    private const SLUG = 'zz-history-load-test';

    private const WABA = '900000000000001';

    private const PHONE_ID = '900000000000002';

    private const DISPLAY = '+971 50 000 0002';

    protected $signature = 'engage:simulate-history
        {--messages=10000 : How many history messages to simulate}
        {--chats=50 : How many different customers (conversations) they belong to}
        {--chunk=500 : Messages per simulated webhook (Meta sends history in chunks)}
        {--timeout=1800 : Give up waiting after this many seconds}
        {--no-wait : Only send the webhooks; do not wait for the import or verify it}
        {--cleanup : Remove the test workspace and everything in it, then stop}
        {--force : Allow running in production}';

    protected $description = 'Simulate a large WhatsApp Business app history import through the real pipeline and verify the result.';

    public function handle(TenantContext $context, WebhookInbox $inbox): int
    {
        if (app()->environment('production') && ! $this->option('force')) {
            $this->components->error('This is a load test. Run it on staging, or add --force to run it on production (it uses its own test workspace and sends nothing to WhatsApp, but it does put real load on this server).');

            return self::FAILURE;
        }
        if ($this->option('cleanup')) {
            return $this->cleanup($context);
        }

        $total = max(1, (int) $this->option('messages'));
        $chats = max(1, min($total, (int) $this->option('chats')));
        $chunk = max(1, min(2000, (int) $this->option('chunk')));
        $run = strtoupper(bin2hex(random_bytes(4)));

        [$tenant, $number] = $this->workspace($context);
        $before = $this->counts($context, $tenant);
        $this->components->info("Workspace \"{$tenant->name}\" · {$total} messages in {$chats} chats · {$chunk} per webhook · run {$run}");

        // ── 1. Send the webhooks, as fast as possible (this is the flood) ────────────────────
        $business = preg_replace('/\D+/', '', self::DISPLAY);
        $customers = array_map(fn (int $i) => '97155'.str_pad((string) (1000000 + $i), 7, '0', STR_PAD_LEFT), range(1, $chats));
        $started = microtime(true);
        $webhooks = (int) ceil($total / $chunk);
        $bar = $this->output->createProgressBar($webhooks);

        for ($w = 0; $w < $webhooks; $w++) {
            $threads = [];
            for ($i = $w * $chunk; $i < min($total, ($w + 1) * $chunk); $i++) {
                $customer = $customers[$i % $chats];
                $threads[$customer]['id'] = $customer;
                $threads[$customer]['messages'][] = [
                    // Two in three are from the customer, one in three from the business (as sent from the phone).
                    'from' => $i % 3 === 2 ? $business : $customer,
                    'id' => "wamid.SIM_{$run}_{$i}",
                    'timestamp' => (string) now()->subMinutes($total - $i)->timestamp, // oldest first, one a minute
                    'type' => 'text',
                    'text' => ['body' => "History message {$i}"],
                    'history_context' => ['status' => 'READ'],
                ];
            }
            $body = ['object' => 'whatsapp_business_account', 'entry' => [['id' => self::WABA, 'time' => now()->timestamp, 'changes' => [[
                'field' => 'history',
                'value' => ['messaging_product' => 'whatsapp', 'metadata' => ['display_phone_number' => $business, 'phone_number_id' => self::PHONE_ID],
                    'history' => [['metadata' => ['phase' => 1, 'chunk_order' => $w + 1, 'progress' => (int) floor(($w + 1) / $webhooks * 100)], 'threads' => array_values($threads)]]],
            ]]]]];
            $inbox->accept((string) json_encode($body), $body);
            $bar->advance();
        }
        $bar->finish();
        $this->newLine(2);
        $accepted = microtime(true) - $started;
        $this->line(sprintf('  %d webhooks accepted in %.1f s (%.0f ms each). That is all the webhook endpoint itself has to do.', $webhooks, $accepted, $accepted / $webhooks * 1000));

        if ($this->option('no-wait')) {
            $this->line('  Not waiting (--no-wait). Watch Horizon: the "sync" queue drains in batches; run again with --cleanup when done.');

            return self::SUCCESS;
        }

        // ── 2. While the import runs: one live customer message, timed ──────────────────────
        $liveId = "wamid.SIM_LIVE_{$run}";
        $liveBody = ['object' => 'whatsapp_business_account', 'entry' => [['id' => self::WABA, 'time' => now()->timestamp, 'changes' => [[
            'field' => 'messages',
            'value' => ['messaging_product' => 'whatsapp', 'metadata' => ['display_phone_number' => $business, 'phone_number_id' => self::PHONE_ID],
                'contacts' => [['wa_id' => '971559999999', 'profile' => ['name' => 'Live Test Customer']]],
                'messages' => [['from' => '971559999999', 'id' => $liveId, 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => 'Live message during the import']]]],
        ]]]]];
        $liveSent = microtime(true);
        $inbox->accept((string) json_encode($liveBody), $liveBody);
        $liveSeconds = null;

        // ── 3. Watch the import drain ────────────────────────────────────────────────────────
        $this->line('  Importing… (waiting list → messages). Ctrl+C stops watching; the import carries on.');
        $deadline = time() + max(10, (int) $this->option('timeout'));
        $peakWaiting = 0;
        $lastLine = 0;
        do {
            $now = $this->counts($context, $tenant);
            $imported = $now['messages'] - $before['messages'];
            $peakWaiting = max($peakWaiting, $now['waiting']);
            if ($liveSeconds === null && $context->bypass(fn () => DB::table('messages')->where('wamid', $liveId)->exists())) {
                $liveSeconds = microtime(true) - $liveSent;
            }
            if (time() - $lastLine >= 5) {
                $lastLine = time();
                $elapsed = max(1, microtime(true) - $started);
                $this->line(sprintf('    %5.0f s   imported %s / %s   waiting %s   %.0f per minute%s', $elapsed, number_format(max(0, $imported - ($liveSeconds !== null ? 1 : 0))), number_format($total),
                    number_format($now['waiting']), $imported / $elapsed * 60, $liveSeconds !== null ? sprintf('   live message arrived after %.1f s', $liveSeconds) : ''));
            }
            $done = $now['waiting'] === 0 && $imported >= $total + ($liveSeconds !== null ? 1 : 0) && $liveSeconds !== null;
            if (! $done) {
                usleep(500_000);
            }
        } while (! $done && time() < $deadline);
        $duration = microtime(true) - $started;

        // ── 4. Verify ────────────────────────────────────────────────────────────────────────
        $after = $this->counts($context, $tenant);
        [$stored, $outbound] = $context->bypass(function () use ($tenant, $run): array {
            $simulated = DB::table('messages')->where('tenant_id', $tenant->id)->where('wamid', 'like', "wamid.SIM\\_{$run}\\_%");

            return [(clone $simulated)->count(), (clone $simulated)->where('direction', 'outbound')->count()];
        });
        $outOfOrder = (int) ($context->bypass(fn () => DB::selectOne(
            "select count(*) as n from (select occurred_at, lag(occurred_at) over (partition by conversation_id order by split_part(wamid, '_', 3)::int) as previous
               from messages where tenant_id = ? and wamid like ?) t where previous is not null and occurred_at < previous", [$tenant->id, "wamid.SIM_{$run}_%"]))->n ?? 0);
        $conversations = $context->bypass(fn () => DB::table('messages')->where('tenant_id', $tenant->id)->where('wamid', 'like', "wamid.SIM_{$run}_%")->distinct()->count('conversation_id'));
        $syncStatus = $context->bypass(fn () => DB::table('phone_numbers')->where('id', $number->id)->value('coexistence_status'));

        $checks = [
            ['All messages imported, none lost', $stored === $total, number_format($stored).' of '.number_format($total)],
            ['No duplicates', $stored <= $total, $stored > $total ? ($stored - $total).' extra' : 'none'],
            ['Waiting list empty', $after['waiting'] === 0, number_format($after['waiting']).' left'],
            ['One conversation per customer', $conversations === $chats, "{$conversations} of {$chats}"],
            ['Direction kept (business vs customer)', $outbound === intdiv($total, 3), number_format($outbound).' sent by the business'],
            ['Every conversation in true time order', $outOfOrder === 0, $outOfOrder === 0 ? 'yes' : "{$outOfOrder} out of order"],
            ['Number marked as synced', $syncStatus === CoexistenceStatus::Synced->value, (string) $syncStatus],
            ['Live message not held up (under 15 s)', $liveSeconds !== null && $liveSeconds < 15, $liveSeconds !== null ? sprintf('%.1f s', $liveSeconds) : 'never arrived'],
        ];
        $this->newLine();
        $this->table(['Check', 'Result', 'Detail'], array_map(fn (array $c) => [$c[0], $c[1] ? 'PASS' : 'FAIL', $c[2]], $checks));
        $this->line(sprintf('  Import took %.0f s (%.0f messages a minute). Largest backlog: %s records waiting.', $duration, $total / max(1, $duration) * 60, number_format($peakWaiting)));
        $this->line('  The test data stays so you can look at it (Super Admin → Companies → "ZZ History load test"). Remove it with: php artisan engage:simulate-history --cleanup'.(app()->environment('production') ? ' --force' : ''));

        $passed = ! in_array(false, array_column($checks, 1), true);
        $passed ? $this->components->info('PASS: the import pipeline handled the flood.') : $this->components->error('FAIL: see the table above.');

        return $passed ? self::SUCCESS : self::FAILURE;
    }

    /** @return array{0: Tenant, 1: PhoneNumber} the test workspace and its made-up coexistence number (created once, reused) */
    private function workspace(TenantContext $context): array
    {
        $tenant = $context->bypass(fn () => Tenant::query()->where('slug', self::SLUG)->first());
        if ($tenant === null) {
            $tenant = $context->bypass(fn () => Tenant::query()->create(['name' => 'ZZ History load test', 'slug' => self::SLUG]));
            app(SubscriptionService::class)->assign($tenant, Plan::byKey('pro')->activeVersionOrFail());
        }

        $number = $context->run($tenant, function () use ($context): PhoneNumber {
            $existing = PhoneNumber::query()->where('phone_number_id', self::PHONE_ID)->first();
            if ($existing !== null) {
                $existing->forceFill(['coexistence_status' => CoexistenceStatus::HistorySyncing])->save();
                DB::table('coexistence_sync_jobs')->where('phone_number_id', $existing->id)->update(['progress' => 0, 'status' => 'in_progress', 'completed_at' => null]);

                return $existing;
            }
            $token = MetaAccessToken::query()->create([
                'secret_id' => app(SecretStore::class)->put('meta.business_token', 'simulated-not-a-real-token', $context->id()), 'meta_app_id' => 'simulated',
            ]);
            $waba = WabaAccount::query()->create(['waba_id' => self::WABA, 'access_token_id' => $token->id, 'status' => WabaStatus::Connected, 'is_subscribed_to_webhooks' => true, 'connected_at' => now()]);

            return PhoneNumber::query()->create([
                'waba_account_id' => $waba->id, 'phone_number_id' => self::PHONE_ID, 'display_phone_number' => self::DISPLAY, 'verified_name' => 'History load test',
                'status' => PhoneNumberStatus::Connected, 'onboarding_type' => OnboardingType::Coexistence, 'coexistence_status' => CoexistenceStatus::HistorySyncing,
                'quality_rating' => 'GREEN', 'messaging_limit_tier' => 'TIER_1K', 'capabilities' => PhoneNumber::capabilitiesFor(OnboardingType::Coexistence),
            ]);
        });

        return [$tenant, $number];
    }

    /** @return array{messages: int, waiting: int} */
    private function counts(TenantContext $context, Tenant $tenant): array
    {
        return $context->bypass(fn () => [
            'messages' => DB::table('messages')->where('tenant_id', $tenant->id)->count(),
            'waiting' => DB::table('coexistence_sync_items')->where('tenant_id', $tenant->id)->count(),
        ]);
    }

    /**
     * Removes the test workspace and everything in it. Tables are discovered (every table with a
     * tenant_id column) and emptied in whatever order their foreign keys allow.
     */
    private function cleanup(TenantContext $context): int
    {
        $tenant = $context->bypass(fn () => Tenant::query()->where('slug', self::SLUG)->first());
        if ($tenant === null) {
            $this->components->info('Nothing to clean up: the test workspace does not exist.');

            return self::SUCCESS;
        }

        $tables = collect(DB::select("select table_name from information_schema.columns where table_schema = current_schema() and column_name = 'tenant_id'
            and table_name in (select table_name from information_schema.tables where table_schema = current_schema() and table_type = 'BASE TABLE')"))->pluck('table_name')->all();
        // Partitions are emptied through their parent table.
        $partitions = collect(DB::select('select c.relname from pg_inherits i join pg_class c on c.oid = i.inhrelid'))->pluck('relname')->all();
        $remaining = array_values(array_diff($tables, $partitions));
        $kept = [];

        $context->bypass(function () use ($tenant, &$remaining, &$kept): void {
            for ($pass = 0; $pass < 12 && $remaining !== []; $pass++) {
                foreach ($remaining as $i => $table) {
                    try {
                        DB::transaction(fn () => DB::table($table)->where('tenant_id', $tenant->id)->delete());
                        unset($remaining[$i]);
                    } catch (Throwable $e) {
                        $kept[$table] = $e->getMessage(); // another table still points at these rows: try again next pass
                    }
                }
                $remaining = array_values($remaining);
            }
            if ($remaining === []) {
                try {
                    DB::table('tenants')->where('id', $tenant->id)->delete();
                } catch (Throwable $e) {
                    $remaining[] = 'tenants';
                }
            }
        });

        if ($remaining === []) {
            $this->components->info('The test workspace and all its data were removed.');

            return self::SUCCESS;
        }
        // Some records are kept on purpose by the database (for example the append-only audit log).
        $left = $context->bypass(fn () => DB::table('messages')->where('tenant_id', $tenant->id)->count());
        $this->components->warn("Test data removed ({$left} messages left). The empty workspace shell \"{$tenant->name}\" stays, because these records cannot be deleted: ".implode(', ', $remaining).'. It has no users and is safe to ignore.');

        return self::SUCCESS;
    }
}
