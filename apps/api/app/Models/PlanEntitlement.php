<?php

namespace App\Models;

use App\Enums\EntitlementKey;
use Database\Factories\PlanEntitlementFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property EntitlementKey $key
 * @property array{enabled?: bool, limit?: int} $value
 */
class PlanEntitlement extends Model
{
    /** @use HasFactory<PlanEntitlementFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $fillable = ['plan_id', 'key', 'value'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'key' => EntitlementKey::class,
            'value' => 'array',
        ];
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
