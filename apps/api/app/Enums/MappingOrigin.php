<?php

namespace App\Enums;

enum MappingOrigin: string
{
    case Matcher = 'matcher';
    case Manual = 'manual';
}
