<?php

declare(strict_types=1);

namespace App\Application\Crm;

use App\Domain\Crm\Models\ContactTag;
use App\Domain\Crm\Services\SegmentQuery;
use App\Domain\Plans\Entitlements\EntitlementService;
use App\Domain\Plans\FeatureKey;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

/** The workspace's tag list. Tags live on contacts as text[]; this catalog names and counts them (plan limit). */
final class TagCatalog
{
    public function __construct(private readonly EntitlementService $entitlements, private readonly TenantContext $context) {}

    /**
     * Validates tag names and creates the ones that are new (within the plan's tag limit).
     *
     * @param  array<int, mixed>  $names
     * @return list<string> cleaned, de-duplicated names
     */
    public function ensure(array $names, string $field = 'tags'): array
    {
        $clean = [];
        foreach ($names as $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            if (preg_match(SegmentQuery::TAG_PATTERN, $name) !== 1) {
                throw ValidationException::withMessages([$field => "\"{$name}\" is not a valid tag. Use up to 40 letters, numbers, spaces, - _ . & or +."]);
            }
            $clean[mb_strtolower($name)] = $name;
        }
        if ($clean === []) {
            return [];
        }

        $existing = ContactTag::query()->get(['name'])->mapWithKeys(fn (ContactTag $t) => [mb_strtolower($t->name) => $t->name])->all();
        $new = array_diff_key($clean, $existing);

        if ($new !== []) {
            $this->entitlements->withinLimit($this->context->tenant(), FeatureKey::Tags, count($new), function () use ($new): void {
                foreach ($new as $name) {
                    ContactTag::query()->create(['name' => $name]);
                }
            });
        }

        // Keep the spelling of a tag that already exists ("vip" → "VIP").
        return array_map(fn (string $key, string $name) => $existing[$key] ?? $name, array_keys($clean), $clean);
    }
}
