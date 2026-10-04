<?php

namespace App\Models;

use App\Support\CatalogText;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['parent_id', 'code', 'slug', 'english_name'];

    protected static function booted(): void
    {
        static::saving(function (self $category): void {
            $category->code = str_replace(' ', '-', CatalogText::normalize($category->code));
            $category->slug = str_replace(' ', '-', CatalogText::normalize($category->slug));
        });
    }

    /** @return BelongsTo<Category, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<Category, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return HasMany<CategoryTranslation, $this> */
    public function translations(): HasMany
    {
        return $this->hasMany(CategoryTranslation::class);
    }

    /** @return HasMany<CanonicalProduct, $this> */
    public function canonicalProducts(): HasMany
    {
        return $this->hasMany(CanonicalProduct::class);
    }
}
