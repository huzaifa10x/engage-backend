<?php

declare(strict_types=1);

namespace App\Domain\Webhooks;

use App\Domain\Tenancy\TenantContext;
use App\Domain\Webhooks\Jobs\ProcessWebhookChange;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Stores verified Meta deliveries. Two layers of idempotency:
 *   1. delivery: sha256(raw body) in webhook_deliveries — Meta retries identical bodies
 *   2. handlers are idempotent per change (a status for a wamid, a quality event, ...)
 */
final class WebhookInbox
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  array<string, mixed>  $body
     * @return int changes stored (0 = duplicate delivery)
     */
    public function accept(string $rawBody, array $body): int
    {
        $hash = hash('sha256', $rawBody);
        $object = (string) ($body['object'] ?? 'unknown');
        $rows = [];

        foreach ((array) ($body['entry'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                if (! is_array($change) || ! isset($change['field'])) {
                    continue;
                }
                $value = is_array($change['value'] ?? null) ? $change['value'] : [];

                $rows[] = [
                    'id' => (string) Str::uuid7(),
                    'object' => $object,
                    'waba_id' => isset($entry['id']) ? (string) $entry['id'] : null,
                    'phone_number_id' => isset($value['metadata']['phone_number_id']) ? (string) $value['metadata']['phone_number_id'] : null,
                    'field' => mb_substr((string) $change['field'], 0, 64),
                    'delivery_hash' => $hash,
                    'payload' => json_encode(['entry_time' => $entry['time'] ?? null, 'value' => $value], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'received_at' => now(),
                ];
            }
        }

        $stored = $this->context->bypass(fn () => DB::transaction(function () use ($hash, $rows) {
            $inserted = DB::table('webhook_deliveries')->insertOrIgnore([
                'delivery_hash' => $hash, 'changes' => count($rows), 'received_at' => now(),
            ]);

            if ($inserted === 0) {
                return 0; // duplicate delivery
            }

            if ($rows !== []) {
                DB::table('webhook_inbound_log')->insert($rows);
            }

            return count($rows);
        }));

        if ($stored > 0) {
            foreach ($rows as $row) {
                ProcessWebhookChange::dispatch($row['id'], $row['received_at']->toIso8601String())->afterCommit();
            }
        }

        return $stored;
    }
}
