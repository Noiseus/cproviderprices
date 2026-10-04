<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array<string, mixed> $normalized_values
 * @property list<array{code: string, field: string, message: string}> $warnings
 * @property string $confidence
 */
class NormalizedImportRecord extends Model
{
    use HasUuids;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new \LogicException('Normalized results are versioned and immutable.');
        });
        static::deleting(function (): never {
            throw new \LogicException('Normalized results are versioned and immutable.');
        });
    }

    /** @var list<string> */
    protected $fillable = ['organization_id', 'import_run_id', 'raw_import_record_id', 'parser_version', 'normalized_values', 'warnings', 'confidence'];

    /** @var list<string> */
    protected $hidden = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['normalized_values' => 'array', 'warnings' => 'array'];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<ImportRun, $this> */
    public function importRun(): BelongsTo
    {
        return $this->belongsTo(ImportRun::class);
    }

    /** @return BelongsTo<RawImportRecord, $this> */
    public function rawImportRecord(): BelongsTo
    {
        return $this->belongsTo(RawImportRecord::class);
    }
}
