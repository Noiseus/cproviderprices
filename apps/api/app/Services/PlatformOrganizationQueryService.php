<?php

namespace App\Services;

use App\Enums\InvitationStatus;
use App\Models\AuditLog;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Pagination\LengthAwarePaginator;

final class PlatformOrganizationQueryService
{
    /**
     * @param  array{search?: string|null, status?: string|null, subscription_status?: string|null, per_page: int, page: int}  $filters
     * @return LengthAwarePaginator<int, Organization>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = Organization::query()->with(['latestSubscription.plan']);

        if (($filters['search'] ?? null) !== null) {
            $term = mb_strtolower(trim((string) $filters['search']));
            $prefix = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
            $query->where(function ($query) use ($prefix): void {
                $query->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$prefix])
                    ->orWhereRaw("LOWER(slug) LIKE ? ESCAPE '!'", [$prefix]);
            });
        }

        if (($filters['status'] ?? null) !== null) {
            $query->where('status', $filters['status']);
        }

        if (($filters['subscription_status'] ?? null) !== null) {
            $status = $filters['subscription_status'];
            $query->whereHas('latestSubscription', function ($query) use ($status): void {
                if ($status === 'expired') {
                    $query->where(function ($query): void {
                        $query->where('status', 'expired')
                            ->orWhere(function ($query): void {
                                $query->where('status', 'trialing')->where('trial_ends_at', '<=', now());
                            });
                    });
                } elseif ($status === 'trialing') {
                    $query->where('status', 'trialing')->where('trial_ends_at', '>', now());
                } else {
                    $query->where('status', $status);
                }
            });
        }

        return $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'], ['*'], 'page', $filters['page']);
    }

    /** @return array<string, mixed> */
    public function overview(): array
    {
        $organizationStatuses = Organization::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'organization_counts' => [
                'total' => Organization::query()->count(),
                'active' => (int) ($organizationStatuses['active'] ?? 0),
                'suspended' => (int) ($organizationStatuses['suspended'] ?? 0),
                'closed' => (int) ($organizationStatuses['closed'] ?? 0),
            ],
            'trialing_subscriptions' => Subscription::query()->where('status', 'trialing')->where('trial_ends_at', '>', now())->count(),
            'pending_invitations' => Invitation::query()
                ->where('status', InvitationStatus::Pending)
                ->where('expires_at', '>', now())
                ->count(),
            'recent_organizations' => Organization::query()
                ->orderByDesc('created_at')->orderByDesc('id')->limit(5)->get()
                ->map(fn (Organization $organization): array => $this->organizationSummary($organization))->all(),
            'recent_audit_activity' => AuditLog::query()
                ->where(function ($query): void {
                    $query->where('action', 'like', 'platform.%')
                        ->orWhere('action', 'like', 'subscription.%')
                        ->orWhereIn('action', ['organization.created', 'organization.status_changed'])
                        ->orWhere('action', 'like', 'invitation.initial_owner%');
                })
                ->orderByDesc('occurred_at')->limit(8)->get()
                ->map(fn (AuditLog $log): array => $this->auditSummary($log))->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function detail(Organization $organization, EntitlementService $entitlements): array
    {
        $locations = $organization->restaurantLocations()->orderBy('name')->get();
        $memberships = $organization->memberships()->with('user')->orderByDesc('created_at')->get();
        $invitations = $organization->invitations()->orderByDesc('created_at')->limit(20)->get();

        return [
            'organization' => $this->organizationSummary($organization),
            'locations' => $locations->map(fn ($location): array => [
                'id' => $location->id,
                'name' => $location->name,
                'code' => $location->code,
                'status' => $location->status->value,
                'timezone' => $location->timezone,
            ])->all(),
            'members' => $memberships->map(fn (OrganizationMembership $membership): array => [
                'id' => $membership->id,
                'name' => $membership->user->name,
                'email' => $membership->user->email,
                'role' => $membership->role->value,
                'status' => $membership->status->value,
                'joined_at' => $membership->joined_at?->toIso8601String(),
            ])->all(),
            'invitations' => $invitations->map(fn ($invitation): array => [
                'id' => $invitation->id,
                'intended_email' => $invitation->intended_email,
                'intended_role' => $invitation->intended_role->value,
                'status' => $invitation->lifecycleStatus()->value,
                'expires_at' => $invitation->expires_at->toIso8601String(),
                'created_at' => $invitation->created_at->toIso8601String(),
            ])->all(),
            'subscription_status' => $entitlements->statusPayload($organization),
            'available_plans' => Plan::query()->where('is_active', true)->orderBy('name')->get(['code', 'name'])
                ->map(fn (Plan $plan): array => ['code' => $plan->code, 'name' => $plan->name])->all(),
            'audit_activity' => AuditLog::query()->where('organization_id', $organization->id)
                ->orderByDesc('occurred_at')->limit(20)->get()
                ->map(fn (AuditLog $log): array => $this->auditSummary($log))->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function organizationSummary(Organization $organization): array
    {
        $subscription = $organization->relationLoaded('latestSubscription') ? $organization->latestSubscription : null;

        return [
            'id' => $organization->id,
            'name' => $organization->name,
            'slug' => $organization->slug,
            'status' => $organization->status->value,
            'created_at' => $organization->created_at->toIso8601String(),
            'subscription' => $subscription === null ? null : [
                'status' => $subscription->lifecycleStatus()->value,
                'plan_code' => $subscription->plan->code,
                'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function auditSummary(AuditLog $log): array
    {
        return [
            'id' => $log->id,
            'action' => $log->action,
            'actor_label' => $log->actor_label,
            'subject_type' => $log->subject_type,
            'subject_id' => $log->subject_id,
            'metadata' => $log->metadata ?? [],
            'occurred_at' => $log->occurred_at->toIso8601String(),
        ];
    }
}
