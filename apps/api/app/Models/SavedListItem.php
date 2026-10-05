<?php

namespace App\Models;

use Database\Factories\SavedListItemFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Durable purchasing intent for one canonical product/optional variant. */
class SavedListItem extends Model
{
    /** @use HasFactory<SavedListItemFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'saved_list_id',
        'organization_id',
        'restaurant_location_id',
        'canonical_product_id',
        'product_variant_id',
        'selected_offer_id',
        'quantity_target',
        'preferred_unit',
        'notes',
        'sort_order',
        'version',
        'created_by',
        'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quantity_target' => 'decimal:6',
            'sort_order' => 'integer',
            'version' => 'integer',
        ];
    }

    /** @return BelongsTo<SavedList, $this> */
    public function savedList(): BelongsTo
    {
        return $this->belongsTo(SavedList::class);
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

    /** @return BelongsTo<SupplierOffer, $this> */
    public function selectedOffer(): BelongsTo
    {
        return $this->belongsTo(SupplierOffer::class, 'selected_offer_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
