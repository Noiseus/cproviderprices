<?php

namespace App\Models;

use App\Enums\ImportStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property ImportStatus $status
 * @property array<string, mixed>|null $mapping_snapshot
 */
class ImportRun extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['organization_id', 'restaurant_location_id', 'supplier_account_id', 'created_by', 'mapping_template_id', 'fingerprint', 'status', 'request_id', 'mapping_snapshot', 'processed_count', 'warning_count', 'failed_count', 'retry_count', 'started_at', 'finished_at'];

    /** @var list<string> */
    protected $hidden = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => ImportStatus::class, 'mapping_snapshot' => 'array', 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime', 'validation_started_at' => 'immutable_datetime', 'validation_completed_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<SupplierAccount, $this> */
    public function supplierAccount(): BelongsTo
    {
        return $this->belongsTo(SupplierAccount::class);
    }

    /** @return BelongsTo<ImportMappingTemplate, $this> */
    public function mappingTemplate(): BelongsTo
    {
        return $this->belongsTo(ImportMappingTemplate::class);
    }
}
