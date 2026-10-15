<?php

declare(strict_types=1);

namespace App\Domain\Chatbots\Services;

use App\Domain\Chatbots\Engine\FlowGraph;
use App\Domain\Chatbots\Jobs\StartChatbotsOnChange;
use App\Domain\Chatbots\Models\ChatbotFlow;
use App\Domain\Chatbots\Models\ChatbotFlowVersion;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;

/**
 * "Data change" triggers: when a contact is saved with a new tag or a changed custom field that a
 * live flow starts on, that flow starts for the contact (StartChatbotsOnChange). Which tags and
 * fields matter is cached per workspace for a minute (and cleared on publish / switch on), so a
 * contact import does not query the flows for every row.
 */
final class DataTriggers
{
    public static function key(string $tenantId): string
    {
        return "chatbot:data-triggers:{$tenantId}";
    }

    public static function forget(string $tenantId): void
    {
        Cache::forget(self::key($tenantId));
    }

    /** @return array{tags: list<string>, fields: list<string>} */
    public static function watched(string $tenantId): array
    {
        return Cache::remember(self::key($tenantId), 60, function (): array {
            $tags = [];
            $fields = [];
            $flows = ChatbotFlow::query()->where('is_active', true)->where('kind', 'bot')->whereNotNull('published_version')->whereNull('archived_at')->get();
            foreach ($flows as $flow) {
                $graph = ChatbotFlowVersion::query()->where('flow_id', $flow->id)->where('version', $flow->published_version)->value('graph');
                $c = FlowGraph::from(is_array($graph) ? $graph : json_decode((string) $graph, true))->start()['config'] ?? null;
                if ($c !== null) {
                    array_push($tags, ...array_map('mb_strtolower', $c['tags_added']));
                    array_push($fields, ...$c['fields_changed']);
                }
            }

            return ['tags' => array_values(array_unique($tags)), 'fields' => array_values(array_unique($fields))];
        });
    }

    /** Contact model "saved" hook. */
    public static function saved(Contact $contact): void
    {
        $context = app(TenantContext::class);
        $tenantId = $contact->tenant_id;
        if ($context->tenantOrNull()?->getKey() !== $tenantId) {
            return;
        }
        $watched = self::watched($tenantId);
        if ($watched['tags'] === [] && $watched['fields'] === []) {
            return;
        }
        $before = $contact->wasRecentlyCreated ? [] : array_map('mb_strtolower', (array) self::decode($contact->getOriginal('tags')));
        $added = array_values(array_filter((array) ($contact->tags ?? []), fn (string $t) => ! in_array(mb_strtolower($t), $before, true) && in_array(mb_strtolower($t), $watched['tags'], true)));

        $old = $contact->wasRecentlyCreated ? [] : (array) self::decode($contact->getOriginal('custom_fields'));
        $new = (array) ($contact->custom_fields ?? []);
        $changed = [];
        foreach ($watched['fields'] as $key) {
            if (array_key_exists($key, $new) && ($new[$key] ?? null) !== ($old[$key] ?? null) && $new[$key] !== null && $new[$key] !== '') {
                $changed[] = $key;
            }
        }
        if ($added !== [] || $changed !== []) {
            StartChatbotsOnChange::dispatch($contact->id, $added, $changed)->afterCommit();
        }
    }

    private static function decode(mixed $value): mixed
    {
        return is_string($value) ? json_decode($value, true) : $value;
    }
}
