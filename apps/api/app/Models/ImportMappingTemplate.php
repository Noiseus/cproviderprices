<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportMappingTemplate extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['organization_id', 'supplier_account_id', 'name', 'headers', 'mapping', 'source_conventions'];

    /** @var list<string> */
    protected $hidden = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['headers' => 'array', 'mapping' => 'array', 'source_conventions' => 'array'];
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
}
