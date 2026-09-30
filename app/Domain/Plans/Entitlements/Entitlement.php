<?php

declare(strict_types=1);

namespace App\Domain\Plans\Entitlements;

use App\Domain\Plans\Enums\FeatureType;

final readonly class Entitlement
{
    /** @param array<string, mixed> $config */
    public function __construct(
        public string $key,
        public FeatureType $type,
        public bool $enabled,
        public ?int $limit,
        public array $config = [],
    ) {}

    public function isUnlimited(): bool
    {
        return $this->type->isQuantified() && $this->enabled && $this->limit === null;
    }

    /** Would holding $total units stay within the limit? */
    public function permits(int $total): bool
    {
        if (! $this->enabled) {
            return false;
        }

        return $this->type === FeatureType::Boolean || $this->limit === null || $total <= $this->limit;
    }

    /** JSON shape for API clients: config is always an object, never []. */
    /** @return array<string, mixed> */
    public function toResponseArray(): array
    {
        return ['type' => $this->type->value, 'enabled' => $this->enabled, 'limit' => $this->limit, 'config' => (object) $this->config];
    }

    /** @return array{key: string, type: string, enabled: bool, limit: ?int, config: array<string, mixed>} */
    public function toArray(): array
    {
        return ['key' => $this->key, 'type' => $this->type->value, 'enabled' => $this->enabled, 'limit' => $this->limit, 'config' => $this->config];
    }

    /** @param array{key: string, type: string, enabled: bool, limit: ?int, config?: array<string, mixed>} $data */
    public static function fromArray(array $data): self
    {
        return new self($data['key'], FeatureType::from($data['type']), $data['enabled'], $data['limit'], $data['config'] ?? []);
    }
}
