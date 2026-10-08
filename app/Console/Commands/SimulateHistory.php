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
use App\Support\Queue\QueueName;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Load test for the WhatsApp Business app history import.
 *
 *   One customer:        php artisan engage:simulate-history --messages=10000 --chats=50
 *   Several at once:     php artisan engage:simulate-history --workspaces=5 --messages=10000
 *
 * It builds history webhooks shaped exactly like Meta's and hands them to the same entry point the
 * real webhook endpoint uses (after its signature check), so the test travels the real path:
 * webhook log → "webhooks" queue → waiting list → "sync" queue → messages. Nothing is mocked.
 *
 * With --workspaces=N, N separate customers start syncing in the same moment (their webhooks are
 * interleaved), which is the case that matters for stability: the import is paced per workspace,
 * so several workspaces importing together is what stresses the shared sync workers and the
 * database. While it runs, a "live" customer message is pushed through every few seconds and
 * timed, and the number of open database connections is sampled.
 *
 * Everything happens in dedicated workspaces called "ZZ History load test", each with a made-up
 * phone number that exists nowhere at Meta. No customer workspace is touched and nothing is sent
 * to WhatsApp.
 */
final class SimulateHistory extends Command
{
    private const SLUG = 'zz-history-load-test';

    protected $signature = 'engage:simulate-history
        {--messages=10000 : History messages per workspace}
        {--chats=50 : Different customers (conversations) per workspace}
        {--workspaces=1 : How many workspaces start importing at the same time}
        {--chunk=500 : Messages per simulated webhook (Meta sends history in chunks)}
        {--timeout=3600 : Give up waiting after this many seconds}
        {--no-wait : Only send the webhooks; do not wait for the import or verify it}
        {--cleanup : Remove the test workspaces and everything in them, then stop}
        {--force : Allow running in production}';

    protected $description = 'Simulate one or several large WhatsApp Business app history imports through the real pipeline and verify the result.';

