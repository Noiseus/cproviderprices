<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EntitlementKey;
use App\Enums\LocationStatus;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\RestaurantLocation;
use App\Services\EntitlementService;
use App\Support\ApiProblem;
use App\Support\Audit;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class RestaurantLocationController extends Controller
{
    public function store(
        Request $request,
        TenantContext $context,
        Organization $organization,
        EntitlementService $entitlements,
    ): JsonResponse {
        Gate::authorize('create', [RestaurantLocation::class, $context->organization]);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required', 'string', 'max:64',
                Rule::unique('restaurant_locations')->where('organization_id', $context->organization->id),
            ],
            'timezone' => ['required', 'timezone:all'],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:64'],
            'postal_code' => ['nullable', 'string', 'max:32'],
            'country_code' => ['nullable', 'string', 'size:2'],
        ]);
        $location = DB::transaction(function () use ($context, $entitlements, $validated): RestaurantLocation {
            Organization::query()->whereKey($context->organization->id)->lockForUpdate()->firstOrFail();
            $entitlements->assertWithinLimit($context->organization, EntitlementKey::MaxLocations);

            return $context->organization->restaurantLocations()->create(
                ['status' => LocationStatus::Active, ...$validated]
            );
        });

        return response()->json(['restaurant_location' => $this->payload($location)], 201);
    }

    public function show(TenantContext $context, Organization $organization, string $restaurantLocation): JsonResponse
    {
        $location = $this->find($context, $restaurantLocation);
        Gate::authorize('view', $location);

        return response()->json(['restaurant_location' => $this->payload($location)]);
    }

    public function update(Request $request, TenantContext $context, Organization $organization, string $restaurantLocation): JsonResponse
    {
        $location = $this->find($context, $restaurantLocation);
        $this->authorizeHighRisk('update', $location, $context);
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', Rule::enum(LocationStatus::class)],
            'timezone' => ['sometimes', 'timezone:all'],
        ]);
        $location->update($validated);

        return response()->json(['restaurant_location' => $this->payload($location->refresh())]);
    }

    public function destroy(TenantContext $context, Organization $organization, string $restaurantLocation): JsonResponse
    {
        $location = $this->find($context, $restaurantLocation);
        $this->authorizeHighRisk('delete', $location, $context);
        $location->delete();

        return response()->json(['message' => 'Restaurant location deleted.']);
    }

    private function find(TenantContext $context, string $id): RestaurantLocation
    {
        $location = $context->organization->restaurantLocations()->whereKey($id)->first();

        if (! $location instanceof RestaurantLocation) {
            throw new ApiProblem('not_found', 'The requested resource was not found.', 404);
        }

        return $location;
    }

    private function authorizeHighRisk(string $ability, RestaurantLocation $location, TenantContext $context): void
    {
        if (! Gate::allows($ability, $location)) {
            Audit::record(
                'authorization.high_risk_denied',
                $context->user,
                $context->organization->id,
                'restaurant_location',
                $location->id,
                ['ability' => $ability],
            );
            throw new ApiProblem('forbidden', 'You are not authorized to perform this action.', 403);
        }
    }

    /** @return array<string, mixed> */
    private function payload(RestaurantLocation $location): array
    {
        return [
            'id' => $location->id,
            'organization_id' => $location->organization_id,
            'name' => $location->name,
            'code' => $location->code,
            'status' => $location->status->value,
            'timezone' => $location->timezone,
        ];
    }
}
