<?php

namespace Database\Factories;

use App\Models\MatchReview;
use App\Models\User;
use App\Models\VendorItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MatchReview> */
class MatchReviewFactory extends Factory
{
    protected $model = MatchReview::class;

    public function definition(): array
    {
        return ['vendor_item_id' => VendorItem::factory(), 'match_candidate_id' => null, 'vendor_item_mapping_id' => null, 'canonical_product_id' => null, 'product_variant_id' => null, 'actor_id' => User::factory(), 'action' => 'reject', 'reason' => 'Factory review reason', 'previous_state' => [], 'resulting_state' => [], 'evidence_context' => [], 'reviewed_at' => now()];
    }
}
