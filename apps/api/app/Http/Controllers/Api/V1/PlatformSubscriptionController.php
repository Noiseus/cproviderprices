<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Services\EntitlementService;
use App\Services\PlatformSubscriptionService;
use App\Support\ApiProblem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class PlatformSubscriptionController extends Controller
{
    public function update(
        Request $request,
        Organization $organization,
        EntitlementService $entitlements,
        PlatformSubscriptionService $subscriptions,
    ): JsonResponse {
        $validated = $request->validate([
            'action' => ['required', Rule::in([
                'start_trial', 'extend_trial', 'activate', 'mark_past_due', 'suspend', 'cancel', 'restore',
            ])],
            'plan_code' => ['nullable', 'string', 'max:64'],
            'trial_ends_at' => ['nullable', 'date', 'after:now'],
            'reason' => ['nullable', 'required_if:action,suspend,cancel', 'string', 'min:3', 'max:1000'],
        ]);
        /** @var array{action: string, plan_code?: string|null, trial_ends_at?: string|null, reason?: string|null} $validated */
        $actor = $request->user();

        if (! $actor instanceof User) {
            throw new ApiProblem('unauthorized', 'Authentication is required.', 401);
        }

        $subscriptions->apply($organization, $actor, $validated);

        return response()->json([
            'subscription_status' => $entitlements->statusPayload($organization),
        ]);
    }
}
