<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Conversions\Services\ConversionMonitoring;
use Illuminate\Console\Command;

/** Conversions to Meta: raises a Sentry alert when the 24-hour success rate is below 90% or an event is queued for more than 24 hours. */
final class MonitorConversions extends Command
{
    protected $signature = 'engage:conversions:monitor';

    protected $description = 'Alert in Sentry when Conversions to Meta fall below a 90% success rate or events are stuck in the queue';

    public function handle(ConversionMonitoring $monitoring): int
    {
        $alerts = $monitoring->alert();
        foreach ($alerts as $alert) {
            $this->warn($alert);
        }
        if ($alerts === []) {
            $this->info('Conversions to Meta: no alerts.');
        }

        return self::SUCCESS;
    }
}
