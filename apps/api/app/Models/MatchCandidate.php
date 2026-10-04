<?php

namespace App\Models;

use App\Enums\MatchCandidateStatus;
use App\Enums\MatchClass;
use App\Enums\MatchConfidence;
use Carbon\CarbonImmutable;
use Database\Factories\MatchCandidateFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property MatchClass $proposed_match_class
 * @property MatchConfidence $confidence
 * @property MatchCandidateStatus $status
 * @property array<string, mixed> $evidence
 * @property CarbonImmutable $generated_at
 * @property VendorItem $vendorItem
 * @property CanonicalProduct $canonicalProduct
 * @property ProductVariant|null $productVariant
 */
class MatchCandidate extends Model
{
    /** @use HasFactory<MatchCandidateFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['vendor_item_id', 'canonical_product_id', 'product_variant_id', 'candidate_key', 'proposed_match_class', 'confidence', 'score', 'rank', 'evidence', 'evidence_fingerprint', 'matcher_version', 'status', 'generated_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['proposed_match_class' => MatchClass::class, 'confidence' => MatchConfidence::class, 'status' => MatchCandidateStatus::class, 'evidence' => 'array', 'generated_at' => 'immutable_datetime'];
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

    /** @return HasMany<MatchReview, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(MatchReview::class);
    }
}
