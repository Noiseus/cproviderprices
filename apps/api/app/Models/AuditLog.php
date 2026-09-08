<?php

namespace App\Models;

use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory, HasUuids;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'actor_id',
        'actor_type',
        'actor_label',
        'action',
        'subject_type',
        'subject_id',
        'metadata',
        'occurred_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AuditLog $auditLog): void {
            $auditLog->occurred_at ??= now();
        });

        static::updating(function (): never {
            throw new LogicException('Audit logs are immutable.');
        });

        static::deleting(function (): never {
            throw new LogicException('Audit logs are immutable.');
        });
    }
}
