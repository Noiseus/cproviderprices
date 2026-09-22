<?php

namespace App\Enums;

enum SupplierSourceMethod: string
{
    case ManualImport = 'manual_import';
    case FutureAutomaticSync = 'future_automatic_sync';
}
