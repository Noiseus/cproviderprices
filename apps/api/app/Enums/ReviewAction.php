<?php

namespace App\Enums;

enum ReviewAction: string
{
    case Accept = 'accept';
    case Reject = 'reject';
    case ChooseExisting = 'choose_existing';
    case CreateCanonical = 'create_canonical';
    case Reverse = 'reverse';
}
