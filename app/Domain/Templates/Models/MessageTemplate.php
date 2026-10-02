<?php

declare(strict_types=1);

namespace App\Domain\Templates\Models;

use App\Domain\Templates\Enums\TemplateStatus;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\WhatsApp\Models\WabaAccount;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $waba_account_id
 * @property ?string $meta_template_id
 * @property string $name
 * @property string $language
 * @property ?string $category
 * @property string $status
 * @property ?string $quality_score
 * @property ?string $rejected_reason
 * @property string $parameter_format
 * @property ?array<int, array<string, mixed>> $components
 * @property ?string $created_by_membership_id
 * @property ?Carbon $last_synced_at
 * @property ?Carbon $deleted_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class MessageTemplate extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    protected $attributes = [
        'status' => TemplateStatus::PENDING,
        'parameter_format' => 'POSITIONAL',
    ];

    protected $fillable = [
        'tenant_id', 'waba_account_id', 'meta_template_id', 'name', 'language', 'category', 'status', 'quality_score',
        'rejected_reason', 'parameter_format', 'components', 'created_by_membership_id', 'last_synced_at',
    ];

    protected function casts(): array
    {
        return ['components' => 'array', 'last_synced_at' => 'datetime'];
    }

    /** @return BelongsTo<WabaAccount, $this> */
    public function wabaAccount(): BelongsTo
    {
        return $this->belongsTo(WabaAccount::class);
    }

    public function isSendable(): bool
    {
        return TemplateStatus::sendable($this->status);
    }

    /** @return ?array<string, mixed> the first component of a type (HEADER, BODY, FOOTER, BUTTONS) */
    public function component(string $type): ?array
    {
        foreach ((array) $this->components as $component) {
            if (strtoupper((string) ($component['type'] ?? '')) === $type) {
                return $component;
            }
        }

        return null;
    }

    /** TEXT | IMAGE | VIDEO | DOCUMENT | LOCATION, or null when there is no header. */
    public function headerFormat(): ?string
    {
        $header = $this->component('HEADER');

        return $header === null ? null : strtoupper((string) ($header['format'] ?? 'TEXT'));
    }

    /**
     * Placeholders in order of first appearance: {{1}} {{2}} (positional) or {{first_name}} (named).
     *
     * @return list<string>
     */
    public static function placeholders(?string $text): array
    {
        if ($text === null || $text === '') {
            return [];
        }
        preg_match_all('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', $text, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * What the sender must fill in, for the picker UI and for server-side validation.
     *
     * @return array{header: list<string>, header_format: ?string, body: list<string>, buttons: list<array{index: int, type: string, text: string, variable: bool}>}
     */
    public function variables(): array
    {
        $header = $this->component('HEADER');
        $format = $this->headerFormat();
        $buttons = [];

        foreach (array_values((array) ($this->component('BUTTONS')['buttons'] ?? [])) as $index => $button) {
            if (! is_array($button)) {
                continue;
            }
            $type = strtoupper((string) ($button['type'] ?? ''));
            $buttons[] = [
                'index' => $index,
                'type' => $type,
                'text' => (string) ($button['text'] ?? ''),
                'variable' => ($type === 'URL' && self::placeholders((string) ($button['url'] ?? '')) !== []) || in_array($type, ['COPY_CODE', 'OTP'], true),
            ];
        }

        return [
            'header' => $format === 'TEXT' ? self::placeholders((string) ($header['text'] ?? '')) : [],
            'header_format' => $format,
            'body' => self::placeholders((string) ($this->component('BODY')['text'] ?? '')),
            'buttons' => $buttons,
        ];
    }
}
