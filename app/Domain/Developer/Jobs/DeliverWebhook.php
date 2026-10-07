<?php

declare(strict_types=1);

namespace App\Domain\Developer\Jobs;

use App\Application\Notifications\WorkspaceMailer;
use App\Domain\Developer\Models\WebhookDelivery;
use App\Domain\Developer\Models\WebhookEndpoint;
use App\Domain\Developer\Services\UrlGuard;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Notifications\WebhookEndpointDisabledNotification;
use App\Support\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Sends one event to one customer endpoint.
 *
 *  • Signed: X-Engage-Signature is "t=<unix time>,v1=<HMAC-SHA256 of '<t>.<body>' with the
 *    endpoint's secret>", so the receiver can prove the request is ours and recent.
 *  • Retried on failure with growing waits (about 9 hours in total), then marked failed.
 *  • An endpoint that keeps failing is switched off and the workspace owners are emailed, so we
 *    do not hammer a dead URL forever.
 *  • Never follows redirects and re-checks the address is public right before sending.
 */
final class DeliverWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Seconds to wait before attempts 2, 3, 4, 5 and 6. */
    public const BACKOFF = [60, 300, 1800, 7200, 21600];

    public int $tries = 1; // retries are scheduled by this job itself, so each attempt is recorded

    public function __construct(public readonly string $deliveryId)
    {
        $this->onQueue(QueueName::Notifications->value);
    }

    public function handle(TenantContext $context, UrlGuard $guard, WorkspaceMailer $mailer): void
    {
        $delivery = $context->bypass(fn () => WebhookDelivery::query()->with('endpoint')->find($this->deliveryId));
        $endpoint = $delivery?->endpoint;
        if ($delivery === null || $endpoint === null || $delivery->status !== 'pending') {
            return;
        }
        $tenant = $context->bypass(fn () => Tenant::query()->find($delivery->tenant_id));
        if ($tenant === null) {
            return;
        }

        $context->run($tenant, function () use ($delivery, $endpoint, $guard, $mailer, $tenant): void {
            $endpoint->refresh(); // it may have been paused or switched off since this delivery was queued
            if ($endpoint->status !== 'active' && ! ($delivery->payload['test'] ?? false)) {
                $delivery->forceFill(['status' => 'failed', 'response_excerpt' => 'The endpoint was paused or disabled before this event could be sent.'])->save();

                return;
            }

            $body = (string) json_encode($delivery->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $timestamp = now()->timestamp;
            $started = microtime(true);
            $status = null;
            $excerpt = null;

            try {
                $guard->assertPublic($endpoint->url);
                $response = Http::timeout(10)->connectTimeout(5)->withoutRedirecting()
                    ->withHeaders([
                        'User-Agent' => '10X-Engage-Webhooks/1.0',
                        'X-Engage-Event' => $delivery->event,
                        'X-Engage-Delivery' => $delivery->id,
                        'X-Engage-Signature' => 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $endpoint->secret),
                    ])
                    ->withBody($body, 'application/json')->post($endpoint->url);
                $status = $response->status();
                $excerpt = mb_substr((string) $response->body(), 0, 500);
            } catch (Throwable $e) {
                $excerpt = mb_substr($e->getMessage(), 0, 500);
            }

            $attempts = $delivery->attempts + 1;
            $delivery->forceFill(['attempts' => $attempts, 'response_status' => $status, 'response_excerpt' => $excerpt, 'duration_ms' => (int) round((microtime(true) - $started) * 1000)]);

            if ($status !== null && $status >= 200 && $status < 300) {
                $delivery->forceFill(['status' => 'delivered', 'delivered_at' => now(), 'next_attempt_at' => null])->save();
                WebhookEndpoint::query()->whereKey($endpoint->id)->update(['consecutive_failures' => 0, 'last_success_at' => now()]);

                return;
            }

            $wait = self::BACKOFF[$attempts - 1] ?? null;
            if ($wait !== null && ! ($delivery->payload['test'] ?? false)) {
                $delivery->forceFill(['next_attempt_at' => now()->addSeconds($wait)])->save();
                self::dispatch($delivery->id)->delay($wait);
            } else {
                $delivery->forceFill(['status' => 'failed', 'next_attempt_at' => null])->save();
            }

            $this->recordFailure($endpoint, $tenant, $mailer);
        });
    }

    /** Counted in the database itself, so deliveries failing at the same moment cannot overwrite each other. */
    private function recordFailure(WebhookEndpoint $endpoint, Tenant $tenant, WorkspaceMailer $mailer): void
    {
        WebhookEndpoint::query()->whereKey($endpoint->id)->update(['consecutive_failures' => DB::raw('consecutive_failures + 1'), 'last_failure_at' => now()]);
        $failures = (int) WebhookEndpoint::query()->whereKey($endpoint->id)->value('consecutive_failures');

        if ($failures >= WebhookEndpoint::DISABLE_AFTER_FAILURES) {
            // Only the delivery that actually flips the switch sends the email.
            $switched = WebhookEndpoint::query()->whereKey($endpoint->id)->where('status', 'active')->update(['status' => 'disabled', 'disabled_at' => now()]);
            if ($switched === 1) {
                $mailer->toOwners($tenant, new WebhookEndpointDisabledNotification($tenant->name, $endpoint->url, $failures));
            }
        }
    }
}
