<?php

namespace App\Services;

use App\Enums\MappingOrigin;
use App\Enums\MatchCandidateStatus;
use App\Enums\MatchClass;
use App\Enums\MatchConfidence;
use App\Models\CanonicalProduct;
use App\Models\MatchCandidate;
use App\Models\ProductAlias;
use App\Models\ProductIdentifier;
use App\Models\VendorItem;
use App\Models\VendorItemMapping;
use App\Support\CatalogText;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Deterministic, bounded candidate generation. It never performs fuzzy/LLM matching. */
final class CatalogMatcher
{
    public const VERSION = 'catalog-matcher-1.0.0';

    private const MAX_CANDIDATES = 25;

    /** @return Collection<int, MatchCandidate> */
    public function generate(VendorItem $vendorItem): Collection
    {
        return DB::transaction(function () use ($vendorItem): Collection {
            $vendor = VendorItem::query()->whereKey($vendorItem->id)->lockForUpdate()->firstOrFail();
            if ($vendor->currentMapping()->exists()) {
                Log::info('catalog.match.skipped_verified_mapping', ['vendor_item_id' => $vendor->id, 'matcher_version' => self::VERSION]);

                return new Collection;
            }
            $identifier = $this->identifierCandidates($vendor);
            if ($identifier !== []) {
                return $this->persist($vendor, $identifier);
            }
            $exact = $this->exactAttributeCandidates($vendor);
            if ($exact !== []) {
                return $this->persist($vendor, $exact);
            }
            $alias = $this->aliasCandidates($vendor);
            $weighted = $this->weightedCandidates($vendor, array_keys($alias));

            return $this->persist($vendor, [...$alias, ...$weighted]);
        });
    }

    /** @return list<array<string, mixed>> */
    private function identifierCandidates(VendorItem $vendor): array
    {
        $identifiers = $vendor->normalized_attributes['identifiers'] ?? [];
        if (! is_array($identifiers)) {
            return [];
        }
        $candidates = [];
        foreach ($identifiers as $identifier) {
            if (! is_array($identifier) || ! is_string($identifier['type'] ?? null) || ! is_string($identifier['value'] ?? null)) {
                continue;
            }
            $normalized = preg_replace('/[^A-Za-z0-9]/', '', mb_strtoupper($identifier['value'])) ?? '';
            if ($normalized === '') {
                continue;
            }
            foreach (ProductIdentifier::query()->with(['canonicalProduct.category', 'productVariant'])->where('identifier_type', $identifier['type'])->where('normalized_value', $normalized)->orderBy('id')->get() as $match) {
                $candidates[] = $this->proposal($vendor, $match->canonicalProduct, $match->productVariant, 'identifier', 100, MatchClass::ExactMatch, MatchConfidence::A, ['identifier' => ['type' => $identifier['type'], 'matched' => $normalized]], true);
            }
        }

        return $candidates;
    }

    /** @return list<array<string, mixed>> */
    private function exactAttributeCandidates(VendorItem $vendor): array
    {
        $description = $vendor->normalized_description;
        $products = CanonicalProduct::query()->with(['category', 'aliases', 'variants'])->where('is_active', true)->where('normalized_key', $description)->orderBy('id')->limit(self::MAX_CANDIDATES)->get();
        $candidates = [];
        foreach ($products as $product) {
            $comparison = $this->compatibility($vendor, $product, null);
            if ($comparison['hard_contradictions'] !== [] || ! $comparison['all_known_product_attributes_match']) {
                continue;
            }
            $qualified = count($comparison['attribute_matches']) >= 2
                && ($vendor->normalized_attributes['parser_confidence'] ?? null) === 'high'
                && ($vendor->normalized_attributes['variable_weight'] ?? false) === false
                && ($vendor->normalized_attributes['parser_warnings'] ?? []) === [];
            $candidates[] = $this->proposal($vendor, $product, null, 'exact_normalized_attributes', 95, MatchClass::ExactMatch, MatchConfidence::A, $comparison, $qualified);
        }

        return $candidates;
    }

