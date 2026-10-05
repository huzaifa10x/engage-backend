<?php

declare(strict_types=1);

namespace App\Application\Templates;

use App\Application\WhatsApp\WhatsappCredentials;
use App\Domain\Templates\Enums\TemplateStatus;
use App\Domain\Templates\Models\MessageTemplate;
use App\Domain\WhatsApp\Enums\WabaStatus;
use App\Domain\WhatsApp\Models\WabaAccount;
use App\Infrastructure\Meta\GraphClient;
use Illuminate\Support\Facades\Cache;

/**
 * Mirrors a WABA's templates from Meta (the source of truth) into message_templates. Called
 * after onboarding, by webhooks, on a schedule and when a client opens the Templates page.
 * Must run inside the owning tenant's context.
 */
final class TemplateSync
{
    public function __construct(
        private readonly GraphClient $graph,
        private readonly WhatsappCredentials $credentials,
    ) {}

    /**
     * Full sync: upsert everything Meta lists, soft-delete what Meta no longer has.
     *
     * @return array{synced: int, removed: int}
     */
    public function syncWaba(WabaAccount $waba): array
    {
        if ($waba->status !== WabaStatus::Connected) {
            return ['synced' => 0, 'removed' => 0];
        }

        $nodes = $this->graph->listTemplates($waba->waba_id, $this->credentials->tokenFor($waba));

        $seen = [];
        foreach ($nodes as $node) {
            $seen[] = $this->apply($waba, $node)->id;
        }

        // Only reached when the whole list was fetched, so "not listed" really means deleted.
        $removed = MessageTemplate::query()->where('waba_account_id', $waba->id)
            ->when($seen !== [], fn ($query) => $query->whereNotIn('id', $seen))
            ->get()
            ->each(function (MessageTemplate $template): void {
                $template->forceFill(['status' => TemplateStatus::DELETED, 'last_synced_at' => now()])->save();
                $template->delete();
            })
            ->count();

        Cache::put(self::cacheKey($waba), now()->toIso8601String(), now()->addDay());

        return ['synced' => count($seen), 'removed' => $removed];
    }

    /**
     * Upsert one template node as returned by Graph.
     *
     * @param  array<string, mixed>  $node
     */
    public function apply(WabaAccount $waba, array $node): MessageTemplate
    {
        $name = (string) $node['name'];
        $language = (string) ($node['language'] ?? 'en');

        /** @var ?MessageTemplate $template */
        $template = MessageTemplate::query()->withTrashed()
            ->where('waba_account_id', $waba->id)->where('name', $name)->where('language', $language)->first();
        $template ??= new MessageTemplate(['waba_account_id' => $waba->id, 'name' => $name, 'language' => $language]);

        $quality = $node['quality_score'] ?? null;

        $template->fill(array_filter([
            'meta_template_id' => isset($node['id']) ? (string) $node['id'] : null,
            'category' => isset($node['category']) ? strtoupper((string) $node['category']) : null,
            'quality_score' => is_array($quality) ? ($quality['score'] ?? null) : (is_string($quality) ? $quality : null),
            'parameter_format' => isset($node['parameter_format']) ? strtoupper((string) $node['parameter_format']) : null,
        ], fn ($value) => $value !== null));

        // jsonb does not keep key order, so compare by value: an unchanged template must not be
        // rewritten (and re-announced to clients) on every sync.
        $components = is_array($node['components'] ?? null) ? array_values($node['components']) : null;
        if ($components !== null && self::canonical($components) !== self::canonical($template->components ?? [])) {
            $template->components = $components;
        }

        $template->status = TemplateStatus::normalize(isset($node['status']) ? (string) $node['status'] : null);
        $reason = isset($node['rejected_reason']) ? strtoupper((string) $node['rejected_reason']) : null;
        $template->rejected_reason = $reason === 'NONE' || $reason === '' ? null : $reason;
        $template->last_synced_at = now();

        if ($template->trashed()) {
            $template->restore(); // re-created on Meta with the same name + language
        }
        $template->save();

        return $template;
    }

    /** Key-order independent form of a JSON value. */
    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonical(...), $value);
    }

    /** When this WABA's templates were last fully synced (null = never / expired). */
    public function lastSyncedAt(WabaAccount $waba): ?string
    {
        $at = Cache::get(self::cacheKey($waba));

        return is_string($at) ? $at : null;
    }

    private static function cacheKey(WabaAccount $waba): string
    {
        return "templates:synced:{$waba->id}";
    }
}
