<?php

namespace App\Models;

use App\Enums\InvitationStatus;
use App\Enums\Role;
use Carbon\CarbonImmutable;
use Database\Factories\InvitationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property InvitationStatus $status
 * @property Role $intended_role
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable $created_at
 * @property Organization $organization
 */
class Invitation extends Model
{
    /** @use HasFactory<InvitationFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'intended_email',
        'intended_role',
        'status',
        'token_hash',
        'invited_by',
        'expires_at',
        'accepted_at',
        'revoked_at',
    ];

    /** @var list<string> */
    protected $hidden = ['token_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'intended_role' => Role::class,
            'status' => InvitationStatus::class,
            'expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public function setIntendedEmailAttribute(string $email): void
    {
        $this->attributes['intended_email'] = mb_strtolower(trim($email));
    }

    public static function hashPlaintextToken(string $plaintextToken): string
    {
        return hash_hmac('sha256', $plaintextToken, (string) config('app.key'));
    }

    public function lifecycleStatus(?Carbon $at = null): InvitationStatus
    {
        $status = $this->status;

        if ($status === InvitationStatus::Pending && $this->expires_at->lessThanOrEqualTo($at ?? now())) {
            return InvitationStatus::Expired;
        }

        return $status;
    }

    public function isActive(?Carbon $at = null): bool
    {
        return $this->lifecycleStatus($at) === InvitationStatus::Pending;
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
