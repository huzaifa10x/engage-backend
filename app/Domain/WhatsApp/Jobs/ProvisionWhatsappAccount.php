<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\Jobs;

use App\Application\WhatsApp\PhoneNumberSync;
use App\Application\WhatsApp\WhatsappCredentials;
use App\Domain\Audit\AuditLogger;
use App\Domain\WhatsApp\Enums\CoexistenceStatus;
use App\Domain\WhatsApp\Enums\PhoneNumberStatus;
use App\Domain\WhatsApp\Enums\SignupStatus;
use App\Domain\WhatsApp\Models\CoexistenceSyncJob;
use App\Domain\WhatsApp\Models\EmbeddedSignupAttempt;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Domain\WhatsApp\Models\WabaAccount;
use App\Infrastructure\Meta\GraphClient;
use App\Infrastructure\Meta\MetaApiException;
use App\Infrastructure\Secrets\SecretStore;
use App\Support\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * The server-side onboarding calls after the code exchange. Every step is idempotent and
 * recorded on the attempt, so a retry resumes where the last run stopped:
 *   fetch_waba → subscribe_webhooks → register_number (skipped for coexistence / no number)
 *   → fetch_number → coexistence_sync (contacts, then history — each allowed once, within 24h)
 *
 * Runs in the tenant's context (restored from Laravel Context by JobTenantContext).
 */
final class ProvisionWhatsappAccount implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 6;

    public function __construct(public readonly string $attemptId)
    {
        $this->onQueue(QueueName::Critical->value);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 60, 120, 300];
    }

    public function handle(GraphClient $graph, WhatsappCredentials $credentials, SecretStore $secrets, PhoneNumberSync $sync, AuditLogger $audit): void
    {
        $attempt = EmbeddedSignupAttempt::query()->find($this->attemptId);
        if ($attempt === null || $attempt->status !== SignupStatus::Provisioning) {
            return;
        }

        /** @var WabaAccount $waba */
        $waba = WabaAccount::query()->findOrFail($attempt->waba_account_id);
        $number = $attempt->phone_number_id !== null
            ? PhoneNumber::query()->where('phone_number_id', $attempt->phone_number_id)->first()
            : null;

        try {
            $token = $credentials->tokenFor($waba);

            $this->step($attempt, 'fetch_waba', function () use ($graph, $waba, $token, $sync) {
                $sync->applyWaba($waba, $graph->getWaba($waba->waba_id, $token));
            });

            $this->step($attempt, 'subscribe_webhooks', function () use ($graph, $waba, $token) {
                $graph->subscribeApp($waba->waba_id, $token);
                $waba->forceFill(['is_subscribed_to_webhooks' => true])->save();
            });

            if ($number !== null) {
                if (! $number->isCoexistence()) {
                    $this->step($attempt, 'register_number', function () use ($graph, $number, $token, $secrets) {
                        if ($number->pin_secret_id === null) {
                            $pin = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                            $number->forceFill(['pin_secret_id' => $secrets->put('whatsapp.registration_pin', $pin, $number->tenant_id)])->save();
                        }
                        $graph->registerPhoneNumber($number->phone_number_id, $secrets->get((string) $number->pin_secret_id), $token);
                        $number->forceFill(['registered_at' => now()])->save();
                    });
                }

                $this->step($attempt, 'fetch_number', function () use ($graph, $number, $token, $sync) {
                    $sync->applyPhoneNumber($number, $graph->getPhoneNumber($number->phone_number_id, $token));
                    $number->forceFill(['status' => PhoneNumberStatus::Connected])->save();
                });

                if ($number->isCoexistence()) {
                    $this->step($attempt, 'coexistence_sync', fn () => $this->startCoexistenceSync($graph, $number, $token));
                }
            }
        } catch (MetaApiException $e) {
            if ($e->isTransient() && $this->attempts() < $this->tries) {
                throw $e; // retry with backoff; completed steps are skipped next time
            }
            $attempt->fail($e->getMessage(), (string) ($e->metaCode ?? $e->httpStatus));
            $audit->record('whatsapp.provisioning_failed', $waba, meta: ['step' => $this->lastStep($attempt)] + $e->context());

            return;
        }

        $attempt->forceFill(['status' => SignupStatus::Completed, 'finished_at' => now()])->save();
        $audit->record('whatsapp.number_connected', $number ?? $waba, after: array_filter([
            'waba_id' => $waba->waba_id,
            'phone_number_id' => $number?->phone_number_id,
            'display_phone_number' => $number?->display_phone_number,
            'onboarding_type' => $number?->onboarding_type->value,
        ]));
    }

    public function failed(?Throwable $e): void
    {
        EmbeddedSignupAttempt::query()->find($this->attemptId)?->fail($e?->getMessage() ?? 'Provisioning failed.', 'provisioning_failed');
    }

    /** Both SMB App Data syncs, immediately: Meta allows each once, within 24 hours of onboarding. */
    private function startCoexistenceSync(GraphClient $graph, PhoneNumber $number, string $token): void
    {
        $number->forceFill([
            'coexistence_status' => CoexistenceStatus::SyncPending,
            'app_sync_started_at' => $number->getAttribute('app_sync_started_at') ?? now(),
            'app_sync_expires_at' => $number->app_sync_expires_at ?? now()->addDay(),
        ])->save();

        foreach (['smb_app_state_sync', 'history'] as $type) {
            $job = CoexistenceSyncJob::query()->firstOrCreate(
                ['phone_number_id' => $number->id, 'sync_type' => $type],
                ['status' => 'requested'],
            );

            if ($job->getAttribute('request_id') !== null) {
                continue; // already requested — never call twice
            }

            $requestId = $graph->requestSmbAppData($number->phone_number_id, $type, $token);
            $job->forceFill(['request_id' => $requestId, 'requested_at' => now(), 'status' => 'in_progress'])->save();
        }

        $number->forceFill(['coexistence_status' => CoexistenceStatus::HistorySyncing])->save();
    }

    private function step(EmbeddedSignupAttempt $attempt, string $name, callable $work): void
    {
        if ($attempt->stepDone($name)) {
            return;
        }

        try {
            $work();
        } catch (MetaApiException $e) {
            $attempt->markStep($name, 'failed', $e->getMessage());
            throw $e;
        }

        $attempt->markStep($name, 'done');
    }

    private function lastStep(EmbeddedSignupAttempt $attempt): ?string
    {
        return array_key_last($attempt->steps ?? []);
    }
}
