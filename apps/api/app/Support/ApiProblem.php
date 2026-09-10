<?php

namespace App\Support;

use RuntimeException;

final class ApiProblem extends RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(
        public readonly string $problemCode,
        string $message,
        public readonly int $status,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
