<?php

namespace Database\Factories;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AuditLog> */
class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'organization_id' => null,
            'actor_id' => null,
            'actor_type' => null,
            'actor_label' => null,
            'action' => 'domain.recorded',
            'subject_type' => null,
            'subject_id' => null,
            'metadata' => [],
            'occurred_at' => now(),
        ];
    }
}
