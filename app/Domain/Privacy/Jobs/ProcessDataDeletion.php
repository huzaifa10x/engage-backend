<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Jobs;

use App\Domain\Privacy\Models\DataDeletionRequest;
use App\Domain\Tenancy\TenantContext;
use App\Support\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/**
 * Erases what 10X Engage holds about a Facebook Login user (the person who ran Embedded
 * Signup): the app-scoped user id and raw session data on signup attempts. Business assets
 * (WABA, numbers, business tokens) belong to the business, not the person, and are governed
 * by PARTNER_REMOVED / workspace deletion instead. End-customer WhatsApp history is out of scope.
 */
final class ProcessDataDeletion implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public function __construct(public readonly string $requestId)
    {
        $this->onQueue(QueueName::Maintenance->value);
    }

    public function handle(TenantContext $context): void
    {
        $context->bypass(function () {
            $request = DataDeletionRequest::query()->findOrFail($this->requestId);
            if ($request->status === 'completed') {
                return;
            }

            $request->forceFill(['status' => 'processing'])->save();

            $attempts = DB::table('embedded_signup_attempts')
                ->where('meta_user_id', $request->meta_user_id)
                ->update(['meta_user_id' => null, 'session_payload' => null, 'updated_at' => now()]);

            $request->forceFill([
                'status' => 'completed',
                'completed_at' => now(),
                'result' => ['signup_attempts_anonymized' => $attempts],
            ])->save();
        });
    }
}
