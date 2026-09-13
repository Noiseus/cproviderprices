<?php

namespace App\Models;

use App\Enums\MembershipStatus;
use App\Enums\Role;
use Carbon\CarbonImmutable;
use Database\Factories\OrganizationMembershipFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property Role $role
 * @property MembershipStatus $status
 * @property CarbonImmutable|null $joined_at
 * @property User $user
 */
class OrganizationMembership extends Model
{
    /** @use HasFactory<OrganizationMembershipFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'user_id',
        'role',
        'status',
        'invited_by',
        'joined_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'status' => MembershipStatus::class,
            'joined_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
