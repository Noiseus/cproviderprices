<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SavedListStatus;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\SavedList;
use App\Services\SavedListService;
use App\Support\ApiProblem;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class SavedListController extends Controller
{
    public function index(Request $request, TenantContext $context, Organization $organization, SavedListService $lists): JsonResponse
    {
        $this->rejectForgedTenantFields($request);
        $validated = $request->validate([
            'location_id' => ['nullable', 'uuid'],
            'status' => ['sometimes', Rule::enum(SavedListStatus::class)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        if (isset($validated['location_id']) && ! $context->organization->restaurantLocations()->whereKey($validated['location_id'])->exists()) {
            throw new ApiProblem('not_found', 'The requested resource was not found.', 404);
        }

        return response()->json($lists->index(
            $context,
            $validated['location_id'] ?? null,
            isset($validated['status']) ? SavedListStatus::from($validated['status']) : SavedListStatus::Active,
            $validated['page'] ?? 1,
            $validated['per_page'] ?? 25,
        ));
    }

    public function store(Request $request, TenantContext $context, Organization $organization, SavedListService $lists): JsonResponse
    {
        $this->rejectForgedTenantFields($request);
        Gate::authorize('create', [SavedList::class, $context->organization]);
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:120'],
            'location_id' => ['required', 'uuid'],
        ]);

        return response()->json($lists->create($context, $validated), 201);
    }

    public function show(TenantContext $context, Organization $organization, string $savedList, SavedListService $lists): JsonResponse
    {
        $this->authorizeList($context, $savedList, 'view');

        return response()->json($lists->detail($context, $savedList));
    }

    public function update(Request $request, TenantContext $context, Organization $organization, string $savedList, SavedListService $lists): JsonResponse
    {
        $this->rejectForgedTenantFields($request);
        $this->authorizeList($context, $savedList, 'update');
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:120'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);

        return response()->json($lists->rename($context, $savedList, $validated['name'], $validated['expected_version']));
    }

    public function duplicate(Request $request, TenantContext $context, Organization $organization, string $savedList, SavedListService $lists): JsonResponse
    {
        $this->rejectForgedTenantFields($request);
        $this->authorizeList($context, $savedList, 'update');
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'min:1', 'max:120'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);

        return response()->json($lists->duplicate($context, $savedList, $validated['name'] ?? null, $validated['expected_version']), 201);
    }

    public function archive(Request $request, TenantContext $context, Organization $organization, string $savedList, SavedListService $lists): JsonResponse
    {
        $this->authorizeList($context, $savedList, 'update');
        $version = $this->expectedVersion($request);

        return response()->json($lists->changeStatus($context, $savedList, SavedListStatus::Archived, $version));
    }

    public function restore(Request $request, TenantContext $context, Organization $organization, string $savedList, SavedListService $lists): JsonResponse
    {
        $this->authorizeList($context, $savedList, 'update');
        $version = $this->expectedVersion($request);

        return response()->json($lists->changeStatus($context, $savedList, SavedListStatus::Active, $version));
    }

    public function destroy(Request $request, TenantContext $context, Organization $organization, string $savedList, SavedListService $lists): JsonResponse
    {
        $this->authorizeList($context, $savedList, 'delete');
        $lists->delete($context, $savedList, $this->expectedVersion($request));

        return response()->json(['message' => 'Saved list deleted.']);
    }

    public function addItem(Request $request, TenantContext $context, Organization $organization, string $savedList, SavedListService $lists): JsonResponse
    {
        $this->rejectForgedTenantFields($request);
        $this->authorizeList($context, $savedList, 'update');
        $validated = $request->validate([
            'canonical_product_id' => ['required', 'uuid'],
            'product_variant_id' => ['nullable', 'uuid'],
            'selected_offer_id' => ['nullable', 'uuid'],
            'quantity_target' => ['sometimes', 'regex:/^\d{1,12}(?:\.\d{1,6})?$/', 'gt:0'],
            'preferred_unit' => ['nullable', Rule::in(['lb', 'gal', 'ea'])],
            'notes' => ['nullable', 'string', 'max:2000'],
            'expected_list_version' => ['required', 'integer', 'min:1'],
        ]);

        return response()->json($lists->addItem($context, $savedList, $validated, $validated['expected_list_version']), 201);
    }

    public function updateItem(Request $request, TenantContext $context, Organization $organization, string $savedList, string $savedListItem, SavedListService $lists): JsonResponse
    {
        $this->rejectForgedTenantFields($request);
        $this->authorizeList($context, $savedList, 'update');
        $validated = $request->validate([
            'quantity_target' => ['sometimes', 'regex:/^\d{1,12}(?:\.\d{1,6})?$/', 'gt:0'],
            'preferred_unit' => ['sometimes', 'nullable', Rule::in(['lb', 'gal', 'ea'])],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);
        $changes = array_diff_key($validated, ['expected_version' => true]);
        if ($changes === []) {
            throw new ApiProblem('invalid_item_update', 'Provide at least one item field to update.', 422);
        }

        return response()->json($lists->updateItem($context, $savedList, $savedListItem, $changes, $validated['expected_version']));
    }

    public function selectOffer(Request $request, TenantContext $context, Organization $organization, string $savedList, string $savedListItem, SavedListService $lists): JsonResponse
    {
        $this->rejectForgedTenantFields($request);
        $this->authorizeList($context, $savedList, 'update');
        $validated = $request->validate([
            'selected_offer_id' => ['nullable', 'uuid'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);

        return response()->json($lists->selectOffer($context, $savedList, $savedListItem, $validated['selected_offer_id'] ?? null, $validated['expected_version']));
    }

    public function removeItem(Request $request, TenantContext $context, Organization $organization, string $savedList, string $savedListItem, SavedListService $lists): JsonResponse
    {
        $this->authorizeList($context, $savedList, 'update');

        return response()->json($lists->removeItem($context, $savedList, $savedListItem, $this->expectedVersion($request)));
    }

    public function reorder(Request $request, TenantContext $context, Organization $organization, string $savedList, SavedListService $lists): JsonResponse
    {
        $this->rejectForgedTenantFields($request);
        $this->authorizeList($context, $savedList, 'update');
        $validated = $request->validate([
            'item_ids' => ['required', 'array'],
            'item_ids.*' => ['required', 'uuid', 'distinct'],
            'expected_list_version' => ['required', 'integer', 'min:1'],
        ]);

        return response()->json($lists->reorder($context, $savedList, $validated['item_ids'], $validated['expected_list_version']));
    }

    private function expectedVersion(Request $request): int
    {
        $this->rejectForgedTenantFields($request);
        $validated = $request->validate(['expected_version' => ['required', 'integer', 'min:1']]);

        return $validated['expected_version'];
    }

    private function authorizeList(TenantContext $context, string $listId, string $ability): SavedList
    {
        if (! Str::isUuid($listId)) {
            throw new ApiProblem('not_found', 'The requested resource was not found.', 404);
        }
        $list = $context->organization->savedLists()->whereKey($listId)->first();
        if (! $list instanceof SavedList) {
            throw new ApiProblem('not_found', 'The requested resource was not found.', 404);
        }
        Gate::authorize($ability, $list);

        return $list;
    }

    private function rejectForgedTenantFields(Request $request): void
    {
        foreach (['organization_id', 'restaurant_location_id'] as $field) {
            if ($request->has($field)) {
                throw new ApiProblem('invalid_tenant_context', 'Organization and location context are controlled by the route and list.', 422);
            }
        }
    }
}
