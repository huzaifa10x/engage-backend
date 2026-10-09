<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

Schedule::command('engage:partitions')->dailyAt('01:00')->onOneServer()->withoutOverlapping();
Schedule::command('engage:subscriptions:expire-trials')->everyFifteenMinutes()->onOneServer()->withoutOverlapping();
Schedule::command('horizon:snapshot')->everyFiveMinutes();
Schedule::command('queue:prune-failed --hours=336')->daily()->onOneServer();
// Behind Stripe's webhooks: ended or unpaid subscriptions always fall back to the Free plan.
Schedule::command('engage:coexistence:import')->everyMinute()->onOneServer()->withoutOverlapping();
// Fallback for when Meta sends no webhook: a disconnection at Meta shows here within two minutes.
// (With a webhook it is seconds.) Costs two small API calls per connected account per run.
Schedule::command('engage:whatsapp:verify-access')->everyTwoMinutes()->onOneServer()->withoutOverlapping();
Schedule::command('engage:coexistence:watch')->hourly()->onOneServer()->withoutOverlapping();
Schedule::command('engage:billing:reconcile')->hourly()->onOneServer()->withoutOverlapping();
Schedule::command('engage:retention:apply')->dailyAt('02:30')->onOneServer()->withoutOverlapping();
Schedule::command('engage:inbox:wake-snoozed')->everyMinute()->onOneServer()->withoutOverlapping();
Schedule::command('engage:campaigns:dispatch')->everyMinute()->onOneServer()->withoutOverlapping();
// Safety net behind the template webhooks (statuses, edits and deletions made on Meta).
Schedule::command('engage:templates:sync')->everyFiveMinutes()->onOneServer()->withoutOverlapping();
Schedule::command('engage:webhooks:prune')->dailyAt('02:30')->onOneServer()->withoutOverlapping();
// Integrations: abandoned-checkout reminders go out when their waiting time has passed.
Schedule::command('engage:integrations:run')->everyMinute()->onOneServer()->withoutOverlapping();
