<?php

namespace App\Services;

use App\Enums\MappingOrigin;
use App\Enums\MatchCandidateStatus;
use App\Enums\MatchClass;
use App\Enums\MatchConfidence;
use App\Enums\ReviewAction;
use App\Models\CanonicalProduct;
use App\Models\MatchCandidate;
use App\Models\MatchReview;
use App\Models\ProductAlias;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\VendorItem;
use App\Models\VendorItemMapping;
use App\Support\ApiProblem;
use App\Support\Audit;
use App\Support\CatalogText;
use Illuminate\Support\Facades\DB;

final class CatalogReviewService
{
    public function __construct(private readonly SearchComparisonCache $cache) {}

    /** @param array<string, mixed> $input */
    public function review(MatchCandidate $candidate, User $actor, ReviewAction $action, array $input): MatchCandidate
    {
        $previousMapping = VendorItemMapping::query()
            ->where('vendor_item_id', $candidate->vendor_item_id)
            ->where('is_current', true)
            ->first();
        $reviewed = DB::transaction(function () use ($candidate, $actor, $action, $input): MatchCandidate {
            $locked = MatchCandidate::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            $vendor = $locked->vendorItem()->lockForUpdate()->firstOrFail();
            if ($locked->status !== MatchCandidateStatus::Pending) {
                if ($locked->reviews()->where('action', $action)->exists()) {
                    return $locked->refresh();
                }
                throw new ApiProblem('candidate_not_reviewable', 'This matching candidate has already been reviewed.', 409);
            }
            $previous = $this->mappingState($vendor->currentMapping()->lockForUpdate()->first());
            if ($action === ReviewAction::Reject) {
                $locked->update(['status' => MatchCandidateStatus::Rejected]);
                $this->reviewRecord($vendor->id, $locked, null, null, null, $actor, $action, $input['reason'] ?? null, $previous, ['candidate_status' => 'rejected']);
                Audit::record('catalog.match_review.rejected', $actor, $vendor->organization_id, 'match_candidate', $locked->id, ['vendor_item_id' => $vendor->id, 'matcher_version' => $locked->matcher_version]);

                return $locked->refresh();
            }

            [$product, $variant] = $this->target($locked, $action, $input);
            $mapping = $this->replaceMapping($vendor->id, $locked, $product, $variant, $actor, $input, $previous);
            $locked->update(['status' => MatchCandidateStatus::Accepted]);
            $this->reviewRecord($vendor->id, $locked, $mapping, $product, $variant, $actor, $action, $input['reason'] ?? null, $previous, $this->mappingState($mapping));
            Audit::record('catalog.match_review.'.$action->value, $actor, $vendor->organization_id, 'match_candidate', $locked->id, ['vendor_item_id' => $vendor->id, 'mapping_id' => $mapping->id, 'canonical_product_id' => $product->id, 'matcher_version' => $locked->matcher_version]);

            return $locked->refresh();
        });
        if ($reviewed->status === MatchCandidateStatus::Accepted) {
            $mapping = VendorItemMapping::query()
                ->where('match_candidate_id', $reviewed->id)
                ->where('is_current', true)
                ->first();
            if ($mapping instanceof VendorItemMapping) {
                $this->invalidateVendor(
                    $mapping->vendorItem()->firstOrFail(),
                    array_values(array_filter([$previousMapping?->canonical_product_id, $mapping->canonical_product_id])),
                );
            }
        }

        return $reviewed;
    }

    public function reverse(VendorItemMapping $mapping, User $actor, string $reason): VendorItemMapping
    {
        $wasCurrent = $mapping->is_current;
        $reversed = DB::transaction(function () use ($mapping, $actor, $reason): VendorItemMapping {
            $locked = VendorItemMapping::query()->whereKey($mapping->id)->lockForUpdate()->firstOrFail();
            $vendor = $locked->vendorItem()->lockForUpdate()->firstOrFail();
            if (! $locked->is_current) {
                return $locked;
            }
            $previous = $this->mappingState($locked);
            $locked->update(['is_current' => false, 'superseded_at' => now()]);
            $this->reviewRecord($vendor->id, null, $locked, $locked->canonicalProduct, $locked->productVariant, $actor, ReviewAction::Reverse, $reason, $previous, ['mapping_reversed' => true]);
            Audit::record('catalog.mapping.reversed', $actor, $vendor->organization_id, 'vendor_item_mapping', $locked->id, ['vendor_item_id' => $vendor->id, 'canonical_product_id' => $locked->canonical_product_id]);

            return $locked->refresh();
        });
        if ($wasCurrent) {
            $this->invalidateVendor(
                $reversed->vendorItem()->firstOrFail(),
                [$reversed->canonical_product_id],
            );
        }

        return $reversed;
    }

