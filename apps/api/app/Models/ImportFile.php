<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportFile extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['organization_id', 'import_run_id', 'original_filename', 'storage_key', 'size_bytes', 'media_type', 'format', 'headers', 'sample_rows'];

    /** @var list<string> */
    protected $hidden = ['storage_key', 'sample_rows'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['headers' => 'array', 'sample_rows' => 'array'];
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
}
