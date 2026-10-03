<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array<string, string> $raw_values
 * @property array<string, string> $mapped_values
 */
class RawImportRecord extends Model
{
    use HasUuids;

    protected static function booted(): void
    {
        static::updating(function (self $record): void {
            if ($record->isDirty(['organization_id', 'import_run_id', 'import_file_id', 'source_row', 'raw_values', 'mapped_values'])) {
                throw new \LogicException('Raw import evidence cannot be overwritten.');
            }
        });
        static::deleting(function (): never {
            throw new \LogicException('Raw import evidence cannot be deleted.');
        });
    }

    /** @var list<string> */
    protected $fillable = ['organization_id', 'import_run_id', 'import_file_id', 'source_row', 'raw_values', 'mapped_values', 'status'];

    /** @var list<string> */
    protected $hidden = ['raw_values', 'mapped_values'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['raw_values' => 'array', 'mapped_values' => 'array'];
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

    /** @return BelongsTo<ImportFile, $this> */
    public function importFile(): BelongsTo
    {
        return $this->belongsTo(ImportFile::class);
    }
}