    /** @param array<string, mixed> $input
     * @return array{CanonicalProduct, ProductVariant|null}
     */
    private function target(MatchCandidate $candidate, ReviewAction $action, array $input): array
    {
        if ($action === ReviewAction::Accept) {
            return [$candidate->canonicalProduct, $candidate->productVariant];
        }
        if ($action === ReviewAction::ChooseExisting) {
            $product = CanonicalProduct::query()->where('is_active', true)->find($input['canonical_product_id'] ?? null);
            if ($product === null) {
                throw new ApiProblem('canonical_product_unavailable', 'The selected canonical product is unavailable.', 422);
            }

            return [$product, $this->variantFor($product, $input['product_variant_id'] ?? null)];
        }
        if ($action !== ReviewAction::CreateCanonical) {
            throw new ApiProblem('invalid_review_action', 'The review action is not supported.', 422);
        }
        /** @var array<string, mixed> $new */
        $new = $input['new_canonical'] ?? [];
        $key = CatalogText::normalize((string) ($new['english_name'] ?? ''));
        $categoryId = $new['category_id'] ?? null;
        if ($key === '' || ! is_string($categoryId)) {
            throw new ApiProblem('invalid_canonical_product', 'A canonical English name and category are required.', 422);
        }
        if (CanonicalProduct::query()->where('category_id', $categoryId)->where('normalized_key', $key)->lockForUpdate()->exists()) {
            throw new ApiProblem('canonical_product_duplicate', 'A matching canonical product already exists in this category.', 409);
        }
        $product = CanonicalProduct::query()->create(['category_id' => $categoryId, 'english_name' => trim((string) $new['english_name']), 'normalized_key' => $key, 'attributes' => is_array($new['attributes'] ?? null) ? $new['attributes'] : [], 'is_active' => true]);
        foreach (($new['aliases'] ?? []) as $alias) {
            if (! is_array($alias) || ! is_string($alias['locale'] ?? null) || ! is_string($alias['alias'] ?? null) || trim($alias['alias']) === '') {
                continue;
            }
            ProductAlias::query()->firstOrCreate(['canonical_product_id' => $product->id, 'product_variant_id' => null, 'locale' => $alias['locale'], 'normalized_alias' => CatalogText::normalize($alias['alias'])], ['alias' => trim($alias['alias'])]);
        }
        $variant = null;
        if (is_array($new['variant'] ?? null) && is_string($new['variant']['code'] ?? null) && trim($new['variant']['code']) !== '') {
            $variant = ProductVariant::query()->create(['canonical_product_id' => $product->id, 'code' => trim($new['variant']['code']), 'normalized_key' => $new['variant']['normalized_key'] ?? $new['variant']['code'], 'attributes' => is_array($new['variant']['attributes'] ?? null) ? $new['variant']['attributes'] : [], 'is_active' => true]);
        }

        return [$product, $variant];
    }

    private function variantFor(CanonicalProduct $product, mixed $variantId): ?ProductVariant
    {
        if ($variantId === null) {
            return null;
        }
        $variant = ProductVariant::query()->where('canonical_product_id', $product->id)->where('is_active', true)->find($variantId);
        if ($variant === null) {
            throw new ApiProblem('product_variant_unavailable', 'The selected product variant is unavailable for that product.', 422);
        }

        return $variant;
    }

    /** @param array<string, mixed> $input
     * @param  array<string, mixed>|null  $previous
     */
    private function replaceMapping(string $vendorId, MatchCandidate $candidate, CanonicalProduct $product, ?ProductVariant $variant, User $actor, array $input, ?array $previous): VendorItemMapping
    {
        $current = VendorItemMapping::query()->where('vendor_item_id', $vendorId)->where('is_current', true)->lockForUpdate()->first();
        if ($current !== null) {
            $current->update(['is_current' => false, 'superseded_at' => now()]);
        }
        $matchClass = MatchClass::tryFrom((string) ($input['match_class'] ?? $candidate->proposed_match_class->value)) ?? $candidate->proposed_match_class;
        $confidence = MatchConfidence::tryFrom((string) ($input['confidence'] ?? $candidate->confidence->value)) ?? $candidate->confidence;

        return VendorItemMapping::query()->create([
            'vendor_item_id' => $vendorId, 'canonical_product_id' => $product->id, 'product_variant_id' => $variant?->id,
            'match_candidate_id' => $candidate->id, 'reviewed_by' => $actor->id, 'supersedes_id' => $current?->id,
            'match_class' => $matchClass, 'confidence' => $confidence, 'origin' => MappingOrigin::Manual, 'matcher_version' => $candidate->matcher_version,
            'evidence' => ['candidate_id' => $candidate->id, 'candidate_evidence_fingerprint' => $candidate->evidence_fingerprint, 'manual_action' => true],
            'is_current' => true, 'verified_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $previous
     * @param  array<string, mixed>  $result
     */
    private function reviewRecord(string $vendorId, ?MatchCandidate $candidate, ?VendorItemMapping $mapping, ?CanonicalProduct $product, ?ProductVariant $variant, User $actor, ReviewAction $action, mixed $reason, ?array $previous, array $result): void
    {
        MatchReview::query()->create([
            'vendor_item_id' => $vendorId, 'match_candidate_id' => $candidate?->id, 'vendor_item_mapping_id' => $mapping?->id,
            'canonical_product_id' => $product?->id, 'product_variant_id' => $variant?->id, 'actor_id' => $actor->id,
            'action' => $action, 'reason' => is_string($reason) ? $reason : null, 'previous_state' => $previous ?? [], 'resulting_state' => $result,
            'evidence_context' => $candidate === null ? [] : ['candidate_id' => $candidate->id, 'evidence_fingerprint' => $candidate->evidence_fingerprint, 'matcher_version' => $candidate->matcher_version], 'reviewed_at' => now(),
        ]);
    }

    /** @return array<string, mixed>|null */
    private function mappingState(?VendorItemMapping $mapping): ?array
    {
        if ($mapping === null) {
            return null;
        }

        return ['mapping_id' => $mapping->id, 'canonical_product_id' => $mapping->canonical_product_id, 'product_variant_id' => $mapping->product_variant_id, 'match_class' => $mapping->match_class->value, 'confidence' => $mapping->confidence->value, 'origin' => $mapping->origin->value, 'is_current' => $mapping->is_current];
    }

    /** @param list<string> $canonicalProductIds */
    private function invalidateVendor(VendorItem $vendor, array $canonicalProductIds): void
    {
        $account = $vendor->supplierAccount()->firstOrFail();
        $this->cache->invalidate(
            $vendor->organization_id,
            $account->restaurant_location_id,
            $canonicalProductIds,
        );
    }
}
