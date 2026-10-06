<?php

namespace App\Support;

use App\Http\Middleware\AssignRequestId;
use App\Models\AuditLog;
use App\Models\User;

final class Audit
{
    /** @param array<string, mixed> $metadata */
    public static function record(
        string $action,
        User $actor,
        ?string $organizationId = null,
        ?string $subjectType = null,
        ?string $subjectId = null,
        array $metadata = [],
    ): AuditLog {
        if (app()->bound('request') && request()->is('api/*')) {
            $metadata = ['request_id' => AssignRequestId::for(request()), ...$metadata];
        }
        $metadata = SensitiveData::redact($metadata);

        return AuditLog::query()->create([
            'organization_id' => $organizationId,
            'actor_id' => $actor->id,
            'actor_type' => 'user',
            'actor_label' => $actor->email,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'metadata' => $metadata,
        ]);
    }
}
