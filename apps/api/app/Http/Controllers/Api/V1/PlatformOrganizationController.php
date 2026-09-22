<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationStatus;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Services\EntitlementService;
use App\Services\PlatformOrganizationQueryService;
use App\Support\ApiProblem;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class PlatformOrganizationController extends Controller
{
    public function index(Request $request, PlatformOrganizationQueryService $queries): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(OrganizationStatus::class)],
            'subscription_status' => ['nullable', Rule::enum(SubscriptionStatus::class)],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);
        /** @var array{search?: string|null, status?: string|null, subscription_status?: string|null, page?: int, per_page?: int} $validated */
        $filters = [...$validated, 'page' => $validated['page'] ?? 1, 'per_page' => $validated['per_page'] ?? 25];
        $paginator = $queries->paginate($filters);

        return response()->json([
            'organizations' => $paginator->getCollection()
                ->map(fn (Organization $organization): array => $queries->organizationSummary($organization))->all(),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'total_pages' => $paginator->lastPage(),
            ],
            'filters' => [
                'search' => $validated['search'] ?? null,
                'status' => $validated['status'] ?? null,
                'subscription_status' => $validated['subscription_status'] ?? null,
            ],
        ]);
    }

    public function show(
        Organization $organization,
        PlatformOrganizationQueryService $queries,
        EntitlementService $entitlements,
    ): JsonResponse {
        return response()->json(['platform_organization' => $queries->detail($organization, $entitlements)]);
    }

    public function store(Request $request, PlatformOrganizationQueryService $queries): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'min:2', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'unique:organizations,slug'],
        ]);
        $actor = $this->actor($request);

        $organization = DB::transaction(function () use ($validated, $actor): Organization {
            $organization = Organization::query()->create([
                'name' => trim($validated['name']),
                'slug' => $validated['slug'],
                'status' => OrganizationStatus::Active,
            ]);

            Audit::record(
                'organization.created',
                $actor,
                $organization->id,
                'organization',
                $organization->id,
                ['name' => $organization->name, 'slug' => $organization->slug],
            );

            return $organization;
        });

        return response()->json(['organization' => $queries->organizationSummary($organization)], 201);
    }

    public function updateStatus(Request $request, Organization $organization): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::enum(OrganizationStatus::class)],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);
        $actor = $this->actor($request);
        $from = $organization->status->value;

        DB::transaction(function () use ($organization, $validated, $actor, $from): void {
            $locked = Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $locked->update(['status' => $validated['status']]);
            Audit::record(
                'organization.status_changed',
                $actor,
                $locked->id,
                'organization',
                $locked->id,
                ['from' => $from, 'to' => $locked->status->value, 'reason' => $validated['reason']],
            );
        });

        return response()->json(['organization_status' => [
            'id' => $organization->id,
            'status' => $organization->refresh()->status->value,
        ]]);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            throw new ApiProblem('unauthorized', 'Authentication is required.', 401);
        }

        return $actor;
    }
}
