<?php

namespace App\Models;

use App\Enums\ReviewAction;
use Carbon\CarbonImmutable;
use Database\Factories\MatchReviewFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property ReviewAction $action
 * @property array<string, mixed> $previous_state
 * @property array<string, mixed> $resulting_state
 * @property array<string, mixed> $evidence_context
 * @property CarbonImmutable $reviewed_at
 * @property User $actor
 */
class MatchReview extends Model
{
    /** @use HasFactory<MatchReviewFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['vendor_item_id', 'match_candidate_id', 'vendor_item_mapping_id', 'canonical_product_id', 'product_variant_id', 'actor_id', 'action', 'reason', 'previous_state', 'resulting_state', 'evidence_context', 'reviewed_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['action' => ReviewAction::class, 'previous_state' => 'array', 'resulting_state' => 'array', 'evidence_context' => 'array', 'reviewed_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<VendorItem, $this> */
    public function vendorItem(): BelongsTo
    {
        return $this->belongsTo(VendorItem::class);
    }

    /** @return BelongsTo<MatchCandidate, $this> */
    public function matchCandidate(): BelongsTo
    {
        return $this->belongsTo(MatchCandidate::class);
    }

    /** @return BelongsTo<VendorItemMapping, $this> */
    public function vendorItemMapping(): BelongsTo
    {
        return $this->belongsTo(VendorItemMapping::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
