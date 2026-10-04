<?php

namespace App\Models;

use App\Support\CatalogText;
use Database\Factories\ProductAliasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductAlias extends Model
{
    /** @use HasFactory<ProductAliasFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['canonical_product_id', 'product_variant_id', 'locale', 'alias', 'normalized_alias'];

    protected static function booted(): void
    {
        static::saving(function (self $alias): void {
            $alias->locale = mb_strtolower(trim($alias->locale));
            $alias->normalized_alias = CatalogText::normalize($alias->alias);
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
