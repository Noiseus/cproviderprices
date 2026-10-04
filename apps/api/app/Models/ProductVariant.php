<?php

namespace App\Models;

use App\Support\CatalogText;
use Database\Factories\ProductVariantFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property array<string, mixed> $attributes */
class ProductVariant extends Model
{
    /** @use HasFactory<ProductVariantFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['canonical_product_id', 'code', 'normalized_key', 'attributes', 'is_active'];

    protected static function booted(): void
    {
        static::saving(function (self $variant): void {
            $variant->normalized_key = CatalogText::normalize($variant->normalized_key ?: (string) $variant->code);
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['attributes' => 'array', 'is_active' => 'boolean'];
    }

    /** @return BelongsTo<CanonicalProduct, $this> */
    public function canonicalProduct(): BelongsTo
    {
        return $this->belongsTo(CanonicalProduct::class);
    }

    /** @return HasMany<ProductAlias, $this> */
    public function aliases(): HasMany
    {
        return $this->hasMany(ProductAlias::class);
    }
}
