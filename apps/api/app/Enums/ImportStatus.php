<?php

namespace App\Enums;

enum ImportStatus: string
{
    case Uploaded = 'uploaded';
    case Validating = 'validating';
    case NeedsMapping = 'needs_mapping';
    case Queued = 'queued';
    case Processing = 'processing';
    case PartiallyCompleted = 'partially_completed';
    case Completed = 'completed';
    case Failed = 'failed';
    case Canceled = 'canceled';

    public function isTerminal(): bool
    {
        return in_array($this, [self::PartiallyCompleted, self::Completed, self::Failed, self::Canceled], true);
    }

    public function canTransitionTo(self $next): bool
    {
        if ($this->isTerminal()) {
            return false;
        }
        if (in_array($next, [self::Failed, self::Canceled], true)) {
            return true;
        }

        return match ($this) {
            self::Uploaded => $next === self::Validating,
            self::Validating => $next === self::NeedsMapping,
            self::NeedsMapping => $next === self::Queued,
            self::Queued => $next === self::Processing,
            self::Processing => in_array($next, [self::Completed, self::PartiallyCompleted], true),
            default => false,
        };
    }
}
