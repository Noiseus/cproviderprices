<?php

namespace App\Enums;

/** Product-match confidence, deliberately distinct from parser confidence. */
enum MatchConfidence: string
{
    case A = 'A';
    case B = 'B';
    case C = 'C';
    case D = 'D';
    case E = 'E';
}
