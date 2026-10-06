<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property string $token_hash
 * @property string $device_name
 * @property int $auth_version
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $last_used_at
 * @property User $user
 */
final class MobileAccessToken extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'id',
        'user_id',
        'token_hash',
        'device_name',
        'auth_version',
        'expires_at',
        'revoked_at',
        'last_used_at',
    ];

    protected $hidden = ['token_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'auth_version' => 'integer',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
        ];
    }

    /** @return array{token: string, accessToken: self} */
    public static function issue(User $user, string $deviceName): array
    {
        self::query()
            ->where('user_id', $user->id)
            ->where('device_name', $deviceName)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        $id = (string) Str::uuid();
        $plainTextToken = 'cpm1.'.$id.'.'.bin2hex(random_bytes(32));
        $accessToken = self::query()->create([
            'id' => $id,
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plainTextToken),
            'device_name' => $deviceName,
            'auth_version' => $user->auth_version,
            'expires_at' => now()->addDays((int) config('mobile.access_token_ttl_days')),
        ]);

        return ['token' => $plainTextToken, 'accessToken' => $accessToken];
    }

    public static function findByPlainText(string $plainTextToken): ?self
    {
        if (strlen($plainTextToken) > 512 || preg_match('/\Acpm1\.[0-9a-f-]{36}\.[0-9a-f]{64}\z/iD', $plainTextToken) !== 1) {
            return null;
        }

        return self::query()
            ->with('user')
            ->where('token_hash', hash('sha256', $plainTextToken))
            ->first();
    }

    public function revoke(): void
    {
        if ($this->revoked_at === null) {
            $this->forceFill(['revoked_at' => now()])->save();
        }
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
