<?php

namespace App\Models;

use App\Support\CatalogText;
use Database\Factories\CategoryTranslationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CategoryTranslation extends Model
{
    /** @use HasFactory<CategoryTranslationFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['category_id', 'locale', 'name', 'normalized_name'];

    protected static function booted(): void
    {
        static::saving(function (self $translation): void {
            $translation->locale = mb_strtolower(trim($translation->locale));
            $translation->normalized_name = CatalogText::normalize($translation->name);
        });
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