    public function handle(TenantContext $context, WebhookInbox $inbox): int
    {
        if (app()->environment('production') && ! $this->option('force')) {
            $this->components->error('This is a load test. Run it on staging, or add --force to run it on production (it uses its own test workspaces and sends nothing to WhatsApp, but it does put real load on this server).');

            return self::FAILURE;
        }
        if ($this->option('cleanup')) {
            return $this->cleanup($context);
        }

        $perWorkspace = max(1, (int) $this->option('messages'));
        $chats = max(1, min($perWorkspace, (int) $this->option('chats')));
        $chunk = max(1, min(2000, (int) $this->option('chunk')));
        $count = max(1, min(20, (int) $this->option('workspaces')));
        $total = $perWorkspace * $count;
        $run = strtoupper(bin2hex(random_bytes(4)));

        /** @var list<array{tenant: Tenant, number: PhoneNumber, index: int, before: int, done: ?float}> $spaces */
        $spaces = [];
        for ($w = 1; $w <= $count; $w++) {
            [$tenant, $number] = $this->workspace($context, $w);
            $spaces[] = ['tenant' => $tenant, 'number' => $number, 'index' => $w, 'before' => $this->messages($context, $tenant), 'done' => null];
        }
        $this->components->info(sprintf('%d workspace(s) × %s messages = %s in total · %d chats each · %d per webhook · run %s',
            $count, number_format($perWorkspace), number_format($total), $chats, $chunk, $run));

        // ── 1. The flood: every workspace's webhooks, interleaved, as fast as possible ───────
        $customers = array_map(fn (int $i) => '97155'.str_pad((string) (1000000 + $i), 7, '0', STR_PAD_LEFT), range(1, $chats));
        $webhooksEach = (int) ceil($perWorkspace / $chunk);
        $started = microtime(true);
        $bar = $this->output->createProgressBar($webhooksEach * $count);

        for ($c = 0; $c < $webhooksEach; $c++) {
            foreach ($spaces as $space) {
                $business = preg_replace('/\D+/', '', (string) $space['number']->display_phone_number);
                $threads = [];
                for ($i = $c * $chunk; $i < min($perWorkspace, ($c + 1) * $chunk); $i++) {
                    $customer = $customers[$i % $chats];
                    $threads[$customer]['id'] = $customer;
                    $threads[$customer]['messages'][] = [
                        // Two in three are from the customer, one in three from the business (as sent from the phone).
                        'from' => $i % 3 === 2 ? $business : $customer,
                        'id' => "wamid.SIM_{$run}_{$space['index']}_{$i}",
                        'timestamp' => (string) now()->subMinutes($perWorkspace - $i)->timestamp, // oldest first, one a minute
                        'type' => 'text',
                        'text' => ['body' => "History message {$i}"],
                        'history_context' => ['status' => 'READ'],
                    ];
                }
                $body = $this->webhook($space['number'], 'history', [
                    'history' => [['metadata' => ['phase' => 1, 'chunk_order' => $c + 1, 'progress' => (int) floor(($c + 1) / $webhooksEach * 100)], 'threads' => array_values($threads)]],
                ]);
                $inbox->accept((string) json_encode($body), $body);
                $bar->advance();
            }
        }
        $bar->finish();
        $this->newLine(2);
        $accepted = microtime(true) - $started;
        $this->line(sprintf('  %d webhooks accepted in %.1f s (%.0f ms each). That is all the webhook endpoint itself has to do.', $webhooksEach * $count, $accepted, $accepted / ($webhooksEach * $count) * 1000));

        if ($this->option('no-wait')) {
            $this->line('  Not waiting (--no-wait). Watch Horizon: the "sync" queue drains in batches; run again with --cleanup when done.');

            return self::SUCCESS;
        }

        // ── 2. Watch: backlog, live-message latency, database connections, per-workspace finish ─
        $this->line('  Importing… Ctrl+C stops watching; the import carries on.');
        $deadline = time() + max(10, (int) $this->option('timeout'));
        $maxConnections = (int) (DB::selectOne('show max_connections')->max_connections ?? 0);
        $peakWaiting = $peakConnections = $peakQueue = 0;
        $probes = [];            // wamid → [sent at, workspace index]
        $latencies = [];
        $lastProbe = $lastLine = 0.0;
        $probeNo = 0;

        do {
            $now = microtime(true);

            // A live customer message every 5 seconds, to a different workspace each time.
            if ($now - $lastProbe >= 5) {
                $lastProbe = $now;
                $space = $spaces[$probeNo % $count];
                $id = "wamid.SIM_LIVE_{$run}_".(++$probeNo);
                $body = $this->webhook($space['number'], 'messages', [
                    'contacts' => [['wa_id' => '971559999999', 'profile' => ['name' => 'Live Test Customer']]],
                    'messages' => [['from' => '971559999999', 'id' => $id, 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => "Live message {$probeNo} during the import"]]],
                ]);
                $probes[$id] = microtime(true);
                $inbox->accept((string) json_encode($body), $body);
            }
            if ($probes !== []) {
                $arrived = $context->bypass(fn () => DB::table('messages')->whereIn('wamid', array_keys($probes))->pluck('wamid')->all());
                foreach ($arrived as $id) {
                    $latencies[] = microtime(true) - $probes[$id];
                    unset($probes[$id]);
                }
            }

            $waiting = (int) $context->bypass(fn () => DB::table('coexistence_sync_items')->whereIn('tenant_id', array_map(fn (array $s) => $s['tenant']->id, $spaces))->count());
            $connections = (int) (DB::selectOne('select count(*) as c from pg_stat_activity')->c ?? 0);
            $queue = $this->queueSize();
            $peakWaiting = max($peakWaiting, $waiting);
            $peakConnections = max($peakConnections, $connections);
            $peakQueue = max($peakQueue, $queue);

            $imported = 0;
            foreach ($spaces as $k => $space) {
                $in = $this->simulated($context, $space['tenant'], $run, $space['index']);
                $imported += $in;
                if ($space['done'] === null && $in >= $perWorkspace) {
                    $spaces[$k]['done'] = microtime(true) - $started;
                    $this->line(sprintf('    workspace %d finished after %.0f s', $space['index'], $spaces[$k]['done']));
                }
            }

            if ($now - $lastLine >= 5) {
                $lastLine = $now;
                $elapsed = max(1, $now - $started);
                $this->line(sprintf('    %5.0f s   imported %s / %s   waiting %s   %s per minute   db connections %d/%d   live %s',
                    $elapsed, number_format($imported), number_format($total), number_format($waiting), number_format($imported / $elapsed * 60),
                    $connections, $maxConnections, $latencies === [] ? '…' : sprintf('%.1f s (worst %.1f s)', end($latencies), max($latencies))));
            }

            $done = $waiting === 0 && $imported >= $total;
            if (! $done) {
                usleep(500_000);
            }
        } while (! $done && time() < $deadline);
        $duration = microtime(true) - $started;

