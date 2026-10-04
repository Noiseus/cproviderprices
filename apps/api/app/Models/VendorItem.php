<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\VendorItemFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Tenant-private supplier item. It intentionally has no price columns.
 *
 * @property array<string, mixed> $normalized_attributes
 * @property CarbonImmutable $first_seen_at
 * @property CarbonImmutable $last_seen_at
 * @property SupplierAccount $supplierAccount
 */
class VendorItem extends Model
{
    /** @use HasFactory<VendorItemFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['organization_id', 'supplier_account_id', 'first_normalized_import_record_id', 'last_normalized_import_record_id', 'supplier_item_identifier', 'identity_key', 'raw_description', 'normalized_description', 'brand', 'normalized_attributes', 'parser_version', 'first_seen_at', 'last_seen_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['normalized_attributes' => 'array', 'first_seen_at' => 'immutable_datetime', 'last_seen_at' => 'immutable_datetime'];
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

    /** @return BelongsTo<NormalizedImportRecord, $this> */
    public function firstNormalizedImportRecord(): BelongsTo
    {
        return $this->belongsTo(NormalizedImportRecord::class, 'first_normalized_import_record_id');
    }

    /** @return BelongsTo<NormalizedImportRecord, $this> */
    public function lastNormalizedImportRecord(): BelongsTo
    {
        return $this->belongsTo(NormalizedImportRecord::class, 'last_normalized_import_record_id');
    }

    /** @return HasMany<VendorItemMapping, $this> */
    public function mappings(): HasMany
    {
        return $this->hasMany(VendorItemMapping::class);
    }

    /** @return HasOne<VendorItemMapping, $this> */
    public function currentMapping(): HasOne
    {
        return $this->hasOne(VendorItemMapping::class)->where('is_current', true);
    }

    /** @return HasMany<MatchCandidate, $this> */
    public function matchCandidates(): HasMany
    {
        return $this->hasMany(MatchCandidate::class);
    }

    /** @return HasMany<MatchReview, $this> */
    public function matchReviews(): HasMany
    {
        return $this->hasMany(MatchReview::class);
    }

    /** @return HasMany<SupplierOffer, $this> */
    public function supplierOffers(): HasMany
    {
        return $this->hasMany(SupplierOffer::class);
    }
}
