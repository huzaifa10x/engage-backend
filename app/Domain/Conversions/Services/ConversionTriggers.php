<?php

declare(strict_types=1);

namespace App\Domain\Conversions\Services;

use App\Domain\Commerce\Models\CommerceOrder;
use App\Domain\Conversions\ConversionsAccess;
use App\Domain\Conversions\Jobs\RecordConversion;
use App\Domain\Conversions\Models\ConversionRule;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Where conversions are noticed: a tag added to a contact, a flow step reached in a real (never a
 * test) run, a paid order linked to a chat. Each check is cheap and only queues a job (maintenance
 * queue, after the change is saved); nothing here can fail or slow down a message, a flow or an
 * order. Workspaces without the conversions flag stop at the first line.
 */
final class ConversionTriggers
{
    /** Contact "saved" hook: tags added in this save. */
    public static function contactSaved(Contact $contact): void
    {
        self::guard(function () use ($contact) {
            $tenant = app(TenantContext::class)->tenantOrNull();
            if ($tenant === null || $tenant->getKey() !== $contact->tenant_id || ! ConversionsAccess::allowed($tenant)) {
                return;
            }
            $watched = ConversionRules::watched($tenant->id)['tags'];
            if ($watched === []) {
                return;
            }
            $original = $contact->getOriginal('tags');
            $before = $contact->wasRecentlyCreated ? [] : array_map('mb_strtolower', (array) (is_string($original) ? json_decode($original, true) : $original));
            foreach ((array) ($contact->tags ?? []) as $tag) {
                $tag = mb_strtolower((string) $tag);
                if (! in_array($tag, $before, true) && in_array($tag, $watched, true)) {
                    RecordConversion::dispatch($tenant->id, ConversionRule::TAG, $tag, contactId: $contact->id, at: now()->toIso8601String())->afterCommit();
                }
            }
        });
    }

    /** A real run of a flow reached a step (LiveRuntime only: test runs never get here). */
    public static function flowStepReached(string $conversationId, string $contactId, string $flowId, string $stepId): void
    {
        self::guard(function () use ($conversationId, $contactId, $flowId, $stepId) {
            $tenant = app(TenantContext::class)->tenantOrNull();
            if ($tenant === null || ! ConversionsAccess::allowed($tenant) || ! in_array("{$flowId}:{$stepId}", ConversionRules::watched($tenant->id)['steps'], true)) {
                return;
            }
            RecordConversion::dispatch($tenant->id, ConversionRule::FLOW_STEP, "{$flowId}:{$stepId}", $conversationId, $contactId, now()->toIso8601String())->afterCommit();
        });
    }

    /** A WooCommerce order changed: once it is paid and linked to a chat (link or phone), it is a purchase. */
    public static function orderChanged(CommerceOrder $order): void
    {
        self::guard(function () use ($order) {
            $tenant = app(TenantContext::class)->tenantOrNull();
            if ($tenant === null || ! $order->paid || $order->contact_id === null || ! in_array($order->match, [CommerceOrder::LINK, CommerceOrder::PHONE], true)
                || ! ConversionsAccess::allowed($tenant) || ! ConversionRules::watched($tenant->id)['orders']) {
                return;
            }
            RecordConversion::dispatch($tenant->id, ConversionRule::ORDER, null, $order->conversation_id, $order->contact_id, ($order->paid_at ?? $order->placed_at)->toIso8601String(), $order->id)->afterCommit();
        });
    }

    private static function guard(callable $check): void
    {
        try {
            $check();
        } catch (Throwable $e) {
            Log::warning('Conversion check skipped', ['error' => $e->getMessage()]);
        }
    }
}
