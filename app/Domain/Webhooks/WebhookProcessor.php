<?php

declare(strict_types=1);

namespace App\Domain\Webhooks;

use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Webhooks\Handlers\WebhookHandler;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Domain\WhatsApp\Models\WabaAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
        'messages', 'smb_message_echoes', 'message_template_status_update', 'message_template_quality_update',
        'message_template_components_update', 'template_category_update', 'message_echoes', 'calls',
        'payment_configuration_update', 'flows', 'security', 'user_preferences',
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
