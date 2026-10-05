<?php

declare(strict_types=1);

namespace App\Domain\Plans\Usage;

use App\Domain\Messaging\Models\Media;
use App\Domain\Plans\FeatureKey;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;

/** Megabytes of media files the workspace currently stores (expired / deleted files are not counted). */
final class MediaStorageCounter implements UsageCounter
{
    public function __construct(private readonly TenantContext $context) {}

    public function feature(): FeatureKey
    {
        return FeatureKey::MediaStorageMb;
    }

    public function current(Tenant $tenant): int
    {
        return $this->context->bypass(fn (): int => (int) ceil(
            (int) Media::query()->where('tenant_id', $tenant->getKey())->whereNotNull('path')->sum('file_size') / 1048576
        ));
    }
}
