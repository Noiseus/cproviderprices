<?php

namespace App\Enums;

/** Source-reported availability for the latest price observation. */
enum OfferAvailability: string
{
    case Available = 'available';
    case Unavailable = 'unavailable';
    case Unknown = 'unknown';
}
