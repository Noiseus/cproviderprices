<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EntitlementKey;
use App\Enums\SupplierAccountStatus;
use App\Enums\SupplierRefreshCadence;
use App\Enums\SupplierSourceMethod;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\RestaurantLocation;
use App\Models\SupplierAccount;
use App\Models\SupplierDefinition;
use App\Services\EntitlementService;
use App\Support\ApiProblem;
use App\Support\Audit;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class SupplierAccountController extends Controller
{
    public function index(Request $request, TenantContext $context, Organization $organization): JsonResponse
    {
        $locationId = $request->validate(['location_id' => ['nullable', 'uuid']])['location_id'] ?? null;
        if ($locationId !== null && ! $context->organization->restaurantLocations()->whereKey($locationId)->exists()) {
            throw new ApiProblem('not_found', 'The requested resource was not found.', 404);
        }
        $query = $context->organization->supplierAccounts()->with(['supplierDefinition', 'restaurantLocation'])
            ->orderBy('display_name');
        if ($locationId !== null) {
            $query->where('restaurant_location_id', $locationId);
        }

        return response()->json([
            'supplier_definitions' => SupplierDefinition::query()->where('is_active', true)->orderBy('name')->get()
                ->map(fn (SupplierDefinition $supplier): array => ['id' => $supplier->id, 'code' => $supplier->code, 'name' => $supplier->name])->all(),
            'supplier_accounts' => $query->get()->map(fn (SupplierAccount $account): array => $this->payload($account))->all(),
        ]);
    }

    public function store(Request $request, TenantContext $context, Organization $organization, EntitlementService $entitlements): JsonResponse
    {
        Gate::authorize('create', [SupplierAccount::class, $context->organization]);
        $validated = $this->validated($request, true);
        $location = $context->organization->restaurantLocations()->whereKey($validated['restaurant_location_id'])->first();
        if (! $location instanceof RestaurantLocation) {
            throw new ApiProblem('not_found', 'The requested resource was not found.', 404);
        }

        $account = DB::transaction(function () use ($context, $entitlements, $validated): SupplierAccount {
            Organization::query()->whereKey($context->organization->id)->lockForUpdate()->firstOrFail();
            $entitlements->assertWithinLimit($context->organization, EntitlementKey::MaxSupplierAccounts);
            if ($context->organization->supplierAccounts()
                ->where('restaurant_location_id', $validated['restaurant_location_id'])
                ->where('supplier_definition_id', $validated['supplier_definition_id'])->exists()) {
                throw new ApiProblem('supplier_account_exists', 'This supplier is already configured for the location.', 409);
            }

            return $context->organization->supplierAccounts()->create($validated);
        });
        Audit::record('supplier_account.created', $context->user, $context->organization->id,
            'supplier_account', $account->id, ['supplier_definition_id' => $account->supplier_definition_id, 'restaurant_location_id' => $account->restaurant_location_id]);

        return response()->json(['supplier_account' => $this->payload($account->refresh()->load(['supplierDefinition', 'restaurantLocation']))], 201);
    }

    public function update(Request $request, TenantContext $context, Organization $organization, string $supplierAccount): JsonResponse
    {
        $account = $this->find($context, $supplierAccount);
        Gate::authorize('update', $account);
        $validated = $this->validated($request, false);
        $fromStatus = $account->status;
        $account->update($validated);
        Audit::record($fromStatus !== $account->status ? 'supplier_account.status_changed' : 'supplier_account.updated',
            $context->user, $context->organization->id, 'supplier_account', $account->id,
            $fromStatus !== $account->status ? ['from' => $fromStatus->value, 'to' => $account->status->value] : []);

        return response()->json(['supplier_account' => $this->payload($account->load(['supplierDefinition', 'restaurantLocation']))]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';
        $relationshipRule = $creating ? $required : 'prohibited';

        return $request->validate([
            'restaurant_location_id' => [$relationshipRule, 'uuid'],
            'supplier_definition_id' => [$relationshipRule, 'uuid', Rule::exists('supplier_definitions', 'id')->where('is_active', true)],
            'display_name' => [$required, 'string', 'max:255'],
            'masked_account_reference' => ['sometimes', 'nullable', 'string', 'max:64', 'not_regex:/password|secret|token|cookie/i'],
            'source_method' => [$required, Rule::enum(SupplierSourceMethod::class)],
            'expected_refresh_cadence' => [$required, Rule::enum(SupplierRefreshCadence::class)],
            'status' => ['sometimes', Rule::enum(SupplierAccountStatus::class)],
            'password' => ['prohibited'],
            'portal_password' => ['prohibited'],
            'mfa_secret' => ['prohibited'],
            'api_key' => ['prohibited'],
            'credentials' => ['prohibited'],
            'organization_id' => ['prohibited'],
        ]);
    }

    private function find(TenantContext $context, string $id): SupplierAccount
    {
        $account = $context->organization->supplierAccounts()->whereKey($id)->first();
        if (! $account instanceof SupplierAccount) {
            throw new ApiProblem('not_found', 'The requested resource was not found.', 404);
        }

        return $account;
    }

    /** @return array<string, mixed> */
    private function payload(SupplierAccount $account): array
    {
        return [
            'id' => $account->id,
            'supplier' => ['id' => $account->supplierDefinition->id, 'code' => $account->supplierDefinition->code, 'name' => $account->supplierDefinition->name],
            'location' => ['id' => $account->restaurantLocation->id, 'name' => $account->restaurantLocation->name],
            'display_name' => $account->display_name,
            'masked_account_reference' => $account->masked_account_reference,
            'source_method' => $account->source_method->value,
            'expected_refresh_cadence' => $account->expected_refresh_cadence->value,
            'status' => $account->status->value,
            'last_successful_sync_at' => $account->last_successful_sync_at?->toIso8601String(),
            'next_expected_sync_at' => $account->next_expected_sync_at?->toIso8601String(),
            'last_sync_failed_at' => $account->last_sync_failed_at?->toIso8601String(),
        ];
    }
}
