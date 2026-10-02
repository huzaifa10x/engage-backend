<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Messaging\Enums\MessageStatus;
use App\Domain\Messaging\Jobs\SendWhatsappMessage;
use App\Domain\Messaging\Models\Message;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Support\Meta\MetaErrorCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Throwable;

/**
 * Answers "why are messages not going out?" in one screen: what happened to recent outbound
 * messages, the exact Meta error for the failed ones, and whether anything is stuck in the queue.
 */
final class DiagnoseMessages extends Command
{
    protected $signature = 'engage:messages:diagnose {--hours=24 : How far back to look} {--retry-stuck : Queue stuck messages again}';

    protected $description = 'Show the status and Meta errors of recent outbound WhatsApp messages.';

    public function handle(TenantContext $context): int
    {
        $since = now()->subHours(max(1, (int) $this->option('hours')));

        /** @var Collection<int, Message> $messages */
        $messages = $context->bypass(fn () => Message::query()
            ->where('direction', Message::OUTBOUND)->where('created_at', '>=', $since)
            ->orderByDesc('created_at')->limit(500)->get());

        if ($messages->isEmpty()) {
            $this->components->warn('No outbound messages in this period. If you pressed Send and nothing is listed, the request never reached the server (check the browser for an error message).');

            return self::SUCCESS;
        }

        $this->components->info('Outbound messages since '.$since->toDateTimeString());
        $this->table(['Status', 'Messages'], $messages->groupBy(fn (Message $m) => $m->status->value)->map(fn ($group, $status) => [$status, $group->count()])->values()->all());

        $failed = $messages->where('status', MessageStatus::Failed)->take(15);
        if ($failed->isNotEmpty()) {
            $this->components->error('Failed messages (newest first)');
            foreach ($failed as $message) {
                $this->line(sprintf('  %s  %-9s  code %s  %s', $message->created_at?->toDateTimeString(), $message->type, $message->error_code ?? '-', $message->error_title ?? ''));
                if (($hint = MetaErrorCatalog::hint($message->error_code)) !== null) {
                    $this->line('      → '.$hint);
                }
            }
        }

        $stuck = $messages->filter(fn (Message $m) => $m->status === MessageStatus::Queued && $m->created_at?->lt(now()->subMinutes(2)));
        if ($stuck->isNotEmpty()) {
            $this->components->error("{$stuck->count()} message(s) have been waiting in the queue for more than 2 minutes — the queue worker (Horizon) is not sending them.");
            $this->line('  Horizon: '.$this->horizonStatus());

            if ($this->option('retry-stuck')) {
                $this->retry($context, $stuck->all());
            } else {
                $this->line('  Run again with --retry-stuck to queue them once more after fixing the worker.');
            }
        } else {
            $this->components->info('Nothing is stuck in the queue. Horizon: '.$this->horizonStatus());
        }

        return self::SUCCESS;
    }

    /** @param array<int, Message> $messages */
    private function retry(TenantContext $context, array $messages): void
    {
        $queued = 0;
        foreach ($messages as $message) {
            $tenant = $context->bypass(fn () => Tenant::query()->find($message->tenant_id));
            $number = $context->bypass(fn () => PhoneNumber::query()->find($message->phone_number_id));
            if ($tenant === null || $number === null) {
                continue;
            }
            // Dispatched inside the tenant context so the job is restored into the same workspace.
            // (a statement, not an arrow function: the job must be pushed before run() returns).
            $context->run($tenant, function () use ($message, $number): void {
                SendWhatsappMessage::dispatch($message->id, $number->id, $number->max_mps);
            });
            $queued++;
        }

        $this->components->info("Queued {$queued} message(s) again.");
    }

    private function horizonStatus(): string
    {
        try {
            $masters = app(MasterSupervisorRepository::class)->all();
        } catch (Throwable $e) {
            return 'could not be checked ('.$e->getMessage().')';
        }

        if ($masters === []) {
            return 'NOT RUNNING — restart the backend services (deploy.sh restart).';
        }

        return collect($masters)->map(fn ($master) => (string) ($master->status ?? 'unknown'))->unique()->implode(', ');
    }
}
