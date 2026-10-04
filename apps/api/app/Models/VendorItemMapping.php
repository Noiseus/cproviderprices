<?php

namespace App\Models;

use App\Enums\MappingOrigin;
use App\Enums\MatchClass;
use App\Enums\MatchConfidence;
use Carbon\CarbonImmutable;
use Database\Factories\VendorItemMappingFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property MatchClass $match_class
 * @property MatchConfidence $confidence
 * @property MappingOrigin $origin
 * @property array<string, mixed> $evidence
 * @property bool $is_current
 * @property CarbonImmutable $verified_at
 * @property CarbonImmutable|null $superseded_at
 * @property CanonicalProduct $canonicalProduct
 * @property ProductVariant|null $productVariant
 */
class VendorItemMapping extends Model
{
    /** @use HasFactory<VendorItemMappingFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['vendor_item_id', 'canonical_product_id', 'product_variant_id', 'match_candidate_id', 'reviewed_by', 'supersedes_id', 'match_class', 'confidence', 'origin', 'matcher_version', 'evidence', 'is_current', 'verified_at', 'superseded_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['match_class' => MatchClass::class, 'confidence' => MatchConfidence::class, 'origin' => MappingOrigin::class, 'evidence' => 'array', 'is_current' => 'boolean', 'verified_at' => 'immutable_datetime', 'superseded_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<VendorItem, $this> */
    public function vendorItem(): BelongsTo
    {
        return $this->belongsTo(VendorItem::class);
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

    /** @return BelongsTo<MatchCandidate, $this> */
    public function matchCandidate(): BelongsTo
    {
        return $this->belongsTo(MatchCandidate::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** @return BelongsTo<VendorItemMapping, $this> */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }
}
