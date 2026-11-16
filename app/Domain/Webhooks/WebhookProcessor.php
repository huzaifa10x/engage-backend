<?php

declare(strict_types=1);

namespace App\Domain\Webhooks;

use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Webhooks\Handlers\WebhookHandler;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Domain\WhatsApp\Models\WabaAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Routes a stored change to its handler inside the owning tenant's context. Fields owned by
 * modules not built yet are marked `deferred` (replayable with engage:webhooks:replay);
 * unknown fields are `ignored` — never an error, so Meta never sees a failure.
 */
final class WebhookProcessor
{
    /** Stored now, processed by a later module (replay once it ships). */
    public const DEFERRED_FIELDS = [
        'calls', 'payment_configuration_update', 'flows', 'security', 'business_username_updates',
    ];

    /** @var array<string, WebhookHandler> */
    private array $handlers = [];

    /** @param iterable<WebhookHandler> $handlers */
    public function __construct(private readonly TenantContext $context, iterable $handlers)
    {
        foreach ($handlers as $handler) {
            foreach ($handler->fields() as $field) {
                $this->handlers[$field] = $handler;
            }
        }
    }

    public function process(string $id, string $receivedAt): void
    {
        // received_at bounds let Postgres prune to one partition; id is the real key.
        $at = Carbon::parse($receivedAt);
        $row = $this->context->bypass(fn () => DB::table('webhook_inbound_log')
            ->where('id', $id)
            ->whereBetween('received_at', [$at->copy()->subDay(), $at->copy()->addDay()])
            ->first());

        if ($row === null || in_array($row->process_status, ['processed', 'ignored'], true)) {
            return;
        }

        $payload = json_decode((string) $row->payload, true) ?: [];
        $value = is_array($payload['value'] ?? null) ? $payload['value'] : [];
        $occurredAt = isset($payload['entry_time']) && is_numeric($payload['entry_time'])
            ? Carbon::createFromTimestamp((int) $payload['entry_time']) : Carbon::parse($row->received_at);

        [$waba, $tenant] = $this->resolve($row->waba_id);

        // An account no workspace has connected (or one disconnected and since reused elsewhere):
        // nothing in it is applied to any workspace. The raw event stays in the inbound log, marked
        // unknown_account, and a warning is logged at most once an hour per account and field.
        // account_update is the one exception: a business-level event names no account of ours.
        if ($tenant === null && $row->field !== 'account_update' && isset($this->handlers[$row->field])) {
            $this->mark($row, ProcessResult::Ignored->value, 'unknown_account', null);
            if (Cache::add('webhook:unknown-account:'.($row->waba_id ?? 'none').':'.$row->field, 1, now()->addHour())) {
                Log::warning('Webhook for an unknown WhatsApp account dropped', [
                    'waba_id' => $row->waba_id, 'phone_number_id' => $row->phone_number_id, 'field' => $row->field, 'webhook_log_id' => $row->id,
                ]);
            }

            return;
        }

        try {
            $result = $this->dispatch($row, $value, $occurredAt, $waba, $tenant);
            $this->mark($row, $result->value, null, $tenant?->id);
        } catch (Throwable $e) {
            $this->mark($row, 'failed', mb_substr($e::class.': '.$e->getMessage(), 0, 2000), $tenant?->id);

            throw $e;
        }
    }

    /** @param array<string, mixed> $value */
    private function dispatch(object $row, array $value, Carbon $occurredAt, ?WabaAccount $waba, ?Tenant $tenant): ProcessResult
    {
        $handler = $this->handlers[$row->field] ?? null;

        if ($handler === null) {
            return in_array($row->field, self::DEFERRED_FIELDS, true) ? ProcessResult::Deferred : ProcessResult::Ignored;
        }

        $work = function () use ($handler, $row, $value, $occurredAt, $waba) {
            $number = $row->phone_number_id !== null
                ? PhoneNumber::query()->where('phone_number_id', $row->phone_number_id)->first()
                : null;

            return DB::transaction(fn () => $handler->handle(new WebhookChange(
                (string) $row->id, (string) $row->field, $row->waba_id, $value, $occurredAt, $waba?->fresh(), $number,
            )));
        };

        return $tenant !== null ? $this->context->run($tenant, $work) : $handler->handle(
            new WebhookChange((string) $row->id, (string) $row->field, $row->waba_id, $value, $occurredAt, null, null)
        );
    }

    /** @return array{0: ?WabaAccount, 1: ?Tenant} */
    private function resolve(?string $wabaId): array
    {
        if ($wabaId === null) {
            return [null, null];
        }

        return $this->context->bypass(function () use ($wabaId) {
            $waba = WabaAccount::query()->where('waba_id', $wabaId)->first();

            return [$waba, $waba ? Tenant::query()->find($waba->tenant_id) : null];
        });
    }

    private function mark(object $row, string $status, ?string $error, ?string $tenantId): void
    {
        $this->context->bypass(fn () => DB::table('webhook_inbound_log')
            ->where('id', $row->id)->where('received_at', $row->received_at)
            ->update([
                'process_status' => $status,
                'tenant_id' => $tenantId,
                'attempts' => DB::raw('attempts + 1'),
                'last_error' => $error,
                'processed_at' => in_array($status, ['processed', 'ignored', 'deferred'], true) ? now() : null,
            ]));
    }
}
