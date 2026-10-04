<?php

namespace App\Services;

use App\Models\CanonicalProduct;
use App\Models\Category;
use App\Models\MatchCandidate;
use App\Models\SupplierAccount;
use App\Support\CatalogText;
use Illuminate\Pagination\LengthAwarePaginator;

final class PlatformMatchingQueryService
{
    /** @param array{supplier_account_id?: string|null, confidence?: string|null, category_id?: string|null, age_days?: int|null, status?: string|null, page: int, per_page: int} $filters
     * @return LengthAwarePaginator<int, MatchCandidate>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = MatchCandidate::query()->with(['vendorItem.supplierAccount.supplierDefinition', 'canonicalProduct.category', 'productVariant']);
        if (($filters['supplier_account_id'] ?? null) !== null) {
            $query->whereHas('vendorItem', fn ($vendor) => $vendor->where('supplier_account_id', $filters['supplier_account_id']));
        }
        if (($filters['confidence'] ?? null) !== null) {
            $query->where('confidence', $filters['confidence']);
        }
        if (($filters['category_id'] ?? null) !== null) {
            $query->whereHas('canonicalProduct', fn ($product) => $product->where('category_id', $filters['category_id']));
        }
        if (($filters['age_days'] ?? null) !== null) {
            $query->where('generated_at', '<=', now()->subDays($filters['age_days']));
        }
        if (($filters['status'] ?? null) !== null) {
            $query->where('status', $filters['status']);
        }

        return $query->orderByDesc('generated_at')->orderByDesc('id')->paginate($filters['per_page'], ['*'], 'page', $filters['page']);
    }

    /** @return array<string, mixed> */
    public function summary(MatchCandidate $candidate): array
    {
        $vendor = $candidate->vendorItem;
        $product = $candidate->canonicalProduct;

        return ['id' => $candidate->id, 'status' => $candidate->status->value, 'confidence' => $candidate->confidence->value, 'proposed_match_class' => $candidate->proposed_match_class->value, 'score' => $candidate->score, 'rank' => $candidate->rank, 'generated_at' => $candidate->generated_at->toIso8601String(), 'matcher_version' => $candidate->matcher_version,
            'vendor_item' => ['id' => $vendor->id, 'raw_description' => $vendor->raw_description, 'supplier' => ['id' => $vendor->supplierAccount->id, 'display_name' => $vendor->supplierAccount->display_name, 'definition_name' => $vendor->supplierAccount->supplierDefinition->name]],
            'candidate_product' => ['id' => $product->id, 'english_name' => $product->english_name, 'category' => ['id' => $product->category->id, 'english_name' => $product->category->english_name], 'variant_id' => $candidate->product_variant_id],
            'evidence_summary' => ['stage' => $candidate->evidence['stage'] ?? null, 'hard_contradictions' => count($candidate->evidence['hard_contradictions'] ?? []), 'attribute_matches' => count($candidate->evidence['attribute_matches'] ?? [])]];
    }

    /** @return array<string, mixed> */
    public function detail(MatchCandidate $candidate): array
    {
        $candidate->loadMissing(['vendorItem.supplierAccount.supplierDefinition', 'canonicalProduct.category', 'canonicalProduct.aliases', 'canonicalProduct.variants', 'productVariant', 'reviews.actor', 'vendorItem.mappings.canonicalProduct', 'vendorItem.matchReviews.actor']);
        $vendor = $candidate->vendorItem;
        $product = $candidate->canonicalProduct;

        return [...$this->summary($candidate), 'vendor_item' => ['id' => $vendor->id, 'supplier_item_identifier' => $vendor->supplier_item_identifier, 'raw_description' => $vendor->raw_description, 'normalized_description' => $vendor->normalized_description, 'brand' => $vendor->brand, 'normalized_attributes' => $vendor->normalized_attributes, 'parser_version' => $vendor->parser_version, 'supplier' => ['id' => $vendor->supplierAccount->id, 'display_name' => $vendor->supplierAccount->display_name, 'definition_name' => $vendor->supplierAccount->supplierDefinition->name]],
            'candidate_product' => ['id' => $product->id, 'english_name' => $product->english_name, 'attributes' => $product->attributes, 'category' => ['id' => $product->category->id, 'english_name' => $product->category->english_name], 'aliases' => $product->aliases->map(fn ($alias) => ['locale' => $alias->locale, 'alias' => $alias->alias])->all(), 'variant' => $candidate->productVariant === null ? null : ['id' => $candidate->productVariant->id, 'code' => $candidate->productVariant->code, 'attributes' => $candidate->productVariant->attributes]],
            'evidence' => $candidate->evidence,
            'mapping_history' => $vendor->mappings->sortByDesc('verified_at')->map(fn ($mapping) => ['id' => $mapping->id, 'canonical_product_id' => $mapping->canonical_product_id, 'canonical_name' => $mapping->canonicalProduct->english_name, 'match_class' => $mapping->match_class->value, 'confidence' => $mapping->confidence->value, 'origin' => $mapping->origin->value, 'is_current' => $mapping->is_current, 'verified_at' => $mapping->verified_at->toIso8601String(), 'superseded_at' => $mapping->superseded_at?->toIso8601String()])->values()->all(),
            'review_history' => $vendor->matchReviews->sortByDesc('reviewed_at')->map(fn ($review) => ['id' => $review->id, 'action' => $review->action->value, 'actor' => $review->actor->email, 'reason' => $review->reason, 'reviewed_at' => $review->reviewed_at->toIso8601String()])->values()->all()];
    }

    /** @return array<string, list<array<string, string>>> */
    public function filterOptions(): array
    {
        return ['suppliers' => SupplierAccount::query()->whereHas('vendorItems.matchCandidates')->orderBy('display_name')->limit(100)->get()->map(fn ($account) => ['id' => $account->id, 'name' => $account->display_name])->all(), 'categories' => Category::query()->whereHas('canonicalProducts.matchCandidates')->orderBy('english_name')->limit(100)->get()->map(fn ($category) => ['id' => $category->id, 'name' => $category->english_name])->all()];
    }

    /** @return list<array{id: string, english_name: string, variants: list<array{id: string, code: string|null}>}> */
    public function canonicalProducts(?string $query, ?string $categoryId): array
    {
        $products = CanonicalProduct::query()
            ->where('is_active', true)
            ->when($categoryId !== null, fn ($query) => $query->where('category_id', $categoryId))
            ->when($query !== null && trim($query) !== '', fn ($builder) => $builder->where('normalized_key', 'like', '%'.CatalogText::normalize($query).'%'))
            ->with('variants')
            ->orderBy('english_name')
            ->limit(20)
            ->get();
        $result = [];
        foreach ($products as $product) {
            $variants = [];
            foreach ($product->variants->where('is_active', true) as $variant) {
                $variants[] = ['id' => $variant->id, 'code' => $variant->code];
            }
            $result[] = ['id' => $product->id, 'english_name' => $product->english_name, 'variants' => $variants];
        }

        return $result;
    }
}