    /** @return array<string, array<string, mixed>> keyed candidate target */
    private function aliasCandidates(VendorItem $vendor): array
    {
        $tokens = array_values(array_filter(CatalogText::tokens($vendor->normalized_description), static fn (string $token): bool => mb_strlen($token) >= 4));
        if ($tokens === []) {
            return [];
        }
        $query = ProductAlias::query()->with(['canonicalProduct.category', 'productVariant'])->where(function ($query) use ($tokens): void {
            foreach (array_slice($tokens, 0, 3) as $token) {
                $query->orWhere('normalized_alias', 'like', '%'.$token.'%');
            }
        })->orderBy('canonical_product_id')->orderBy('product_variant_id')->orderBy('id')->limit(self::MAX_CANDIDATES);
        $found = [];
        foreach ($query->get() as $alias) {
            $product = $alias->canonicalProduct;
            if (! $product->is_active) {
                continue;
            }
            $key = $this->candidateKey($product->id, $alias->product_variant_id);
            $aliasTokens = CatalogText::tokens($alias->normalized_alias);
            $overlap = count(array_intersect($tokens, $aliasTokens));
            if ($overlap === 0) {
                continue;
            }
            $completeAlias = $aliasTokens !== [] && count(array_diff($aliasTokens, CatalogText::tokens($vendor->normalized_description))) === 0;
            $score = $completeAlias ? 80 : min(70, 25 + ($overlap * 20));
            $comparison = $this->compatibility($vendor, $product, $alias->productVariant);
            $class = $comparison['hard_contradictions'] === [] ? MatchClass::EquivalentProduct : MatchClass::ComparableAlternative;
            $confidence = $comparison['hard_contradictions'] === [] && $completeAlias ? MatchConfidence::B : ($score >= 50 ? MatchConfidence::C : MatchConfidence::D);
            $proposal = $this->proposal($vendor, $product, $alias->productVariant, 'alias_text', $score, $class, $confidence, [...$comparison, 'alias' => ['locale' => $alias->locale, 'matched_alias' => $alias->alias, 'complete_token_match' => $completeAlias]], false);
            if (! isset($found[$key]) || $proposal['score'] > $found[$key]['score']) {
                $found[$key] = $proposal;
            }
        }

        return $found;
    }

    /** @param list<string> $existingKeys
     * @return list<array<string, mixed>>
     */
    private function weightedCandidates(VendorItem $vendor, array $existingKeys): array
    {
        $tokens = array_values(array_filter(CatalogText::tokens($vendor->normalized_description), static fn (string $token): bool => mb_strlen($token) >= 4));
        if ($tokens === []) {
            return [];
        }
        $products = CanonicalProduct::query()->with('category')->where('is_active', true)->where(function ($query) use ($tokens): void {
            foreach (array_slice($tokens, 0, 3) as $token) {
                $query->orWhere('normalized_key', 'like', '%'.$token.'%');
            }
        })->orderBy('category_id')->orderBy('normalized_key')->orderBy('id')->limit(self::MAX_CANDIDATES)->get();
        $result = [];
        foreach ($products as $product) {
            $key = $this->candidateKey($product->id, null);
            if (in_array($key, $existingKeys, true)) {
                continue;
            }
            $comparison = $this->compatibility($vendor, $product, null);
            $overlap = count(array_intersect($tokens, CatalogText::tokens($product->normalized_key)));
            $score = min(60, $overlap * 25 + count($comparison['attribute_matches']) * 10);
            if ($score < 25) {
                continue;
            }
            $class = $comparison['hard_contradictions'] === [] ? MatchClass::RelatedProduct : MatchClass::NotComparable;
            $confidence = $comparison['hard_contradictions'] === [] ? ($score >= 50 ? MatchConfidence::C : MatchConfidence::D) : MatchConfidence::E;
            $result[] = $this->proposal($vendor, $product, null, 'weighted_attributes', $score, $class, $confidence, [...$comparison, 'weighted' => ['text_overlap_tokens' => $overlap, 'attribute_weight' => count($comparison['attribute_matches']) * 10]], false);
        }

        return $result;
    }

    /** @return array<string, mixed> */
    /**
     * @param  array<string, mixed>  $comparison
     * @return array<string, mixed>
     */
    private function proposal(VendorItem $vendor, CanonicalProduct $product, mixed $variant, string $stage, int $score, MatchClass $class, MatchConfidence $confidence, array $comparison, bool $autoEligible): array
    {
        $variantId = $variant?->id;
        $evidence = [
            'matcher_version' => self::VERSION,
            'stage' => $stage,
            'score' => $score,
            'weights' => ['identifier' => 100, 'exact_normalized_attributes' => 95, 'complete_alias' => 80, 'token_overlap' => 20, 'attribute_match' => 10],
            'vendor' => ['normalized_description' => $vendor->normalized_description, 'size_code' => $vendor->normalized_attributes['size_code'] ?? null, 'dimensions' => $vendor->normalized_attributes['dimensions'] ?? [], 'parser_confidence' => $vendor->normalized_attributes['parser_confidence'] ?? null],
            'candidate' => ['canonical_product_id' => $product->id, 'canonical_name' => $product->english_name, 'category_id' => $product->category_id, 'variant_id' => $variantId],
            ...$comparison,
        ];

        return ['canonical_product_id' => $product->id, 'product_variant_id' => $variantId, 'candidate_key' => $this->candidateKey($product->id, $variantId), 'proposed_match_class' => $class, 'confidence' => $confidence, 'score' => $score, 'evidence' => $evidence, 'auto_eligible' => $autoEligible && $class === MatchClass::ExactMatch && $confidence === MatchConfidence::A && ($comparison['hard_contradictions'] ?? []) === []];
    }

