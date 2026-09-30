<?php

declare(strict_types=1);

namespace App\Domain\Plans\Entitlements;

use App\Domain\Plans\FeatureKey;

/** Resolved, cacheable view of what a tenant may use right now. */
final readonly class EntitlementSet
{
    /** @param array<string, Entitlement> $entitlements */
    public function __construct(
        public string $planKey,
        public string $planName,
        public string $planVersionId,
        public int $planVersion,
        public ?string $subscriptionStatus,
        public ?string $trialEndsAt,
        public array $entitlements,
    ) {}

    public function get(FeatureKey $feature): Entitlement
    {
        return $this->entitlements[$feature->value]
            ?? new Entitlement($feature->value, $feature->type(), false, 0);
    }

    public function allows(FeatureKey $feature): bool
    {
        return $this->get($feature)->enabled;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'plan_key' => $this->planKey,
            'plan_name' => $this->planName,
            'plan_version_id' => $this->planVersionId,
            'plan_version' => $this->planVersion,
            'subscription_status' => $this->subscriptionStatus,
            'trial_ends_at' => $this->trialEndsAt,
            'entitlements' => array_map(fn (Entitlement $e) => $e->toArray(), $this->entitlements),
        ];
    }

    /** @return array<string, mixed> */
    public function toResponseArray(): array
    {
        return [
            'plan' => ['key' => $this->planKey, 'name' => $this->planName, 'version' => $this->planVersion],
            'subscription' => ['status' => $this->subscriptionStatus, 'trial_ends_at' => $this->trialEndsAt],
            'features' => (object) array_map(fn (Entitlement $e) => $e->toResponseArray(), $this->entitlements),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['plan_key'],
            $data['plan_name'],
            $data['plan_version_id'],
            (int) $data['plan_version'],
            $data['subscription_status'],
            $data['trial_ends_at'],
            array_map(fn (array $e) => Entitlement::fromArray($e), $data['entitlements']),
        );
    }
}
