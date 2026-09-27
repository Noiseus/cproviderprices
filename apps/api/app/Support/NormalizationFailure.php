<?php

namespace App\Support;

use RuntimeException;

/** Safe for failed_jobs and worker logs: never retain HTTP/SQL exception chains. */
final class NormalizationFailure extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly bool $transient = false)
    {
        parent::__construct('Import normalization: '.$reason.'.');
    }
}
