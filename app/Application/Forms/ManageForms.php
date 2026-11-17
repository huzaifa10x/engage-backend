<?php

declare(strict_types=1);

namespace App\Application\Forms;

use App\Application\WhatsApp\WhatsappCredentials;
use App\Domain\Audit\AuditLogger;
use App\Domain\Forms\Form;
use App\Domain\Forms\FormDefinition;
use App\Domain\Forms\FormVersion;
use App\Domain\Tenancy\TenantContext;
use App\Domain\WhatsApp\Models\WabaAccount;
use App\Infrastructure\Meta\GraphClient;
use App\Infrastructure\Meta\MetaApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * WhatsApp forms: made here, created, updated and published on Meta through the Flows API.
 *
 * A draft version is changed in place (its Meta flow gets the new Flow JSON). A published version is
 * never changed: editing it starts a new version, with its own Meta flow, and conversations that
 * already got the old one keep it until they finish.
 */
final class ManageForms
{
    public const STATUS_TEXT = [
        'NOT_CREATED' => 'Draft (not on WhatsApp yet)',
        'DRAFT' => 'Draft on WhatsApp (not live)',
        'PUBLISHED' => 'Published',
        'DEPRECATED' => 'Retired',
        'BLOCKED' => 'Blocked by WhatsApp',
        'THROTTLED' => 'Limited by WhatsApp',
    ];

    private const CATEGORY = ['booking' => 'APPOINTMENT_BOOKING', 'lead' => 'LEAD_GENERATION', 'custom' => 'OTHER'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly GraphClient $graph,
        private readonly WhatsappCredentials $credentials,
        private readonly AuditLogger $audit,
    ) {}

    public function create(string $wabaAccountId, string $name, string $kind, mixed $definition = null): Form
    {
        $kind = in_array($kind, ['booking', 'lead'], true) ? $kind : 'custom';
        WabaAccount::query()->findOrFail($wabaAccountId);
        $definition = FormDefinition::normalize($definition ?? FormDefinition::template($kind));
        $name = $this->name($name);

        $form = DB::transaction(function () use ($wabaAccountId, $name, $kind, $definition) {
            $form = Form::query()->create(['waba_account_id' => $wabaAccountId, 'name' => $name, 'kind' => $kind, 'created_by_membership_id' => $this->context->membership()?->id]);
            $version = FormVersion::query()->create(['form_id' => $form->id, 'version' => 1, 'definition' => $definition]);
            $form->forceFill(['current_version_id' => $version->id])->save();

            return $form;
        });
        $this->audit->record('form.created', $form, after: ['name' => $name, 'kind' => $kind]);

        return $form;
    }

    /** Saves the questions: in place on a draft, as a new version when the current one is published. */
    public function update(Form $form, ?string $name, mixed $definition): Form
    {
        $definition = FormDefinition::normalize($definition);
        $current = $this->current($form);
        DB::transaction(function () use ($form, $name, $definition, $current) {
            if ($name !== null) {
                $form->forceFill(['name' => $this->name($name)])->save();
            }
            if ($current->locked()) {
                $next = FormVersion::query()->create(['form_id' => $form->id, 'version' => $current->version + 1, 'definition' => $definition]);
                $form->forceFill(['current_version_id' => $next->id])->save();
            } else {
                $current->forceFill(['definition' => $definition, 'meta_errors' => null])->save();
            }
        });
        $form->refresh();
        $version = $this->current($form);
        // A draft already on WhatsApp gets the new questions straight away.
        if ($version->meta_flow_id !== null && $version->meta_status === 'DRAFT') {
            $this->upload($form, $version);
        }
        $this->audit->record('form.updated', $form, after: ['version' => $version->version]);

        return $form;
    }

