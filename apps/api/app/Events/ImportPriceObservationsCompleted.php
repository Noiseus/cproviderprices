<?php

namespace App\Events;

/**
 * Internal post-commit signal. IDs are re-scoped by the listener; this event
 * carries no source payload, customer price, credential, or tenant authority.
 */
final class ImportPriceObservationsCompleted
{
    public function __construct(
        public readonly string $organizationId,
        public readonly string $locationId,
        public readonly string $importRunId,
    ) {}
}
