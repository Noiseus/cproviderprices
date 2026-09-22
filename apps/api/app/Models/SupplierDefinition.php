<?php

namespace App\Models;

use Database\Factories\SupplierDefinitionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierDefinition extends Model
{
    /** @use HasFactory<SupplierDefinitionFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $fillable = ['code', 'name', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<SupplierAccount, $this> */
    public function accounts(): HasMany
    {
        return $this->hasMany(SupplierAccount::class);
    }
}