    /** Sends the current version to WhatsApp and publishes it. Meta's errors come back in plain words. */
    public function publish(Form $form): Form
    {
        $version = $this->current($form);
        if ($version->locked()) {
            throw ValidationException::withMessages(['form' => 'This version is already '.mb_strtolower(self::STATUS_TEXT[$version->meta_status]).'. Change the questions to make a new version.']);
        }
        $errors = $this->upload($form, $version);
        if ($errors !== []) {
            throw ValidationException::withMessages(['form' => 'WhatsApp did not accept the form: '.implode(' ', $errors)]);
        }
        try {
            $this->graph->publishFlow((string) $version->meta_flow_id, $this->token($form));
        } catch (MetaApiException $e) {
            $version->forceFill(['meta_errors' => [['message' => $e->getMessage()]]])->save();
            throw ValidationException::withMessages(['form' => 'WhatsApp did not publish the form: '.$e->getMessage()]);
        }
        $version->forceFill(['meta_status' => 'PUBLISHED', 'published_at' => now(), 'meta_errors' => null, 'checked_at' => now()])->save();
        $this->audit->record('form.published', $form, after: ['version' => $version->version, 'meta_flow_id' => $version->meta_flow_id]);

        return $form->refresh();
    }

    /** Reads the current status from WhatsApp (Meta can block or limit a form). */
    public function refresh(Form $form): Form
    {
        foreach (FormVersion::query()->where('form_id', $form->id)->whereNotNull('meta_flow_id')->orderByDesc('version')->limit(5)->get() as $version) {
            try {
                $meta = $this->graph->getFlow((string) $version->meta_flow_id, $this->token($form));
                $version->forceFill(['meta_status' => in_array($meta['status'], array_keys(self::STATUS_TEXT), true) ? $meta['status'] : $version->meta_status,
                    'meta_errors' => $meta['validation_errors'] ?: null, 'checked_at' => now()])->save();
            } catch (MetaApiException) {
                // Not reachable now: keep what we knew.
            }
        }

        return $form->refresh();
    }

    /** Leaves the list. Its published versions are retired on WhatsApp, so they can no longer be sent. */
    public function archive(Form $form): void
    {
        foreach (FormVersion::query()->where('form_id', $form->id)->where('meta_status', 'PUBLISHED')->get() as $version) {
            try {
                $this->graph->deprecateFlow((string) $version->meta_flow_id, $this->token($form));
                $version->forceFill(['meta_status' => 'DEPRECATED'])->save();
            } catch (MetaApiException) {
                // Retire it in WhatsApp Manager if Meta refuses now.
            }
        }
        $form->forceFill(['archived_at' => now()])->save();
        $this->audit->record('form.archived', $form, meta: ['name' => $form->name]);
    }

    /** @return list<string> WhatsApp's validation errors, in plain words (empty when the JSON is valid) */
    private function upload(Form $form, FormVersion $version): array
    {
        $json = (string) json_encode(FormDefinition::flowJson($version->definition), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        try {
            if ($version->meta_flow_id === null) {
                $created = $this->graph->createFlow($this->wabaId($form), mb_substr($form->name.' v'.$version->version, 0, 200),
                    [self::CATEGORY[$form->kind] ?? 'OTHER'], $json, $this->token($form));
                $errors = $created['validation_errors'];
                $version->forceFill(['meta_flow_id' => $created['id'], 'meta_status' => 'DRAFT'])->save();
            } else {
                $errors = $this->graph->updateFlowJson((string) $version->meta_flow_id, $json, $this->token($form));
            }
        } catch (MetaApiException $e) {
            $version->forceFill(['meta_errors' => [['message' => $e->getMessage()]]])->save();
            throw ValidationException::withMessages(['form' => 'WhatsApp could not be updated: '.$e->getMessage()]);
        }
        $version->forceFill(['meta_errors' => $errors ?: null, 'checked_at' => now()])->save();

        return array_map(fn (array $e) => (string) ($e['message'] ?? $e['error'] ?? 'Unknown problem').(isset($e['line_start']) ? " (line {$e['line_start']})" : ''), $errors);
    }

    private function current(Form $form): FormVersion
    {
        /** @var FormVersion */
        return FormVersion::query()->findOrFail($form->current_version_id);
    }

    private function name(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 80) {
            throw ValidationException::withMessages(['name' => 'Give the form a name of up to 80 characters.']);
        }

        return $name;
    }

    private function token(Form $form): string
    {
        return $this->credentials->tokenFor(WabaAccount::query()->findOrFail($form->waba_account_id));
    }

    private function wabaId(Form $form): string
    {
        return (string) WabaAccount::query()->findOrFail($form->waba_account_id)->waba_id;
    }
}
