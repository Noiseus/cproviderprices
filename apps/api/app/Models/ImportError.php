<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportError extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['organization_id', 'import_run_id', 'source_row', 'field', 'code', 'message', 'error_key'];

    /** @var list<string> */
    protected $hidden = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [];
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
