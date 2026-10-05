<?php

namespace App\Services;

use App\Enums\LocationStatus;
use App\Enums\OfferAvailability;
use App\Enums\SavedListStatus;
use App\Models\CanonicalProduct;
use App\Models\ProductVariant;
use App\Models\RestaurantLocation;
use App\Models\SavedList;
use App\Models\SavedListItem;
use App\Models\SupplierOffer;
use App\Support\ApiProblem;
use App\Support\Audit;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Mutates durable purchasing intent and derives current offer summaries via
 * OfferComparisonService. It deliberately does not persist any price values.
 */
final class SavedListService
{
    /** @var array<string, string> */
    private const UNIT_DIMENSIONS = ['lb' => 'weight', 'gal' => 'volume', 'ea' => 'count'];

    public function __construct(private readonly OfferComparisonService $comparisons) {}

    /**
     * @return array{saved_lists: list<array<string, mixed>>, pagination: array<string, int>}
     */
    public function index(TenantContext $context, ?string $locationId, SavedListStatus $status, int $page, int $perPage): array
    {
        $query = $context->organization->savedLists()
            ->where('status', $status)
            ->with('restaurantLocation:id,name')
            ->withCount('items')
            ->orderByDesc('updated_at')
            ->orderByDesc('id');
        if ($locationId !== null) {
            $query->where('restaurant_location_id', $locationId);
        }
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return [
            'saved_lists' => $paginator->getCollection()->map(fn (SavedList $list): array => $this->indexPayload($list))->all(),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'total_pages' => max(1, $paginator->lastPage()),
            ],
        ];
    }

    /** @return array{saved_list: array<string, mixed>} */
    public function detail(TenantContext $context, string $listId): array
    {
        $list = $this->find($context, $listId, true);
        $items = $list->items()
            ->with(['canonicalProduct.category', 'productVariant'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $summaries = $this->comparisons->describeOffersForIntents(
            $context->organization->id,
            $list->restaurantLocation,
            $items->map(static fn (SavedListItem $item): array => [
                'canonical_product_id' => $item->canonical_product_id,
                'product_variant_id' => $item->product_variant_id,
            ])->all(),
        );

        return ['saved_list' => $this->detailPayload($list, $items->all(), $summaries)];
    }

    /** @param array{name: string, location_id: string} $data
     * @return array{saved_list: array<string, mixed>}
     */
    public function create(TenantContext $context, array $data): array
    {
        $location = $this->location($context, $data['location_id']);
        $this->assertActiveLocation($location);
        $list = SavedList::query()->create([
            'organization_id' => $context->organization->id,
            'restaurant_location_id' => $location->id,
            'name' => trim($data['name']),
            'status' => SavedListStatus::Active,
            'version' => 1,
            'created_by' => $context->user->id,
            'updated_by' => $context->user->id,
        ]);
        Audit::record('saved_list.created', $context->user, $context->organization->id, 'saved_list', $list->id, [
            'location_id' => $location->id,
        ]);

        return ['saved_list' => $this->indexPayload($list->load('restaurantLocation')->loadCount('items'))];
    }

    /** @return array{saved_list: array<string, mixed>} */
    public function rename(TenantContext $context, string $listId, string $name, int $expectedVersion): array
    {
        $list = DB::transaction(function () use ($context, $listId, $name, $expectedVersion): SavedList {
            $list = $this->locked($context, $listId);
            $this->assertListVersion($list, $expectedVersion);
            $list->update([
                'name' => trim($name),
                'version' => $list->version + 1,
                'updated_by' => $context->user->id,
            ]);

            return $list->refresh();
        });
        Audit::record('saved_list.renamed', $context->user, $context->organization->id, 'saved_list', $list->id);

        return ['saved_list' => $this->indexPayload($list->load('restaurantLocation')->loadCount('items'))];
    }

    /** @return array{saved_list: array<string, mixed>} */
    public function duplicate(TenantContext $context, string $listId, ?string $name, int $expectedVersion): array
    {
        $copy = DB::transaction(function () use ($context, $listId, $name, $expectedVersion): SavedList {
            $source = $this->locked($context, $listId);
            $this->assertListVersion($source, $expectedVersion);
            $copy = SavedList::query()->create([
                'organization_id' => $source->organization_id,
                'restaurant_location_id' => $source->restaurant_location_id,
                'name' => trim($name ?? $source->name.' copy'),
                'status' => SavedListStatus::Active,
                'version' => 1,
                'created_by' => $context->user->id,
                'updated_by' => $context->user->id,
            ]);
            foreach ($source->items()->orderBy('sort_order')->orderBy('id')->get() as $item) {
                SavedListItem::query()->create([
                    'saved_list_id' => $copy->id,
                    'organization_id' => $copy->organization_id,
                    'restaurant_location_id' => $copy->restaurant_location_id,
                    'canonical_product_id' => $item->canonical_product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'selected_offer_id' => $item->selected_offer_id,
                    'quantity_target' => $item->quantity_target,
                    'preferred_unit' => $item->preferred_unit,
                    'notes' => $item->notes,
                    'sort_order' => $item->sort_order,
                    'version' => 1,
                    'created_by' => $context->user->id,
                    'updated_by' => $context->user->id,
                ]);
            }

            return $copy;
        });
        Audit::record('saved_list.duplicated', $context->user, $context->organization->id, 'saved_list', $copy->id, [
            'source_saved_list_id' => $listId,
        ]);

        return ['saved_list' => $this->indexPayload($copy->load('restaurantLocation')->loadCount('items'))];
    }

    /** @return array{saved_list: array<string, mixed>} */
    public function changeStatus(TenantContext $context, string $listId, SavedListStatus $status, int $expectedVersion): array
    {
        $list = DB::transaction(function () use ($context, $listId, $status, $expectedVersion): SavedList {
            $list = $this->locked($context, $listId);
            $this->assertListVersion($list, $expectedVersion);
            $list->update([
                'status' => $status,
                'version' => $list->version + 1,
                'updated_by' => $context->user->id,
            ]);

            return $list->refresh();
        });
        Audit::record('saved_list.'.($status === SavedListStatus::Archived ? 'archived' : 'restored'), $context->user, $context->organization->id, 'saved_list', $list->id);

        return ['saved_list' => $this->indexPayload($list->load('restaurantLocation')->loadCount('items'))];
    }

    public function delete(TenantContext $context, string $listId, int $expectedVersion): void
    {
        DB::transaction(function () use ($context, $listId, $expectedVersion): void {
            $list = $this->locked($context, $listId);
            $this->assertListVersion($list, $expectedVersion);
            Audit::record('saved_list.deleted', $context->user, $context->organization->id, 'saved_list', $list->id, [
                'item_count' => $list->items()->count(),
            ]);
            $list->delete();
        });
    }

    /** @param array{canonical_product_id: string, product_variant_id?: string|null, selected_offer_id?: string|null, quantity_target?: string, preferred_unit?: string|null, notes?: string|null} $data
     * @return array{saved_list: array<string, mixed>, item: array<string, mixed>}
     */
    public function addItem(TenantContext $context, string $listId, array $data, int $expectedListVersion): array
    {
        try {
            [$list, $item] = DB::transaction(function () use ($context, $listId, $data, $expectedListVersion): array {
                $list = $this->locked($context, $listId);
                $this->assertWritable($list);
                $this->assertListVersion($list, $expectedListVersion);
                $product = CanonicalProduct::query()->where('is_active', true)->whereKey($data['canonical_product_id'])->first();
                if (! $product instanceof CanonicalProduct) {
                    throw new ApiProblem('not_found', 'The requested resource was not found.', 404);
                }
                $variantId = $data['product_variant_id'] ?? null;
                if ($variantId !== null && ! ProductVariant::query()->whereKey($variantId)->where('canonical_product_id', $product->id)->where('is_active', true)->exists()) {
                    throw new ApiProblem('invalid_product_variant', 'The selected variant is not part of this product.', 422);
                }
                $duplicate = $list->items()->where('canonical_product_id', $product->id)
                    ->when($variantId === null, fn (Builder $query): Builder => $query->whereNull('product_variant_id'), fn (Builder $query): Builder => $query->where('product_variant_id', $variantId))
                    ->exists();
                if ($duplicate) {
                    throw new ApiProblem('duplicate_list_item', 'This product is already on the saved list.', 409);
                }
                $preferredUnit = $data['preferred_unit'] ?? null;
                $this->assertPreferredUnit($list, $product->id, $variantId, $preferredUnit);
                $selectedOfferId = $data['selected_offer_id'] ?? null;
                if ($selectedOfferId !== null) {
                    $this->assertSelectedOffer($list, $product->id, $variantId, $selectedOfferId);
                }
                $item = SavedListItem::query()->create([
                    'saved_list_id' => $list->id,
                    'organization_id' => $list->organization_id,
                    'restaurant_location_id' => $list->restaurant_location_id,
                    'canonical_product_id' => $product->id,
                    'product_variant_id' => $variantId,
                    'selected_offer_id' => $selectedOfferId,
                    'quantity_target' => $data['quantity_target'] ?? '1',
                    'preferred_unit' => $preferredUnit,
                    'notes' => $data['notes'] ?? null,
                    'sort_order' => ((int) $list->items()->max('sort_order')) + 1,
                    'version' => 1,
                    'created_by' => $context->user->id,
                    'updated_by' => $context->user->id,
                ]);
                $list->update(['version' => $list->version + 1, 'updated_by' => $context->user->id]);

                return [$list->refresh(), $item];
            });
        } catch (QueryException $exception) {
            if (str_contains($exception->getMessage(), 'saved_list_items_canonical_')) {
                throw new ApiProblem('duplicate_list_item', 'This product is already on the saved list.', 409);
            }
            throw $exception;
        }
        Audit::record('saved_list.item_added', $context->user, $context->organization->id, 'saved_list_item', $item->id, ['saved_list_id' => $list->id]);

        return $this->singleItemResponse($context, $list->id, $item->id);
    }

    /** @param array{quantity_target?: string, preferred_unit?: string|null, notes?: string|null} $data
     * @return array{saved_list: array<string, mixed>, item: array<string, mixed>}
     */
    public function updateItem(TenantContext $context, string $listId, string $itemId, array $data, int $expectedVersion): array
    {
        [$list, $item] = DB::transaction(function () use ($context, $listId, $itemId, $data, $expectedVersion): array {
            $list = $this->locked($context, $listId);
            $this->assertWritable($list);
            $item = $this->lockedItem($list, $itemId);
            $this->assertItemVersion($item, $expectedVersion);
            $changes = [];
            if (array_key_exists('preferred_unit', $data)) {
                $this->assertPreferredUnit($list, $item->canonical_product_id, $item->product_variant_id, $data['preferred_unit']);
                $changes['preferred_unit'] = $data['preferred_unit'];
            }
            foreach (['quantity_target', 'notes'] as $field) {
                if (array_key_exists($field, $data)) {
                    $changes[$field] = $data[$field];
                }
            }
            $item->update([...$changes, 'version' => $item->version + 1, 'updated_by' => $context->user->id]);
            $list->update(['version' => $list->version + 1, 'updated_by' => $context->user->id]);

            return [$list->refresh(), $item->refresh()];
        });
        Audit::record('saved_list.item_updated', $context->user, $context->organization->id, 'saved_list_item', $item->id, [
            'saved_list_id' => $list->id,
            'fields' => array_keys($data),
        ]);

        return $this->singleItemResponse($context, $list->id, $item->id);
    }

    /** @return array{saved_list: array<string, mixed>, item: array<string, mixed>} */
    public function selectOffer(TenantContext $context, string $listId, string $itemId, ?string $selectedOfferId, int $expectedVersion): array
    {
        [$list, $item] = DB::transaction(function () use ($context, $listId, $itemId, $selectedOfferId, $expectedVersion): array {
            $list = $this->locked($context, $listId);
            $this->assertWritable($list);
            $item = $this->lockedItem($list, $itemId);
            $this->assertItemVersion($item, $expectedVersion);
            if ($selectedOfferId !== null) {
                $this->assertSelectedOffer($list, $item->canonical_product_id, $item->product_variant_id, $selectedOfferId);
            }
            $item->update([
                'selected_offer_id' => $selectedOfferId,
                'version' => $item->version + 1,
                'updated_by' => $context->user->id,
            ]);
            $list->update(['version' => $list->version + 1, 'updated_by' => $context->user->id]);

            return [$list->refresh(), $item->refresh()];
        });
        Audit::record('saved_list.selected_offer_'.($selectedOfferId === null ? 'cleared' : 'changed'), $context->user, $context->organization->id, 'saved_list_item', $item->id, [
            'saved_list_id' => $list->id,
            'selected_offer_id' => $selectedOfferId,
        ]);

        return $this->singleItemResponse($context, $list->id, $item->id);
    }

    /** @return array{saved_list: array<string, mixed>} */
    public function removeItem(TenantContext $context, string $listId, string $itemId, int $expectedVersion): array
    {
        $list = DB::transaction(function () use ($context, $listId, $itemId, $expectedVersion): SavedList {
            $list = $this->locked($context, $listId);
            $this->assertWritable($list);
            $item = $this->lockedItem($list, $itemId);
            $this->assertItemVersion($item, $expectedVersion);
            $item->delete();
            $list->update(['version' => $list->version + 1, 'updated_by' => $context->user->id]);

            return $list->refresh();
        });
        Audit::record('saved_list.item_removed', $context->user, $context->organization->id, 'saved_list_item', $itemId, ['saved_list_id' => $list->id]);

        return [
            'saved_list' => ['id' => $list->id, 'version' => $list->version],
            'removed_item_id' => $itemId,
        ];
    }

    /** @param list<string> $orderedItemIds
     * @return array{saved_list: array<string, mixed>}
     */
    public function reorder(TenantContext $context, string $listId, array $orderedItemIds, int $expectedListVersion): array
    {
        $list = DB::transaction(function () use ($context, $listId, $orderedItemIds, $expectedListVersion): SavedList {
            $list = $this->locked($context, $listId);
            $this->assertWritable($list);
            $this->assertListVersion($list, $expectedListVersion);
            $items = $list->items()->whereIn('id', $orderedItemIds)->lockForUpdate()->get()->keyBy('id');
            if ($items->count() !== $list->items()->count() || array_diff($orderedItemIds, $items->keys()->all()) !== []) {
                throw new ApiProblem('invalid_list_order', 'Reorder must include every current list item exactly once.', 422);
            }
            foreach ($orderedItemIds as $index => $itemId) {
                /** @var SavedListItem $item */
                $item = $items->get($itemId);
                if ($item->sort_order !== $index + 1) {
                    $item->update([
                        'sort_order' => $index + 1,
                        'version' => $item->version + 1,
                        'updated_by' => $context->user->id,
                    ]);
                }
            }
            $list->update(['version' => $list->version + 1, 'updated_by' => $context->user->id]);

            return $list->refresh();
        });
        Audit::record('saved_list.reordered', $context->user, $context->organization->id, 'saved_list', $list->id);

        return [
            'saved_list' => ['id' => $list->id, 'version' => $list->version],
            'items' => $list->items()->orderBy('sort_order')->orderBy('id')->get(['id', 'sort_order', 'version'])
                ->map(fn (SavedListItem $item): array => ['id' => $item->id, 'sort_order' => $item->sort_order, 'version' => $item->version])
                ->all(),
        ];
    }

    private function find(TenantContext $context, string $listId, bool $withLocation = false): SavedList
    {
        $query = $context->organization->savedLists()->whereKey($listId);
        if ($withLocation) {
            $query->with('restaurantLocation');
        }
        $list = $query->first();
        if (! $list instanceof SavedList) {
            throw new ApiProblem('not_found', 'The requested resource was not found.', 404);
        }

        return $list;
    }

    private function locked(TenantContext $context, string $listId): SavedList
    {
        $list = $context->organization->savedLists()->whereKey($listId)->lockForUpdate()->first();
        if (! $list instanceof SavedList) {
            throw new ApiProblem('not_found', 'The requested resource was not found.', 404);
        }

        return $list;
    }

    private function lockedItem(SavedList $list, string $itemId): SavedListItem
    {
        $item = $list->items()->whereKey($itemId)->lockForUpdate()->first();
        if (! $item instanceof SavedListItem) {
            throw new ApiProblem('not_found', 'The requested resource was not found.', 404);
        }

        return $item;
    }

    private function location(TenantContext $context, string $locationId): RestaurantLocation
    {
        $location = $context->organization->restaurantLocations()->whereKey($locationId)->first();
        if (! $location instanceof RestaurantLocation) {
            throw new ApiProblem('not_found', 'The requested resource was not found.', 404);
        }

        return $location;
    }

    private function assertActiveLocation(RestaurantLocation $location): void
    {
        if ($location->status !== LocationStatus::Active) {
            throw new ApiProblem('location_inactive', 'Only an active location can be used for a saved list.', 409);
        }
    }

    private function assertWritable(SavedList $list): void
    {
        if ($list->status !== SavedListStatus::Active) {
            throw new ApiProblem('list_archived', 'Archived saved lists must be restored before they can be changed.', 409);
        }
    }

    private function assertListVersion(SavedList $list, int $expectedVersion): void
    {
        if ($list->version !== $expectedVersion) {
            throw new ApiProblem('conflict', 'This saved list changed elsewhere. Reload it before trying again.', 409, [
                'resource' => 'saved_list',
                'current_version' => $list->version,
            ]);
        }
    }

    private function assertItemVersion(SavedListItem $item, int $expectedVersion): void
    {
        if ($item->version !== $expectedVersion) {
            throw new ApiProblem('conflict', 'This list item changed elsewhere. Reload it before trying again.', 409, [
                'resource' => 'saved_list_item',
                'current_version' => $item->version,
            ]);
        }
    }

    private function assertPreferredUnit(SavedList $list, string $canonicalProductId, ?string $productVariantId, ?string $unit): void
    {
        if ($unit === null) {
            return;
        }
        $dimension = self::UNIT_DIMENSIONS[$unit] ?? null;
        if ($dimension === null) {
            throw new ApiProblem('invalid_preferred_unit', 'Preferred units must be lb, gal, or ea.', 422);
        }
        $knownDimensions = SupplierOffer::query()
            ->where('organization_id', $list->organization_id)
            ->where('restaurant_location_id', $list->restaurant_location_id)
            ->where('is_active', true)
            ->whereHas('vendorItem.currentMapping', function (Builder $mappings) use ($canonicalProductId, $productVariantId): Builder {
                $mappings->where('canonical_product_id', $canonicalProductId);
                if ($productVariantId !== null) {
                    $mappings->where('product_variant_id', $productVariantId);
                }

                return $mappings;
            })
            ->whereHas('currentPriceObservation', fn (Builder $observations): Builder => $observations->whereNotNull('dimension'))
            ->with('currentPriceObservation:id,supplier_offer_id,dimension')
            ->get()
            ->map(fn (SupplierOffer $offer): ?string => $offer->currentPriceObservation?->dimension)
            ->filter()
            ->unique()
            ->values()
            ->all();
        if ($knownDimensions !== [] && ! in_array($dimension, $knownDimensions, true)) {
            throw new ApiProblem('incompatible_preferred_unit', 'That preferred unit is incompatible with known product dimensions.', 422, [
                'known_dimensions' => $knownDimensions,
            ]);
        }
    }

    private function assertSelectedOffer(SavedList $list, string $canonicalProductId, ?string $productVariantId, string $selectedOfferId): void
    {
        $offer = SupplierOffer::query()
            ->whereKey($selectedOfferId)
            ->where('organization_id', $list->organization_id)
            ->where('restaurant_location_id', $list->restaurant_location_id)
            ->where('is_active', true)
            ->whereNotNull('current_price_observation_id')
            ->whereHas('currentPriceObservation', fn (Builder $observations): Builder => $observations->where('availability', OfferAvailability::Available->value))
            ->whereHas('vendorItem.currentMapping', function (Builder $mappings) use ($canonicalProductId, $productVariantId): Builder {
                $mappings->where('canonical_product_id', $canonicalProductId);
                if ($productVariantId !== null) {
                    $mappings->where('product_variant_id', $productVariantId);
                }

                return $mappings;
            })
            ->first();
        if (! $offer instanceof SupplierOffer) {
            throw new ApiProblem('invalid_selected_offer', 'The selected offer is not available for this list item.', 422);
        }
    }

    /** @return array<string, mixed> */
    private function indexPayload(SavedList $list): array
    {
        return [
            'id' => $list->id,
            'name' => $list->name,
            'status' => $list->status->value,
            'version' => $list->version,
            'location' => ['id' => $list->restaurantLocation->id, 'name' => $list->restaurantLocation->name],
            'item_count' => (int) ($list->items_count ?? 0),
            'updated_at' => $list->updated_at->toIso8601String(),
        ];
    }

    /**
     * @param  list<SavedListItem>  $items
     * @param  array<string, array{offers: list<array<string, mixed>>, warnings: list<string>}>  $summaries
     * @return array<string, mixed>
     */
    private function detailPayload(SavedList $list, array $items, array $summaries): array
    {
        return [
            'id' => $list->id,
            'name' => $list->name,
            'status' => $list->status->value,
            'version' => $list->version,
            'location' => ['id' => $list->restaurantLocation->id, 'name' => $list->restaurantLocation->name],
            'created_at' => $list->created_at->toIso8601String(),
            'updated_at' => $list->updated_at->toIso8601String(),
            'items' => array_map(fn (SavedListItem $item): array => $this->itemPayload($item, $summaries[$this->summaryKey($item)] ?? ['offers' => [], 'warnings' => []]), $items),
        ];
    }

    /** @param array{offers: list<array<string, mixed>>, warnings: list<string>} $summary
     * @return array<string, mixed>
     */
    private function itemPayload(SavedListItem $item, array $summary): array
    {
        $offers = $summary['offers'];
        $selected = $item->selected_offer_id === null ? null : collect($offers)->firstWhere('id', $item->selected_offer_id);
        $selectedUnavailable = $item->selected_offer_id !== null && ($selected === null || $selected['availability'] !== OfferAvailability::Available->value);
        $bestNormalized = collect($offers)->first(fn (array $offer): bool => in_array('best_normalized_price', $offer['winner_labels'], true));
        $bestAvailable = collect($offers)->first(fn (array $offer): bool => in_array('best_available_option', $offer['winner_labels'], true));
        $warnings = $summary['warnings'];
        if ($selectedUnavailable) {
            $warnings[] = 'selected_offer_unavailable';
        }

        return [
            'id' => $item->id,
            'version' => $item->version,
            'sort_order' => $item->sort_order,
            'canonical_product' => [
                'id' => $item->canonicalProduct->id,
                'english_name' => $item->canonicalProduct->english_name,
                'attributes' => $item->canonicalProduct->attributes,
                'category' => ['id' => $item->canonicalProduct->category->id, 'english_name' => $item->canonicalProduct->category->english_name],
            ],
            'product_variant' => $item->productVariant === null ? null : [
                'id' => $item->productVariant->id,
                'code' => $item->productVariant->code,
                'attributes' => $item->productVariant->attributes,
            ],
            'quantity_target' => $item->quantity_target,
            'preferred_unit' => $item->preferred_unit,
            'notes' => $item->notes,
            'selected_offer_id' => $item->selected_offer_id,
            'selected_offer' => $selected,
            'selected_offer_unavailable' => $selectedUnavailable,
            'best_normalized_option' => $bestNormalized,
            'best_available_option' => $bestAvailable,
            'alternative_offer_count' => count($offers),
            'offer_choices' => $offers,
            'warnings' => array_values(array_unique($warnings)),
            'updated_at' => $item->updated_at->toIso8601String(),
        ];
    }

    /** @return array{saved_list: array{id: string, version: int}, item: array<string, mixed>} */
    private function singleItemResponse(TenantContext $context, string $listId, string $itemId): array
    {
        $list = $this->find($context, $listId, true);
        $item = $list->items()->whereKey($itemId)->with(['canonicalProduct.category', 'productVariant'])->first();
        if (! $item instanceof SavedListItem) {
            throw new \LogicException('Updated saved-list item was not present after its mutation.');
        }
        $summaries = $this->comparisons->describeOffersForIntents(
            $context->organization->id,
            $list->restaurantLocation,
            [[
                'canonical_product_id' => $item->canonical_product_id,
                'product_variant_id' => $item->product_variant_id,
            ]],
        );

        return [
            'saved_list' => ['id' => $list->id, 'version' => $list->version],
            'item' => $this->itemPayload($item, $summaries[$this->summaryKey($item)] ?? ['offers' => [], 'warnings' => []]),
        ];
    }

    private function summaryKey(SavedListItem $item): string
    {
        return $item->canonical_product_id.'|'.($item->product_variant_id ?? 'canonical');
    }
}
