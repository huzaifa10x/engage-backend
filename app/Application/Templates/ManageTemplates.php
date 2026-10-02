<?php

declare(strict_types=1);

namespace App\Application\Templates;

use App\Application\WhatsApp\WhatsappCredentials;
use App\Domain\Audit\AuditLogger;
use App\Domain\Plans\Entitlements\EntitlementService;
use App\Domain\Plans\FeatureKey;
use App\Domain\Templates\Enums\TemplateStatus;
use App\Domain\Templates\Exceptions\TemplateException;
use App\Domain\Templates\Models\MessageTemplate;
use App\Domain\Templates\Services\TemplateComponents;
use App\Domain\Tenancy\TenantContext;
use App\Domain\WhatsApp\Enums\WabaStatus;
use App\Domain\WhatsApp\Exceptions\WhatsappException;
use App\Domain\WhatsApp\Models\WabaAccount;
use App\Infrastructure\Meta\GraphClient;
use App\Infrastructure\Meta\MetaApiException;
use Illuminate\Validation\ValidationException;

/**
 * Create / edit / delete templates ON META first, then mirror the result locally — a template
 * never exists here that Meta does not know about.
 */
final class ManageTemplates
{
    private const EDITABLE = [TemplateStatus::APPROVED, TemplateStatus::REJECTED, TemplateStatus::PAUSED];

    public function __construct(
        private readonly GraphClient $graph,
        private readonly WhatsappCredentials $credentials,
        private readonly TemplateComponents $components,
        private readonly EntitlementService $entitlements,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $data validated form: name, language, category, header, body, body_examples, footer, buttons */
    public function create(WabaAccount $waba, array $data): MessageTemplate
    {
        $token = $this->tokenFor($waba);
        $name = (string) $data['name'];
        $language = (string) $data['language'];

        $existing = MessageTemplate::query()->where('waba_account_id', $waba->id)->where('name', $name)->where('language', $language)->exists();
        if ($existing) {
            throw ValidationException::withMessages(['name' => 'A template with this name and language already exists on this WhatsApp account.']);
        }

        $components = $this->components->build($data);

        return $this->entitlements->withinLimit($this->context->tenant(), FeatureKey::MessageTemplates, 1, function () use ($waba, $token, $name, $language, $data, $components) {
            try {
                $created = $this->graph->createTemplate($waba->waba_id, [
                    'name' => $name,
                    'language' => $language,
                    'category' => (string) $data['category'],
                    'components' => $components,
                ], $token);
            } catch (MetaApiException $e) {
                throw TemplateException::meta($e, 'create');
            }

            /** @var ?MessageTemplate $template */
            $template = MessageTemplate::query()->withTrashed()
                ->where('waba_account_id', $waba->id)->where('name', $name)->where('language', $language)->first();
            $template ??= new MessageTemplate(['waba_account_id' => $waba->id, 'name' => $name, 'language' => $language]);
            if ($template->trashed()) {
                $template->restore();
            }

            $template->fill([
                'meta_template_id' => $created['id'],
                'category' => strtoupper($created['category'] ?? (string) $data['category']),
                'status' => TemplateStatus::normalize($created['status']),
                'components' => $components,
                'rejected_reason' => null,
                'created_by_membership_id' => $this->context->membership()?->id,
                'last_synced_at' => now(),
            ])->save();

            $this->audit->record('template.submitted', $template, after: ['name' => $name, 'language' => $language, 'status' => $template->status]);

            return $template;
        });
    }

    /** @param array<string, mixed> $data same form as create (name and language cannot change on Meta) */
    public function update(MessageTemplate $template, array $data): MessageTemplate
    {
        if (! in_array($template->status, self::EDITABLE, true) || $template->meta_template_id === null) {
            throw TemplateException::notEditable(strtolower(str_replace('_', ' ', $template->status)));
        }

        $waba = WabaAccount::query()->findOrFail($template->waba_account_id);
        $token = $this->tokenFor($waba);

        $components = $this->components->build($data);
        $changes = ['components' => $components];
        // Meta does not allow changing the category of an approved template.
        if ($template->status !== TemplateStatus::APPROVED && ! empty($data['category'])) {
            $changes['category'] = (string) $data['category'];
        }

        try {
            $this->graph->updateTemplate($template->meta_template_id, $changes, $token);
        } catch (MetaApiException $e) {
            throw TemplateException::meta($e, 'update');
        }

        $before = ['status' => $template->status];
        $template->fill([
            'components' => $components,
            'category' => $changes['category'] ?? $template->category,
            'status' => TemplateStatus::PENDING, // edits are reviewed again
            'rejected_reason' => null,
            'last_synced_at' => now(),
        ])->save();

        $this->audit->record('template.updated', $template, before: $before, after: ['status' => $template->status]);

        return $template;
    }

    public function delete(MessageTemplate $template): void
    {
        $waba = WabaAccount::query()->findOrFail($template->waba_account_id);

        try {
            $this->graph->deleteTemplate($waba->waba_id, $template->name, $template->meta_template_id, $this->tokenFor($waba));
        } catch (MetaApiException $e) {
            throw TemplateException::meta($e, 'delete');
        }

        $template->forceFill(['status' => TemplateStatus::DELETED])->save();
        $template->delete();

        $this->audit->record('template.deleted', $template, meta: ['name' => $template->name, 'language' => $template->language]);
    }

    private function tokenFor(WabaAccount $waba): string
    {
        if ($waba->status !== WabaStatus::Connected) {
            throw TemplateException::accountUnavailable();
        }

        try {
            return $this->credentials->tokenFor($waba);
        } catch (WhatsappException) {
            throw TemplateException::accountUnavailable();
        }
    }
}
