<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Messaging\Jobs\SendWhatsappMessage;
use App\Domain\WhatsApp\Jobs\ImportCoexistenceBatch;
use App\Support\Queue\QueueName;
use Tests\TestCase;

/**
 * The queue lanes, as production will actually run them. Guards three mistakes that are easy to
 * make and invisible until the system is under load:
 *
 *   • a queue that jobs are sent to but no production worker reads (jobs wait forever);
 *   • worker maximums that add up to more database connections than Postgres allows;
 *   • the heavy import lane being allowed to scale up with the size of the backlog.
 */
final class QueueLanesTest extends TestCase
{
    /** @return array<string, array<string, mixed>> supervisor name → effective production options */
    private function production(): array
    {
        $defaults = (array) config('horizon.defaults');
        $lanes = [];
        // Exactly what Horizon does: only supervisors LISTED for the environment run, each merged over its defaults.
        foreach ((array) config('horizon.environments.production') as $name => $overrides) {
            $lanes[$name] = array_merge($defaults[$name] ?? [], $overrides);
        }

        return $lanes;
    }

    public function test_every_queue_has_a_worker_in_production(): void
    {
        $consumed = collect($this->production())->flatMap(fn (array $lane) => (array) ($lane['queue'] ?? []))->unique()->values()->all();

        foreach (QueueName::cases() as $queue) {
            $this->assertContains($queue->value, $consumed, "Jobs sent to the \"{$queue->value}\" queue would never run in production: no supervisor in config/horizon.php → environments.production reads it.");
        }
        // And every listed supervisor is a real one with queues to read.
        foreach ($this->production() as $name => $lane) {
            $this->assertNotEmpty($lane['queue'] ?? [], "{$name} is listed for production but has no definition in horizon.defaults.");
        }
    }

    public function test_the_worker_budget_fits_the_database(): void
    {
        $workers = collect($this->production())->sum(fn (array $lane) => (int) $lane['maxProcesses']);
        $compose = (string) file_get_contents(base_path('deploy/docker-compose.prod.yml'));
        $this->assertSame(1, preg_match('/max_connections=\$\{POSTGRES_MAX_CONNECTIONS:-(\d+)\}/', $compose, $m), 'Postgres max_connections must be set explicitly in the production compose file.');
        $connections = (int) $m[1];

        // Workers may use at most 60% of the connections; the rest is for the web server, the scheduler, Reverb and admin tools.
        $this->assertLessThanOrEqual((int) floor($connections * 0.6), $workers, "Horizon can start {$workers} workers but Postgres only allows {$connections} connections.");
    }

    public function test_the_import_lane_is_a_small_fixed_pool_separate_from_live_traffic(): void
    {
        $lanes = $this->production();
        $sync = $lanes['supervisor-sync'];

        $this->assertSame(['sync'], $sync['queue']);
        $this->assertSame('simple', $sync['balance'], 'the import lane must not auto-scale with its backlog');
        $this->assertLessThanOrEqual(5, $sync['maxProcesses']);
        $this->assertSame('sync', (new ImportCoexistenceBatch('00000000-0000-0000-0000-000000000000'))->queue);

        // No live lane reads the sync queue, and sending stays on its own lane.
        foreach ($lanes as $name => $lane) {
            if ($name !== 'supervisor-sync') {
                $this->assertNotContains('sync', $lane['queue'], "{$name} must not take import work");
            }
        }
        $this->assertSame('messaging', (new SendWhatsappMessage('00000000-0000-0000-0000-000000000000', '00000000-0000-0000-0000-000000000000'))->queue);
    }
}
