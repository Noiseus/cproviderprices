<?php

namespace Database\Factories;

use App\Models\CanonicalProduct;
use App\Models\MatchCandidate;
use App\Models\VendorItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MatchCandidate> */
class MatchCandidateFactory extends Factory
{
    protected $model = MatchCandidate::class;

    public function definition(): array
    {
        $product = CanonicalProduct::factory();

        return ['vendor_item_id' => VendorItem::factory(), 'canonical_product_id' => $product, 'product_variant_id' => null, 'candidate_key' => 'product:'.fake()->uuid(), 'proposed_match_class' => 'equivalent_product', 'confidence' => 'C', 'score' => 60, 'rank' => 1, 'evidence' => ['stage' => 'alias_text'], 'evidence_fingerprint' => hash('sha256', fake()->uuid()), 'matcher_version' => 'catalog-matcher-1.0.0', 'status' => 'pending', 'generated_at' => now()];
    }
}
