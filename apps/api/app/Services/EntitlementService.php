<?php

namespace App\Services;

use App\Enums\EntitlementKey;
use App\Enums\MembershipStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Organization;
use App\Models\PlanEntitlement;
use App\Models\Subscription;
use App\Support\ApiProblem;

final class EntitlementService
{
    /** @var array<string, Subscription|null> */
    private array $subscriptions = [];

    public function subscriptionFor(Organization $organization): ?Subscription
    {
        if (! array_key_exists($organization->id, $this->subscriptions)) {
            $this->subscriptions[$organization->id] = $organization->subscriptions()
                ->with('plan.entitlements')
                ->orderByDesc('starts_at')
                ->orderByDesc('created_at')
                ->first();
        }

        return $this->subscriptions[$organization->id];
    }

    public function forget(Organization $organization): void
    {
        unset($this->subscriptions[$organization->id]);
    }

    public function featureEnabled(Organization $organization, EntitlementKey $key): bool
    {
        if ($key->isLimit() || ! $this->hasSubscriptionAccess($organization)) {
            return false;
        }

        $value = $this->entitlement($organization, $key)?->value;

        return is_array($value) && ($value['enabled'] ?? false) === true;
    }

    public function limit(Organization $organization, EntitlementKey $key): ?int
    {
        if (! $key->isLimit()) {
            return null;
        }

        $value = $this->entitlement($organization, $key)?->value;
        $limit = is_array($value) ? ($value['limit'] ?? null) : null;

        return is_int($limit) && $limit >= 0 ? $limit : null;
    }

    public function currentUsage(Organization $organization, EntitlementKey $key): ?int
    {
        return match ($key) {
            EntitlementKey::MaxLocations => $organization->restaurantLocations()->count(),
            EntitlementKey::MaxUsers => $organization->memberships()
                ->where('status', MembershipStatus::Active)->count(),
            EntitlementKey::MaxSupplierAccounts => null,
            default => null,
        };
    }

    public function hasReachedLimit(Organization $organization, EntitlementKey $key): bool
    {
        $limit = $this->limit($organization, $key);
        $usage = $this->currentUsage($organization, $key);

        return $limit !== null && $usage !== null && $usage >= $limit;
    }

    public function assertFeatureEnabled(Organization $organization, EntitlementKey $key): void
    {
        $this->assertSubscriptionAccess($organization);

        if (! $this->featureEnabled($organization, $key)) {
            throw new ApiProblem('feature_disabled', 'This feature is not available for the current plan.', 403, [
                'entitlement_key' => $key->value,
            ]);
        }
    }

    public function assertWithinLimit(Organization $organization, EntitlementKey $key): void
    {
        $this->assertSubscriptionAccess($organization);
        $limit = $this->limit($organization, $key);
        $usage = $this->currentUsage($organization, $key);

        if ($limit === null) {
            throw new ApiProblem('feature_disabled', 'This capability is not available for the current plan.', 403, [
                'entitlement_key' => $key->value,
            ]);
        }

        if ($usage !== null && $usage >= $limit) {
            throw new ApiProblem('limit_reached', 'The current plan limit has been reached.', 409, [
                'entitlement_key' => $key->value,
                'limit' => $limit,
                'current_usage' => $usage,
            ]);
        }
    }

    public function hasSubscriptionAccess(Organization $organization): bool
    {
        return $this->subscriptionFor($organization)?->grantsEntitlementAccess() ?? false;
    }

    public function assertSubscriptionAccess(Organization $organization): void
    {
        $subscription = $this->subscriptionFor($organization);
        $status = $subscription?->lifecycleStatus();

        if ($subscription !== null && $status === SubscriptionStatus::Suspended) {
            throw new ApiProblem('subscription_suspended', 'This subscription is suspended.', 423, [
                'subscription_status' => $status->value,
            ]);
        }

        if ($subscription === null || ! $subscription->grantsEntitlementAccess()) {
            throw new ApiProblem('subscription_inactive', 'An active subscription or trial is required.', 403, [
                'subscription_status' => $status === null ? 'none' : $status->value,
            ]);
        }
    }

    /** @return array<string, mixed> */
    public function statusPayload(Organization $organization): array
    {
        $subscription = $this->subscriptionFor($organization);
        $capabilities = [];
        $limits = [];

        foreach (EntitlementKey::cases() as $key) {
            if ($key->isLimit()) {
                $limit = $this->limit($organization, $key);
                $usage = $this->currentUsage($organization, $key);
                $limits[$key->value] = [
                    'limit' => $limit,
                    'current_usage' => $usage,
                    'reached' => $limit !== null && $usage !== null ? $usage >= $limit : null,
                ];
            } else {
                $capabilities[$key->value] = $this->featureEnabled($organization, $key);
            }
        }

        return [
            'plan' => $subscription === null ? null : [
                'code' => $subscription->plan->code,
                'name' => $subscription->plan->name,
            ],
            'subscription' => $subscription === null ? null : [
                'status' => $subscription->lifecycleStatus()->value,
                'recorded_status' => $subscription->status->value,
                'starts_at' => $subscription->starts_at->toIso8601String(),
                'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
                'current_period_ends_at' => $subscription->current_period_ends_at?->toIso8601String(),
                'access_available' => $subscription->grantsEntitlementAccess(),
            ],
            'capabilities' => $capabilities,
            'limits' => $limits,
        ];
    }

    private function entitlement(Organization $organization, EntitlementKey $key): ?PlanEntitlement
    {
        $subscription = $this->subscriptionFor($organization);

        if ($subscription === null) {
            return null;
        }

        return $subscription->plan->entitlements->first(
            fn (PlanEntitlement $entitlement): bool => $entitlement->key === $key
        );
    }
}
