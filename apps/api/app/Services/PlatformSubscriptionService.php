<?php

namespace App\Services;

use App\Enums\SubscriptionStatus;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\ApiProblem;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use LogicException;

final class PlatformSubscriptionService
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    /** @param array{action: string, plan_code?: string|null, trial_ends_at?: string|null, reason?: string|null} $input */
    public function apply(Organization $organization, User $actor, array $input): Subscription
    {
        $subscription = DB::transaction(function () use ($organization, $actor, $input): Subscription {
            $current = $organization->subscriptions()
                ->with('plan')
                ->orderByDesc('starts_at')
                ->orderByDesc('created_at')
                ->lockForUpdate()
                ->first();
            $plan = $this->resolvePlan($input['plan_code'] ?? null, $current);
            $action = $input['action'];
            $from = $current?->status->value;

            if ($action === 'start_trial') {
                if (! isset($input['trial_ends_at'])) {
                    throw new ApiProblem('validation_failed', 'A trial end timestamp is required.', 422, [
                        'fields' => ['trial_ends_at' => ['The trial end timestamp is required.']],
                    ]);
                }

                if ($current !== null && $current->ends_at === null) {
                    $current->update([
                        'status' => SubscriptionStatus::Canceled,
                        'canceled_at' => now(),
                        'ends_at' => now(),
                    ]);
                }

                $current = $organization->subscriptions()->create([
                    'plan_id' => $plan->id,
                    'status' => SubscriptionStatus::Trialing,
                    'starts_at' => now(),
                    'trial_ends_at' => $input['trial_ends_at'],
                ]);
            } else {
                if (! $current instanceof Subscription) {
                    throw new ApiProblem('subscription_not_found', 'No subscription is available to update.', 404);
                }

                if ($action === 'extend_trial') {
                    if ($current->status !== SubscriptionStatus::Trialing || ! isset($input['trial_ends_at'])) {
                        throw new ApiProblem('subscription_transition_invalid', 'Only a trialing subscription can be extended with a trial end timestamp.', 422);
                    }
                    $current->update(['trial_ends_at' => $input['trial_ends_at']]);
                } else {
                    $status = match ($action) {
                        'activate', 'restore' => SubscriptionStatus::Active,
                        'mark_past_due' => SubscriptionStatus::PastDue,
                        'suspend' => SubscriptionStatus::Suspended,
                        'cancel' => SubscriptionStatus::Canceled,
                        default => throw new LogicException('Validated subscription action was not handled.'),
                    };
                    $current->update([
                        'plan_id' => $plan->id,
                        'status' => $status,
                        'canceled_at' => $status === SubscriptionStatus::Canceled ? now() : null,
                        'ends_at' => $status === SubscriptionStatus::Canceled ? now() : null,
                    ]);
                }
            }

            Audit::record(
                'subscription.'.$action,
                $actor,
                $organization->id,
                'subscription',
                $current->id,
                array_filter([
                    'from' => $from,
                    'to' => $current->status->value,
                    'plan_code' => $plan->code,
                    'reason' => $input['reason'] ?? null,
                ], static fn (mixed $value): bool => $value !== null),
            );

            return $current->refresh()->load('plan.entitlements');
        });

        $this->entitlements->forget($organization);

        return $subscription;
    }

    private function resolvePlan(?string $planCode, ?Subscription $current): Plan
    {
        if ($planCode === null && $current !== null) {
            return $current->plan;
        }

        $plan = Plan::query()->where('code', $planCode)->where('is_active', true)->first();

        if (! $plan instanceof Plan) {
            throw new ApiProblem('plan_unavailable', 'The requested plan is unavailable.', 422);
        }

        return $plan;
    }
}
