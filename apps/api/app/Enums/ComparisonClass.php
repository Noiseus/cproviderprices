<?php

namespace App\Enums;

enum ComparisonClass: string
{
    case Exact = 'exact';
    case Equivalent = 'equivalent';
    case Alternative = 'alternative';
    case NotSafelyComparable = 'not_safely_comparable';
}