        // Let the last live probes land (up to 15 s), so "worst latency" is not cut short.
        for ($i = 0; $i < 30 && $probes !== []; $i++) {
            usleep(500_000);
            foreach ($context->bypass(fn () => DB::table('messages')->whereIn('wamid', array_keys($probes))->pluck('wamid')->all()) as $id) {
                $latencies[] = microtime(true) - $probes[$id];
                unset($probes[$id]);
            }
        }

        // ── 3. Verify ────────────────────────────────────────────────────────────────────────
        $stored = $outbound = $outOfOrder = $conversations = $notSynced = 0;
        foreach ($spaces as $space) {
            $tenant = $space['tenant'];
            $like = "wamid.SIM\\_{$run}\\_{$space['index']}\\_%";
            [$s, $o, $c, $bad] = $context->bypass(function () use ($tenant, $like): array {
                $q = DB::table('messages')->where('tenant_id', $tenant->id)->where('wamid', 'like', $like);

                return [
                    (clone $q)->count(),
                    (clone $q)->where('direction', 'outbound')->count(),
                    (clone $q)->distinct()->count('conversation_id'),
                    (int) (DB::selectOne(
                        "select count(*) as n from (select occurred_at, lag(occurred_at) over (partition by conversation_id order by split_part(wamid, '_', 4)::int) as previous
                           from messages where tenant_id = ? and wamid like ?) t where previous is not null and occurred_at < previous", [$tenant->id, $like])->n ?? 0),
                ];
            });
            $stored += $s;
            $outbound += $o;
            $conversations += $c;
            $outOfOrder += $bad;

            // The last batch marks the number as synced a moment after it empties the list: give it that moment.
            $status = null;
            for ($i = 0; $i < 40; $i++) {
                $status = $context->bypass(fn () => DB::table('phone_numbers')->where('id', $space['number']->id)->value('coexistence_status'));
                if ($status === CoexistenceStatus::Synced->value) {
                    break;
                }
                usleep(500_000);
            }
            $notSynced += $status === CoexistenceStatus::Synced->value ? 0 : 1;
        }
        $waitingLeft = (int) $context->bypass(fn () => DB::table('coexistence_sync_items')->whereIn('tenant_id', array_map(fn (array $s) => $s['tenant']->id, $spaces))->count());
        $worst = $latencies === [] ? null : max($latencies);
        $average = $latencies === [] ? null : array_sum($latencies) / count($latencies);
        $finishes = array_filter(array_column($spaces, 'done'), fn ($d) => $d !== null);

        $checks = [
            ['All messages imported, none lost', $stored === $total, number_format($stored).' of '.number_format($total)],
            ['No duplicates', $stored <= $total, $stored > $total ? ($stored - $total).' extra' : 'none'],
            ['Waiting list empty', $waitingLeft === 0, number_format($waitingLeft).' left'],
            ['One conversation per customer', $conversations === $chats * $count, "{$conversations} of ".($chats * $count)],
            ['Direction kept (business vs customer)', $outbound === intdiv($perWorkspace, 3) * $count, number_format($outbound).' sent by the business'],
            ['Every conversation in true time order', $outOfOrder === 0, $outOfOrder === 0 ? 'yes' : "{$outOfOrder} out of order"],
            ['Every number marked as synced', $notSynced === 0, $notSynced === 0 ? "all {$count}" : "{$notSynced} still syncing"],
            ['Live messages never held up (worst under 15 s)', $worst !== null && $probes === [] && $worst < 15,
                $worst === null ? 'none arrived' : sprintf('%d probes · average %.1f s · worst %.1f s%s', count($latencies), $average, $worst, $probes !== [] ? ' · '.count($probes).' never arrived' : '')],
            ['Database connections stayed in budget (under 80%)', $maxConnections === 0 || $peakConnections < $maxConnections * 0.8, "peak {$peakConnections} of {$maxConnections}"],
        ];
        $this->newLine();
        $this->table(['Check', 'Result', 'Detail'], array_map(fn (array $c) => [$c[0], $c[1] ? 'PASS' : 'FAIL', $c[2]], $checks));
        $this->line(sprintf('  Total %.0f s · %s messages a minute overall · largest backlog %s records · sync queue peaked at %d job(s).',
            $duration, number_format($total / max(1, $duration) * 60), number_format($peakWaiting), $peakQueue));
        if ($finishes !== []) {
            $this->line(sprintf('  First workspace finished after %.0f s, the last after %.0f s.', min($finishes), max($finishes)));
        }
        $this->line('  The test data stays so you can look at it (Super Admin → Companies → "ZZ History load test"). Remove it with: php artisan engage:simulate-history --cleanup'.(app()->environment('production') ? ' --force' : ''));

        $passed = ! in_array(false, array_column($checks, 1), true);
        $passed ? $this->components->info('PASS: the import pipeline handled the flood.') : $this->components->error('FAIL: see the table above.');

        return $passed ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed> a webhook body shaped like Meta's
     */
    private function webhook(PhoneNumber $number, string $field, array $value): array
    {
        return ['object' => 'whatsapp_business_account', 'entry' => [['id' => $this->wabaId($number), 'time' => now()->timestamp, 'changes' => [[
            'field' => $field,
            'value' => ['messaging_product' => 'whatsapp', 'metadata' => ['display_phone_number' => preg_replace('/\D+/', '', (string) $number->display_phone_number), 'phone_number_id' => $number->phone_number_id]] + $value,
        ]]]]];
    }

    private function wabaId(PhoneNumber $number): string
    {
        return '9000000'.substr((string) $number->phone_number_id, -8, 7).'1';
    }

    /** @return array{0: Tenant, 1: PhoneNumber} test workspace number $index and its made-up coexistence number (created once, reused) */
    private function workspace(TenantContext $context, int $index): array
    {
        // Workspace 1 keeps the original names, so earlier single-workspace runs are reused and cleaned up too.
        $slug = $index === 1 ? self::SLUG : self::SLUG.'-'.$index;
        $phoneId = '9000000'.str_pad((string) $index, 7, '0', STR_PAD_LEFT).'2';
        $display = '+971 50 '.str_pad((string) $index, 3, '0', STR_PAD_LEFT).' 0002';

        $tenant = $context->bypass(fn () => Tenant::query()->where('slug', $slug)->first());
        if ($tenant === null) {
            $tenant = $context->bypass(fn () => Tenant::query()->create(['name' => 'ZZ History load test'.($index > 1 ? " {$index}" : ''), 'slug' => $slug]));
            app(SubscriptionService::class)->assign($tenant, Plan::byKey('pro')->activeVersionOrFail());
        }

        $number = $context->run($tenant, function () use ($context, $phoneId, $display, $index): PhoneNumber {
            $existing = PhoneNumber::query()->first();
            if ($existing !== null) {
                $existing->forceFill(['coexistence_status' => CoexistenceStatus::HistorySyncing])->save();
                DB::table('coexistence_sync_jobs')->where('phone_number_id', $existing->id)->update(['progress' => 0, 'status' => 'in_progress', 'completed_at' => null]);

                return $existing;
            }
            $token = MetaAccessToken::query()->create([
                'secret_id' => app(SecretStore::class)->put('meta.business_token', 'simulated-not-a-real-token', $context->id()), 'meta_app_id' => 'simulated',
            ]);
            $waba = WabaAccount::query()->create(['waba_id' => '9000000'.str_pad((string) $index, 7, '0', STR_PAD_LEFT).'1', 'access_token_id' => $token->id, 'status' => WabaStatus::Connected, 'is_subscribed_to_webhooks' => true, 'connected_at' => now()]);

            return PhoneNumber::query()->create([
                'waba_account_id' => $waba->id, 'phone_number_id' => $phoneId, 'display_phone_number' => $display, 'verified_name' => 'History load test',
                'status' => PhoneNumberStatus::Connected, 'onboarding_type' => OnboardingType::Coexistence, 'coexistence_status' => CoexistenceStatus::HistorySyncing,
                'quality_rating' => 'GREEN', 'messaging_limit_tier' => 'TIER_1K', 'capabilities' => PhoneNumber::capabilitiesFor(OnboardingType::Coexistence),
            ]);
        });

        return [$tenant, $number];
    }

    private function messages(TenantContext $context, Tenant $tenant): int
    {
        return (int) $context->bypass(fn () => DB::table('messages')->where('tenant_id', $tenant->id)->count());
    }

    /** History messages of this run stored for one workspace so far. */
    private function simulated(TenantContext $context, Tenant $tenant, string $run, int $index): int
    {
        return (int) $context->bypass(fn () => DB::table('messages')->where('tenant_id', $tenant->id)->where('wamid', 'like', "wamid.SIM\\_{$run}\\_{$index}\\_%")->count());
    }

    /** Jobs on the import lane right now (waiting, delayed or running); 0 when the queue cannot be asked. */
    private function queueSize(): int
    {
        try {
            return (int) Queue::size(QueueName::Sync->value);
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Removes every test workspace and everything in it. Tables are discovered (every table with
     * a tenant_id column) and emptied in whatever order their foreign keys allow.
     */
    private function cleanup(TenantContext $context): int
    {
        $tenants = $context->bypass(fn () => Tenant::query()->where('slug', self::SLUG)->orWhere('slug', 'like', self::SLUG.'-%')->get());
        if ($tenants->isEmpty()) {
            $this->components->info('Nothing to clean up: no test workspace exists.');

            return self::SUCCESS;
        }

        $tables = collect(DB::select("select table_name from information_schema.columns where table_schema = current_schema() and column_name = 'tenant_id'
            and table_name in (select table_name from information_schema.tables where table_schema = current_schema() and table_type = 'BASE TABLE')"))->pluck('table_name')->all();
        // Partitions are emptied through their parent table.
        $partitions = collect(DB::select('select c.relname from pg_inherits i join pg_class c on c.oid = i.inhrelid'))->pluck('relname')->all();
        $ids = $tenants->pluck('id')->all();
        $remaining = array_values(array_diff($tables, $partitions));

        $context->bypass(function () use ($ids, &$remaining): void {
            for ($pass = 0; $pass < 12 && $remaining !== []; $pass++) {
                foreach ($remaining as $i => $table) {
                    try {
                        DB::transaction(fn () => DB::table($table)->whereIn('tenant_id', $ids)->delete());
                        unset($remaining[$i]);
                    } catch (Throwable) {
                        // another table still points at these rows: try again next pass
                    }
                }
                $remaining = array_values($remaining);
            }
            if ($remaining === []) {
                try {
                    DB::table('tenants')->whereIn('id', $ids)->delete();
                } catch (Throwable) {
                    $remaining[] = 'tenants';
                }
            }
        });

        if ($remaining === []) {
            $this->components->info("{$tenants->count()} test workspace(s) and all their data were removed.");

            return self::SUCCESS;
        }
        // Some records are kept on purpose by the database (for example the append-only audit log).
        $left = (int) $context->bypass(fn () => DB::table('messages')->whereIn('tenant_id', $ids)->count());
        $this->components->warn("Test data removed ({$left} messages left). {$tenants->count()} empty workspace shell(s) named \"ZZ History load test\" stay, because these records cannot be deleted: ".implode(', ', $remaining).'. They have no users and are safe to ignore.');

        return self::SUCCESS;
    }
}
