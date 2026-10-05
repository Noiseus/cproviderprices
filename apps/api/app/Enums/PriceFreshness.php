<?php

namespace App\Enums;

enum PriceFreshness: string
{
    case Fresh = 'fresh';
    case Aging = 'aging';
    case Stale = 'stale';
    case ConnectionFailed = 'connection_failed';
    case ManualImport = 'manual_import';
}
