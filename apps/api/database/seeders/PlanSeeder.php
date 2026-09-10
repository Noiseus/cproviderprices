<?php

namespace Database\Seeders;

use App\Enums\EntitlementKey;
use App\Models\Plan;
use Illuminate\Database\Seeder;

final class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $this->upsertPlan('starter', 'Starter', 'Core capabilities for a small hospitality team.', [
            EntitlementKey::MaxLocations->value => ['limit' => 1],
            EntitlementKey::MaxUsers->value => ['limit' => 3],
            EntitlementKey::MaxSupplierAccounts->value => ['limit' => 1],
            EntitlementKey::ManualImportsEnabled->value => ['enabled' => true],
            EntitlementKey::AutomaticSyncEnabled->value => ['enabled' => false],
            EntitlementKey::PriceHistoryEnabled->value => ['enabled' => false],
            EntitlementKey::BasketOptimizerEnabled->value => ['enabled' => false],
        ]);

        $this->upsertPlan('growth', 'Growth', 'Expanded capabilities and limits for growing teams.', [
            EntitlementKey::MaxLocations->value => ['limit' => 5],
            EntitlementKey::MaxUsers->value => ['limit' => 15],
            EntitlementKey::MaxSupplierAccounts->value => ['limit' => 5],
            EntitlementKey::ManualImportsEnabled->value => ['enabled' => true],
            EntitlementKey::AutomaticSyncEnabled->value => ['enabled' => true],
            EntitlementKey::PriceHistoryEnabled->value => ['enabled' => true],
            EntitlementKey::BasketOptimizerEnabled->value => ['enabled' => false],
        ]);
    }

    /** @param array<string, array<string, bool|int>> $entitlements */
    private function upsertPlan(string $code, string $name, string $description, array $entitlements): void
    {
        $plan = Plan::query()->updateOrCreate(
            ['code' => $code],
            ['name' => $name, 'description' => $description, 'is_active' => true],
        );

        foreach ($entitlements as $key => $value) {
            $plan->entitlements()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
    }
}
