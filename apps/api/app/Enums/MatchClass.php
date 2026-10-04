<?php

namespace App\Enums;

enum MatchClass: string
{
    case ExactMatch = 'exact_match';
    case EquivalentProduct = 'equivalent_product';
    case ComparableAlternative = 'comparable_alternative';
    case RelatedProduct = 'related_product';
    case NotComparable = 'not_comparable';
}
