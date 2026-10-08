<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

Schedule::command('engage:partitions')->dailyAt('01:00')->onOneServer()->withoutOverlapping();
Schedule::command('engage:subscriptions:expire-trials')->everyFifteenMinutes()->onOneServer()->withoutOverlapping();
Schedule::command('horizon:snapshot')->everyFiveMinutes();
Schedule::command('queue:prune-failed --hours=336')->daily()->onOneServer();
// Behind Stripe's webhooks: ended or unpaid subscriptions always fall back to the Free plan.
Schedule::command('engage:coexistence:import')->everyMinute()->onOneServer()->withoutOverlapping();
// Removed access at Meta shows up here within ten minutes even when no webhook arrives and nothing is being sent.
Schedule::command('engage:whatsapp:verify-access')->everyTenMinutes()->onOneServer()->withoutOverlapping();
Schedule::command('engage:coexistence:watch')->hourly()->onOneServer()->withoutOverlapping();
Schedule::command('engage:billing:reconcile')->hourly()->onOneServer()->withoutOverlapping();
Schedule::command('engage:retention:apply')->dailyAt('02:30')->onOneServer()->withoutOverlapping();
Schedule::command('engage:inbox:wake-snoozed')->everyMinute()->onOneServer()->withoutOverlapping();
Schedule::command('engage:campaigns:dispatch')->everyMinute()->onOneServer()->withoutOverlapping();
// Safety net behind the template webhooks (statuses, edits and deletions made on Meta).
Schedule::command('engage:templates:sync')->everyFiveMinutes()->onOneServer()->withoutOverlapping();
Schedule::command('engage:webhooks:prune')->dailyAt('02:30')->onOneServer()->withoutOverlapping();
