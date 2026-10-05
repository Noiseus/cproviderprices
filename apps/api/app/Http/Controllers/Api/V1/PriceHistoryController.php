<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LocationStatus;
use App\Http\Controllers\Controller;
use App\Models\CanonicalProduct;
use App\Models\Organization;
use App\Models\RestaurantLocation;
use App\Services\PriceHistoryService;
use App\Services\SearchComparisonCache;
use App\Support\ApiProblem;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PriceHistoryController extends Controller
{
    public function show(
        Request $request,
        TenantContext $context,
        Organization $organization,
        string $canonicalProduct,
        PriceHistoryService $history,
        SearchComparisonCache $cache,
    ): JsonResponse {
        $validated = $request->validate([
            'location_id' => ['required', 'uuid'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'supplier_id' => ['sometimes', 'array', 'max:50'],
            'supplier_id.*' => ['uuid'],
            'dimension' => ['nullable', 'in:weight,volume,count'],
        ]);
        $to = isset($validated['to']) ? CarbonImmutable::parse($validated['to'])->endOfDay() : now()->toImmutable();
        $from = isset($validated['from']) ? CarbonImmutable::parse($validated['from'])->startOfDay() : $to->subDays(89)->startOfDay();
        if ($from->greaterThan($to) || $from->diffInDays($to) > 366) {
            throw new ApiProblem('invalid_history_range', 'Price history ranges must be between zero and 366 days.', 422);
        }
        $location = $context->organization->restaurantLocations()->whereKey($validated['location_id'])->first();
        if (! $location instanceof RestaurantLocation) {
            throw new ApiProblem('not_found', 'The requested resource was not found.', 404);
        }
        if ($location->status !== LocationStatus::Active) {
            throw new ApiProblem('location_inactive', 'Only an active location can be viewed.', 409);
        }
        $product = CanonicalProduct::query()->with('category')->where('is_active', true)->whereKey($canonicalProduct)->first();
        if (! $product instanceof CanonicalProduct) {
            throw new ApiProblem('not_found', 'The requested resource was not found.', 404);
        }
        $filters = [
            'from' => $from,
            'to' => $to,
            'supplier_ids' => $validated['supplier_id'] ?? [],
            'dimension' => $validated['dimension'] ?? null,
        ];
        $parameters = [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'supplier_ids' => $filters['supplier_ids'],
            'dimension' => $filters['dimension'],
        ];
        $payload = $cache->rememberHistory(
            $context->organization->id,
            $location->id,
            $product->id,
            $parameters,
            fn (): array => $history->history($context->organization->id, $location, $product, $filters),
        );

        return response()->json($payload)->header('Cache-Control', 'private, no-store');
    }
}
