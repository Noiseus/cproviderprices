<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ComparisonClass;
use App\Enums\LocationStatus;
use App\Enums\OfferAvailability;
use App\Enums\PriceFreshness;
use App\Http\Controllers\Controller;
use App\Models\CanonicalProduct;
use App\Models\Organization;
use App\Models\RestaurantLocation;
use App\Services\OfferComparisonService;
use App\Services\SearchComparisonCache;
use App\Support\ApiProblem;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ProductComparisonController extends Controller
{
    public function show(
        Request $request,
        TenantContext $context,
        Organization $organization,
        string $canonicalProduct,
        OfferComparisonService $comparisons,
        SearchComparisonCache $cache,
    ): JsonResponse {
        $filters = $request->validate([
            'location_id' => ['required', 'uuid'],
            'supplier_id' => ['sometimes', 'array', 'max:50'],
            'supplier_id.*' => ['uuid'],
            'availability' => ['sometimes', 'array', 'max:3'],
            'availability.*' => [Rule::enum(OfferAvailability::class)],
            'freshness' => ['sometimes', 'array', 'max:4'],
            'freshness.*' => [Rule::enum(PriceFreshness::class)],
            'match_class' => ['sometimes', 'array', 'max:4'],
            'match_class.*' => [Rule::enum(ComparisonClass::class)],
            'sort' => ['sometimes', Rule::in(['supplier', 'lowest_case_price', 'lowest_normalized_price', 'newest_observation'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $location = $context->organization->restaurantLocations()->whereKey($filters['location_id'])->first();
        if (! $location instanceof RestaurantLocation) {
            throw new ApiProblem('not_found', 'The requested resource was not found.', 404);
        }
        if ($location->status !== LocationStatus::Active) {
            throw new ApiProblem('location_inactive', 'Only an active location can be compared.', 409);
        }
        $product = CanonicalProduct::query()->with('category')->where('is_active', true)->whereKey($canonicalProduct)->first();
        if (! $product instanceof CanonicalProduct) {
            throw new ApiProblem('not_found', 'The requested resource was not found.', 404);
        }
        $parameters = [
            'supplier_ids' => $filters['supplier_id'] ?? [],
            'availability' => $filters['availability'] ?? [],
            'freshness' => $filters['freshness'] ?? [],
            'match_classes' => $filters['match_class'] ?? [],
            'sort' => $filters['sort'] ?? 'supplier',
            'page' => $filters['page'] ?? 1,
            'per_page' => $filters['per_page'] ?? 50,
        ];
        $payload = $cache->rememberComparison(
            $context->organization->id,
            $location->id,
            $product->id,
            $parameters,
            fn (): array => $comparisons->compare($context->organization->id, $location, $product, $parameters),
        );

        return response()->json($payload)->header('Cache-Control', 'private, no-store');
    }
}
