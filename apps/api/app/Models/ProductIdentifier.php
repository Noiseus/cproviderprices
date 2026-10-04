<?php

namespace App\Models;

use Database\Factories\ProductIdentifierFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductIdentifier extends Model
{
    /** @use HasFactory<ProductIdentifierFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['canonical_product_id', 'product_variant_id', 'identifier_type', 'identifier_value', 'normalized_value'];

    protected static function booted(): void
    {
        static::saving(function (self $identifier): void {
            $identifier->identifier_type = mb_strtolower(trim($identifier->identifier_type));
            $identifier->normalized_value = preg_replace('/[^A-Za-z0-9]/', '', mb_strtoupper($identifier->identifier_value)) ?? '';
        });
    }

    /** @return BelongsTo<CanonicalProduct, $this> */
    public function canonicalProduct(): BelongsTo
    {
        return $this->belongsTo(CanonicalProduct::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
