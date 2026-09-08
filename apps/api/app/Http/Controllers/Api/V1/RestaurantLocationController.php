<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LocationStatus;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\RestaurantLocation;
use App\Support\ApiProblem;
use App\Support\Audit;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class RestaurantLocationController extends Controller
{
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
