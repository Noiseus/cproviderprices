<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property SubscriptionStatus $status
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable|null $trial_ends_at
 * @property CarbonImmutable|null $current_period_ends_at
 * @property CarbonImmutable|null $canceled_at
 * @property CarbonImmutable|null $ends_at
 * @property Plan $plan
 */
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'plan_id',
        'status',
        'starts_at',
        'trial_ends_at',
        'current_period_ends_at',
        'canceled_at',
        'ends_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'starts_at' => 'immutable_datetime',
            'trial_ends_at' => 'immutable_datetime',
            'current_period_ends_at' => 'immutable_datetime',
            'canceled_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function lifecycleStatus(?CarbonInterface $at = null): SubscriptionStatus
    {
        $at ??= now();

        if ($this->status === SubscriptionStatus::Trialing
            && ($this->trial_ends_at === null || $this->trial_ends_at->lessThanOrEqualTo($at))) {
            return SubscriptionStatus::Expired;
        }

        if ($this->ends_at !== null && $this->ends_at->lessThanOrEqualTo($at)
            && ! in_array($this->status, [SubscriptionStatus::Canceled, SubscriptionStatus::Suspended], true)) {
            return SubscriptionStatus::Expired;
        }

        return $this->status;
    }

    public function grantsEntitlementAccess(?CarbonInterface $at = null): bool
    {
        $at ??= now();

        if ($this->starts_at->isAfter($at)) {
            return false;
        }

        return in_array($this->lifecycleStatus($at), [
            SubscriptionStatus::Trialing,
            SubscriptionStatus::Active,
            SubscriptionStatus::PastDue,
        ], true);
    }
}
