<?php

namespace App\Enums;

enum EntitlementKey: string
{
    case MaxLocations = 'max_locations';
    case MaxUsers = 'max_users';
    case MaxSupplierAccounts = 'max_supplier_accounts';
    case ManualImportsEnabled = 'manual_imports_enabled';
    case AutomaticSyncEnabled = 'automatic_sync_enabled';
    case PriceHistoryEnabled = 'price_history_enabled';
    case BasketOptimizerEnabled = 'basket_optimizer_enabled';

    public function isLimit(): bool
    {
        return match ($this) {
            self::MaxLocations, self::MaxUsers, self::MaxSupplierAccounts => true,
            default => false,
        };
    }
}
