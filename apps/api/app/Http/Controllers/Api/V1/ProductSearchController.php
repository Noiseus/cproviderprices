<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ComparisonClass;
use App\Enums\LocationStatus;
use App\Enums\OfferAvailability;
use App\Enums\PriceFreshness;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\RestaurantLocation;
use App\Services\ProductSearchService;
use App\Services\SearchComparisonCache;
use App\Support\ApiProblem;
use App\Support\CatalogText;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ProductSearchController extends Controller
{
    public function index(
        Request $request,
        TenantContext $context,
        Organization $organization,
        ProductSearchService $search,
        SearchComparisonCache $cache,
    ): JsonResponse {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:160'],
            'location_id' => ['required', 'uuid'],
            'category_id' => ['sometimes', 'nullable', 'uuid'],
            'supplier_id' => ['sometimes', 'array', 'max:50'],
            'supplier_id.*' => ['uuid'],
            'brand' => ['sometimes', 'array', 'max:50'],
            'brand.*' => ['string', 'min:1', 'max:255'],
            'unit' => ['sometimes', 'array', 'max:4'],
            'unit.*' => [Rule::in(['weight', 'volume', 'count', 'case'])],
            'availability' => ['sometimes', 'array', 'max:3'],
            'availability.*' => [Rule::enum(OfferAvailability::class)],
            'freshness' => ['sometimes', 'array', 'max:4'],
            'freshness.*' => [Rule::enum(PriceFreshness::class)],
            'match_class' => ['sometimes', 'array', 'max:4'],
            'match_class.*' => [Rule::enum(ComparisonClass::class)],
            'sort' => ['sometimes', Rule::in(['relevance', 'lowest_case_price', 'lowest_normalized_price', 'newest_observation'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $location = $context->organization->restaurantLocations()->whereKey($validated['location_id'])->first();
        if (! $location instanceof RestaurantLocation) {
            throw new ApiProblem('not_found', 'The requested resource was not found.', 404);
        }
        if ($location->status !== LocationStatus::Active) {
            throw new ApiProblem('location_inactive', 'Only an active location can be searched.', 409);
        }
        $filters = [
            'query' => trim($validated['q']),
            'category_id' => $validated['category_id'] ?? null,
            'supplier_ids' => $validated['supplier_id'] ?? [],
            'brands' => array_values(array_unique(array_filter(array_map(
                static fn (string $brand): string => CatalogText::normalize($brand),
                $validated['brand'] ?? [],
            )))),
            'units' => $validated['unit'] ?? [],
            'availability' => $validated['availability'] ?? [],
            'freshness' => $validated['freshness'] ?? [],
            'match_classes' => $validated['match_class'] ?? [],
            'sort' => $validated['sort'] ?? 'relevance',
            'page' => $validated['page'] ?? 1,
            'per_page' => $validated['per_page'] ?? 25,
        ];
        $payload = $cache->rememberSearch(
            $context->organization->id,
            $location->id,
            $filters,
            fn (): array => $search->search($context->organization->id, $location->id, $filters),
        );

        return response()->json($payload);
    }
}
