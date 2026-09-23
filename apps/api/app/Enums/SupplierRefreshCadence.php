<?php

namespace App\Enums;

enum SupplierRefreshCadence: string
{
    case OnDemand = 'on_demand';
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
}
