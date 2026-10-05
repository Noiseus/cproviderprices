<?php

namespace App\Logging;

use App\Support\SensitiveData;
use Illuminate\Log\Logger;
use Monolog\LogRecord;

final class RedactSensitiveContext
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();
        if (! $monolog instanceof \Monolog\Logger) {
            return;
        }

        $monolog->pushProcessor(
            static fn (LogRecord $record): LogRecord => $record->with(context: SensitiveData::redact($record->context)),
        );
    }
}
