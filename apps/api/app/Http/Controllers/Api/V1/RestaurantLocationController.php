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
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class RestaurantLocationController extends Controller
{
    public function index(TenantContext $context, Organization $organization): JsonResponse
    {
        return response()->json(['restaurant_locations' => $context->organization->restaurantLocations()
            ->orderBy('name')->get()->map(fn (RestaurantLocation $location): array => $this->payload($location))->all()]);
    }

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
                'sometimes', 'string', 'max:64',
                Rule::unique('restaurant_locations')->where('organization_id', $context->organization->id),
            ],
            'timezone' => ['required', 'timezone:all'],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:64'],
            'postal_code' => ['nullable', 'string', 'max:32'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'external_reference' => ['nullable', 'string', 'max:255'],
        ]);
        $validated['code'] ??= $this->nextCode($context, $validated['name']);
        $location = DB::transaction(function () use ($context, $entitlements, $validated): RestaurantLocation {
            Organization::query()->whereKey($context->organization->id)->lockForUpdate()->firstOrFail();
            $entitlements->assertWithinLimit($context->organization, EntitlementKey::MaxLocations);

            return $context->organization->restaurantLocations()->create(
                ['status' => LocationStatus::Active, ...$validated]
            );
        });
        Audit::record('location.created', $context->user, $context->organization->id,
            'restaurant_location', $location->id);

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
            'address_line_1' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address_line_2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'region' => ['sometimes', 'nullable', 'string', 'max:100'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:32'],
            'country_code' => ['sometimes', 'string', 'size:2'],
            'external_reference' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        $fromStatus = $location->status;
        $location->update($validated);
        $action = $fromStatus !== $location->status
            ? ($location->status === LocationStatus::Active ? 'location.activated' : 'location.deactivated')
            : 'location.updated';
        Audit::record($action, $context->user, $context->organization->id, 'restaurant_location', $location->id);

        return response()->json(['restaurant_location' => $this->payload($location->refresh())]);
    }

    public function destroy(TenantContext $context, Organization $organization, string $restaurantLocation): JsonResponse
    {
        $location = $this->find($context, $restaurantLocation);
        $this->authorizeHighRisk('delete', $location, $context);
        $location->update(['status' => LocationStatus::Inactive]);
        Audit::record('location.deactivated', $context->user, $context->organization->id,
            'restaurant_location', $location->id);

        return response()->json(['message' => 'Restaurant location deactivated.']);
    }

    public function select(Request $request, TenantContext $context, Organization $organization, string $restaurantLocation): JsonResponse
    {
        $location = $this->find($context, $restaurantLocation);
        Gate::authorize('view', $location);
        if ($location->status !== LocationStatus::Active) {
            throw new ApiProblem('location_inactive', 'Only an active location can be selected.', 409);
        }
        $request->session()->put('selected_location.'.$context->organization->id, $location->id);

        return response()->json(['selected_location_id' => $location->id]);
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
            'address_line_1' => $location->address_line_1,
            'address_line_2' => $location->address_line_2,
            'city' => $location->city,
            'region' => $location->region,
            'postal_code' => $location->postal_code,
            'country_code' => $location->country_code,
            'external_reference' => $location->external_reference,
        ];
    }

    private function nextCode(TenantContext $context, string $name): string
    {
        $base = Str::upper(Str::slug($name, '_')) ?: 'LOCATION';
        $base = mb_substr($base, 0, 55);
        $code = $base;
        $counter = 1;
        while ($context->organization->restaurantLocations()->where('code', $code)->exists()) {
            $code = $base.'_'.++$counter;
        }

        return $code;
    }
}
