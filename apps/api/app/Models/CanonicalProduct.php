<?php

namespace App\Models;

use App\Support\CatalogText;
use Database\Factories\CanonicalProductFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property array<string, mixed> $attributes */
class CanonicalProduct extends Model
{
    /** @use HasFactory<CanonicalProductFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['category_id', 'english_name', 'normalized_key', 'attributes', 'is_active'];

    protected static function booted(): void
    {
        static::saving(function (self $product): void {
            $product->normalized_key = CatalogText::normalize($product->normalized_key ?: $product->english_name);
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['attributes' => 'array', 'is_active' => 'boolean'];
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return HasMany<ProductVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    /** @return HasMany<ProductAlias, $this> */
    public function aliases(): HasMany
    {
        return $this->hasMany(ProductAlias::class);
    }

    /** @return HasMany<ProductIdentifier, $this> */
    public function identifiers(): HasMany
    {
        return $this->hasMany(ProductIdentifier::class);
    }

    /** @return HasMany<MatchCandidate, $this> */
    public function matchCandidates(): HasMany
    {
        return $this->hasMany(MatchCandidate::class);
    }
}
