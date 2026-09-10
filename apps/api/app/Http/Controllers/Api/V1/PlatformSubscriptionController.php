<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\EntitlementService;
use App\Support\ApiProblem;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use LogicException;

final class PlatformSubscriptionController extends Controller
{
    public function update(
        Request $request,
        Organization $organization,
        EntitlementService $entitlements,
    ): JsonResponse {
        $validated = $request->validate([
            'action' => ['required', Rule::in([
                'start_trial', 'extend_trial', 'activate', 'mark_past_due', 'suspend', 'cancel', 'restore',
            ])],
            'plan_code' => ['nullable', 'string', 'max:64'],
            'trial_ends_at' => ['nullable', 'date', 'after:now'],
        ]);
        /** @var array{action: string, plan_code?: string|null, trial_ends_at?: string|null} $validated */
        $actor = $request->user();

        if (! $actor instanceof User) {
            throw new ApiProblem('unauthorized', 'Authentication is required.', 401);
        }

        $subscription = DB::transaction(function () use ($validated, $organization, $actor): Subscription {
            $current = $organization->subscriptions()->with('plan')->latest('starts_at')->lockForUpdate()->first();
            $plan = $this->resolvePlan($validated['plan_code'] ?? null, $current);
            $action = $validated['action'];
            $from = $current?->status->value;

            if ($action === 'start_trial') {
                if (! isset($validated['trial_ends_at'])) {
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
                    'trial_ends_at' => $validated['trial_ends_at'],
                ]);
            } else {
                if (! $current instanceof Subscription) {
                    throw new ApiProblem('subscription_not_found', 'No subscription is available to update.', 404);
                }

                if ($action === 'extend_trial') {
                    if ($current->status !== SubscriptionStatus::Trialing || ! isset($validated['trial_ends_at'])) {
                        throw new ApiProblem('subscription_transition_invalid', 'Only a trialing subscription can be extended with a trial end timestamp.', 422);
                    }
                    $current->update(['trial_ends_at' => $validated['trial_ends_at']]);
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
                ['from' => $from, 'to' => $current->status->value, 'plan_code' => $plan->code],
            );

            return $current->refresh()->load('plan.entitlements');
        });

        $entitlements->forget($organization);

        return response()->json([
            'subscription_status' => $entitlements->statusPayload($organization),
        ]);
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