    /** @return array{attribute_matches: list<array<string, mixed>>, attribute_differences: list<array<string, mixed>>, hard_contradictions: list<array<string, mixed>>, all_known_product_attributes_match: bool} */
    private function compatibility(VendorItem $vendor, CanonicalProduct $product, mixed $variant): array
    {
        $vendorAttributes = $vendor->normalized_attributes;
        $target = [...$product->attributes, ...(is_object($variant) ? $variant->attributes : [])];
        $matches = [];
        $differences = [];
        $hard = [];
        foreach (['family', 'form', 'variety', 'size_code', 'dimension'] as $key) {
            $vendorValue = $key === 'dimension' ? ($vendorAttributes['dimensions'][0] ?? null) : ($vendorAttributes[$key] ?? null);
            $candidateValue = $target[$key] ?? null;
            if ($vendorValue === null || $candidateValue === null) {
                continue;
            }
            if ($vendorValue === $candidateValue) {
                $matches[] = ['attribute' => $key, 'value' => $candidateValue];
            } else {
                $difference = ['attribute' => $key, 'vendor_value' => $vendorValue, 'candidate_value' => $candidateValue];
                $differences[] = $difference;
                $hard[] = $difference;
            }
        }

        return ['attribute_matches' => $matches, 'attribute_differences' => $differences, 'hard_contradictions' => $hard, 'all_known_product_attributes_match' => $matches !== [] && $hard === []];
    }

    /** @param array<int|string, array<string, mixed>> $proposals
     * @return Collection<int, MatchCandidate>
     */
    private function persist(VendorItem $vendor, array $proposals): Collection
    {
        $deduplicated = [];
        foreach ($proposals as $proposal) {
            $key = $proposal['candidate_key'];
            if (! isset($deduplicated[$key]) || $proposal['score'] > $deduplicated[$key]['score']) {
                $deduplicated[$key] = $proposal;
            }
        }
        uasort($deduplicated, static fn (array $left, array $right): int => [$right['score'], $left['candidate_key']] <=> [$left['score'], $right['candidate_key']]);
        $saved = new Collection;
        $rank = 1;
        foreach (array_slice($deduplicated, 0, self::MAX_CANDIDATES) as $proposal) {
            $fingerprint = CatalogText::fingerprint([
                'vendor_identity' => $vendor->identity_key,
                'description' => $vendor->normalized_description,
                'attributes' => $vendor->normalized_attributes,
                'candidate_key' => $proposal['candidate_key'],
                'matcher_version' => self::VERSION,
                'stage' => $proposal['evidence']['stage'],
            ]);
            $candidate = MatchCandidate::query()->firstOrCreate([
                'vendor_item_id' => $vendor->id,
                'candidate_key' => $proposal['candidate_key'],
                'matcher_version' => self::VERSION,
                'evidence_fingerprint' => $fingerprint,
            ], [
                'canonical_product_id' => $proposal['canonical_product_id'], 'product_variant_id' => $proposal['product_variant_id'],
                'proposed_match_class' => $proposal['proposed_match_class'], 'confidence' => $proposal['confidence'], 'score' => $proposal['score'], 'rank' => $rank,
                'evidence' => $proposal['evidence'], 'status' => MatchCandidateStatus::Pending, 'generated_at' => now(),
            ]);
            if ($candidate->status === MatchCandidateStatus::Pending && $proposal['auto_eligible'] === true) {
                VendorItemMapping::query()->create([
                    'vendor_item_id' => $vendor->id, 'canonical_product_id' => $candidate->canonical_product_id, 'product_variant_id' => $candidate->product_variant_id,
                    'match_candidate_id' => $candidate->id, 'reviewed_by' => null, 'supersedes_id' => null, 'match_class' => MatchClass::ExactMatch,
                    'confidence' => MatchConfidence::A, 'origin' => MappingOrigin::Matcher, 'matcher_version' => self::VERSION, 'evidence' => $candidate->evidence,
                    'is_current' => true, 'verified_at' => now(),
                ]);
                $candidate->update(['status' => MatchCandidateStatus::AutoPublished]);
                Log::info('catalog.match.auto_published', ['vendor_item_id' => $vendor->id, 'candidate_id' => $candidate->id, 'matcher_version' => self::VERSION]);
            }
            $saved->push($candidate);
            $rank++;
        }
        Log::info('catalog.match.generated', ['vendor_item_id' => $vendor->id, 'candidate_count' => $saved->count(), 'matcher_version' => self::VERSION]);

        return $saved;
    }

    private function candidateKey(string $productId, ?string $variantId): string
    {
        return $variantId === null ? 'product:'.$productId : 'variant:'.$variantId;
    }
}
