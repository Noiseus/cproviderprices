<?php

namespace Tests\Feature;

use App\Enums\MappingOrigin;
use App\Enums\MatchCandidateStatus;
use App\Enums\MatchClass;
use App\Enums\MatchConfidence;
use App\Enums\Role;
use App\Models\CanonicalProduct;
use App\Models\Category;
use App\Models\MatchCandidate;
use App\Models\Organization;
use App\Models\ProductIdentifier;
use App\Models\User;
use App\Models\VendorItem;
use App\Models\VendorItemMapping;
use App\Services\CatalogMatcher;
use App\Support\CatalogText;
use Database\Seeders\CatalogTaxonomySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenantContext;
use Tests\TestCase;

final class CatalogMatchingTest extends TestCase
{
    use BuildsTenantContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogTaxonomySeeder::class);
    }

    public function test_tomato_taxonomy_keeps_english_source_and_spanish_aliases_separate(): void
    {
        $tomatoes = Category::query()->where('code', 'fresh-tomatoes')->sole();
        $beefsteak = CanonicalProduct::query()->where('category_id', $tomatoes->id)->where('normalized_key', 'beefsteak tomato')->sole();

        $this->assertSame('Beefsteak Tomato', $beefsteak->english_name);
        $this->assertDatabaseHas('product_aliases', ['canonical_product_id' => $beefsteak->id, 'locale' => 'es', 'normalized_alias' => 'tomate beefsteak']);
        $this->assertDatabaseHas('product_aliases', ['canonical_product_id' => $beefsteak->id, 'locale' => 'en', 'normalized_alias' => 'beef tomato']);
    }

    public function test_beef_tomato_examples_create_deterministic_reviewable_candidates_and_preserve_size_code(): void
    {
        $matcher = app(CatalogMatcher::class);
        $beefsteak = CanonicalProduct::query()->where('normalized_key', 'beefsteak tomato')->sole();
        foreach (['TOM BEEF 5X6 1/25 LB CS', 'TOM BEEFSTEAK 25#'] as $description) {
            $vendor = $this->vendor($description, ['size_code' => $description === 'TOM BEEF 5X6 1/25 LB CS' ? '5X6' : null]);
            $first = $matcher->generate($vendor);
            $second = $matcher->generate($vendor->fresh());
            $candidate = $first->firstWhere('canonical_product_id', $beefsteak->id);

            $this->assertNotNull($candidate);
            $this->assertSame(MatchClass::EquivalentProduct, $candidate->proposed_match_class);
            $this->assertSame(MatchConfidence::B, $candidate->confidence);
            $this->assertSame('alias_text', $candidate->evidence['stage']);
            $this->assertSame($first->pluck('id')->all(), $second->pluck('id')->all());
            $this->assertDatabaseMissing('vendor_item_mappings', ['vendor_item_id' => $vendor->id]);
        }
        $firstVendor = VendorItem::query()->where('raw_description', 'TOM BEEF 5X6 1/25 LB CS')->sole();
        $this->assertSame('5X6', $firstVendor->normalized_attributes['size_code']);
        $this->assertNotSame('30', $firstVendor->normalized_attributes['size_code']);
    }

    public function test_identifier_stage_takes_precedence_and_only_rule_qualified_a_exact_match_auto_publishes(): void
    {
        $product = CanonicalProduct::query()->where('normalized_key', 'beefsteak tomato')->sole();
        ProductIdentifier::query()->create(['canonical_product_id' => $product->id, 'identifier_type' => 'gtin', 'identifier_value' => '00012345678905']);
        $vendor = $this->vendor('Supplier language that does not otherwise match', ['identifiers' => [['type' => 'gtin', 'value' => '00012345678905']]]);

        $candidate = app(CatalogMatcher::class)->generate($vendor)->sole();
        $mapping = VendorItemMapping::query()->where('vendor_item_id', $vendor->id)->sole();

        $this->assertSame('identifier', $candidate->evidence['stage']);
        $this->assertSame(MatchConfidence::A, $candidate->confidence);
        $this->assertSame(MatchCandidateStatus::AutoPublished, $candidate->fresh()->status);
        $this->assertSame(MatchClass::ExactMatch, $mapping->match_class);
        $this->assertSame(MappingOrigin::Matcher, $mapping->origin);
    }

    public function test_existing_verified_mapping_suppresses_rematching_and_supplier_sku_is_not_global_identifier(): void
    {
        $vendor = $this->vendor('TOM BEEFSTEAK 25#');
        $product = CanonicalProduct::query()->where('normalized_key', 'roma tomato')->sole();
        VendorItemMapping::query()->create(['vendor_item_id' => $vendor->id, 'canonical_product_id' => $product->id, 'match_candidate_id' => null, 'reviewed_by' => null, 'supersedes_id' => null, 'match_class' => MatchClass::EquivalentProduct, 'confidence' => MatchConfidence::B, 'origin' => MappingOrigin::Manual, 'matcher_version' => CatalogMatcher::VERSION, 'evidence' => ['manual' => true], 'is_current' => true, 'verified_at' => now()]);

        $this->assertCount(0, app(CatalogMatcher::class)->generate($vendor));
        $this->assertDatabaseCount('product_identifiers', 0);
        $this->assertSame('sku:CAT-001', VendorItem::factory()->create()->identity_key);
    }

    public function test_hard_attribute_contradiction_can_never_become_an_exact_match(): void
    {
        $vendor = $this->vendor('TOM BEEFSTEAK 25#', ['form' => 'canned']);
        $candidate = app(CatalogMatcher::class)->generate($vendor)->firstOrFail();

        $this->assertNotSame(MatchClass::ExactMatch, $candidate->proposed_match_class);
        $this->assertNotEmpty($candidate->evidence['hard_contradictions']);
        $this->assertNotSame(MatchConfidence::A, $candidate->confidence);
        $this->assertDatabaseMissing('vendor_item_mappings', ['vendor_item_id' => $vendor->id]);
    }

    public function test_parser_confidence_is_not_match_confidence_and_low_confidence_never_publishes_as_exact_before_review(): void
    {
        $vendor = $this->vendor('TOM BEEFSTEAK 25#', ['parser_confidence' => 'low']);
        $candidate = app(CatalogMatcher::class)->generate($vendor)->firstOrFail();

        $this->assertSame('low', $candidate->evidence['vendor']['parser_confidence']);
        $this->assertSame(MatchConfidence::B, $candidate->confidence);
        $this->assertSame(MatchCandidateStatus::Pending, $candidate->status);
        $this->assertDatabaseMissing('vendor_item_mappings', ['vendor_item_id' => $vendor->id, 'match_class' => MatchClass::ExactMatch->value]);
    }

    public function test_rejected_same_evidence_is_remembered_but_changed_evidence_can_be_reconsidered(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $vendor = $this->vendor('TOM BEEFSTEAK 25#');
        $candidate = app(CatalogMatcher::class)->generate($vendor)->firstOrFail();
        $this->asUser($admin)->postJson("/api/v1/platform/matching/candidates/{$candidate->id}/review", ['action' => 'reject', 'reason' => 'Supplier wording is not sufficient.'])->assertOk();
        $this->assertSame(MatchCandidateStatus::Rejected, $candidate->fresh()->status);
        $again = app(CatalogMatcher::class)->generate($vendor->fresh())->firstOrFail();
        $this->assertSame($candidate->id, $again->id);
        $vendor->update(['raw_description' => 'TOM BEEFSTEAK FRESH 25#', 'normalized_description' => 'tomato beefsteak fresh']);
        $replacement = app(CatalogMatcher::class)->generate($vendor->fresh())->firstOrFail();
        $this->assertNotSame($candidate->id, $replacement->id);
        $this->assertSame(MatchCandidateStatus::Pending, $replacement->status);
    }

    public function test_platform_review_accept_choose_create_and_reverse_are_audited_and_preserve_mapping_history(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $vendor = $this->vendor('TOM BEEFSTEAK 25#');
        $candidate = app(CatalogMatcher::class)->generate($vendor)->firstOrFail();
        $this->asUser($admin)->postJson("/api/v1/platform/matching/candidates/{$candidate->id}/review", ['action' => 'accept'])->assertOk();
        $first = VendorItemMapping::query()->where('vendor_item_id', $vendor->id)->sole();
        $this->assertSame(MappingOrigin::Manual, $first->origin);

        $roma = CanonicalProduct::query()->where('normalized_key', 'roma tomato')->sole();
        $secondCandidate = MatchCandidate::query()->create(['vendor_item_id' => $vendor->id, 'canonical_product_id' => $roma->id, 'product_variant_id' => null, 'candidate_key' => 'product:'.$roma->id, 'proposed_match_class' => MatchClass::ComparableAlternative, 'confidence' => MatchConfidence::C, 'score' => 55, 'rank' => 1, 'evidence' => ['stage' => 'test'], 'evidence_fingerprint' => hash('sha256', 'choose-existing'), 'matcher_version' => CatalogMatcher::VERSION, 'status' => MatchCandidateStatus::Pending, 'generated_at' => now()]);
        $this->postJson("/api/v1/platform/matching/candidates/{$secondCandidate->id}/review", ['action' => 'choose_existing', 'canonical_product_id' => $roma->id, 'match_class' => 'comparable_alternative', 'confidence' => 'C'])->assertOk();
        $second = VendorItemMapping::query()->where('vendor_item_id', $vendor->id)->where('is_current', true)->sole();
        $this->assertFalse($first->fresh()->is_current);
        $this->assertSame($first->id, $second->supersedes_id);

        $this->postJson("/api/v1/platform/matching/mappings/{$second->id}/reverse", ['reason' => 'Reviewed against new supplier evidence.'])->assertOk()->assertJsonPath('mapping.is_current', false);
        $this->assertDatabaseCount('vendor_item_mappings', 2);
        $this->assertDatabaseHas('audit_logs', ['action' => 'catalog.match_review.accept', 'subject_id' => $candidate->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'catalog.match_review.choose_existing', 'subject_id' => $secondCandidate->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'catalog.mapping.reversed', 'subject_id' => $second->id]);
    }

    public function test_create_canonical_review_is_atomic_and_duplicate_protected(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $vendor = $this->vendor('UNUSUAL VINE TOMATO');
        $candidate = app(CatalogMatcher::class)->generate($vendor)->firstOrFail();
        $category = Category::query()->where('code', 'fresh-tomatoes')->sole();
        $payload = ['action' => 'create_canonical', 'new_canonical' => ['english_name' => 'Vine Tomato', 'category_id' => $category->id, 'aliases' => [['locale' => 'es', 'alias' => 'tomate en rama']]]];
        $this->asUser($admin)->postJson("/api/v1/platform/matching/candidates/{$candidate->id}/review", $payload)->assertOk();
        $this->assertDatabaseHas('canonical_products', ['category_id' => $category->id, 'normalized_key' => 'vine tomato']);
        $this->assertDatabaseHas('product_aliases', ['locale' => 'es', 'normalized_alias' => 'tomate en rama']);

        $next = MatchCandidate::query()->create(['vendor_item_id' => $vendor->id, 'canonical_product_id' => CanonicalProduct::query()->where('normalized_key', 'tomato')->sole()->id, 'product_variant_id' => null, 'candidate_key' => 'product:duplicate', 'proposed_match_class' => MatchClass::RelatedProduct, 'confidence' => MatchConfidence::D, 'score' => 25, 'rank' => 2, 'evidence' => ['stage' => 'test'], 'evidence_fingerprint' => hash('sha256', 'create-duplicate'), 'matcher_version' => CatalogMatcher::VERSION, 'status' => MatchCandidateStatus::Pending, 'generated_at' => now()]);
        $this->postJson("/api/v1/platform/matching/candidates/{$next->id}/review", $payload)->assertConflict()->assertJsonPath('error.code', 'canonical_product_duplicate');
        $this->assertSame(MatchCandidateStatus::Pending, $next->fresh()->status);
    }

    public function test_platform_queue_is_paginated_filtered_and_denied_to_all_tenant_roles(): void
    {
        $organization = Organization::factory()->create();
        foreach ([Role::OrganizationOwner, Role::Buyer, Role::Viewer] as $role) {
            $user = User::factory()->create();
            $this->membership($user, $organization, $role);
            $this->asUser($user)->getJson('/api/v1/platform/matching/candidates')->assertForbidden()->assertJsonPath('error.code', 'platform_scope_required');
        }
        $admin = User::factory()->platformAdmin()->create();
        $candidate = app(CatalogMatcher::class)->generate($this->vendor('TOM BEEFSTEAK 25#'))->firstOrFail();
        $this->asUser($admin)->getJson("/api/v1/platform/matching/candidates?confidence=B&supplier_account_id={$candidate->vendorItem->supplier_account_id}&status=pending&page=1&per_page=10")
            ->assertOk()->assertJsonFragment(['id' => $candidate->id])->assertJsonPath('pagination.per_page', 10);
        $this->getJson("/api/v1/organizations/{$candidate->vendorItem->organization_id}/vendor-items/{$candidate->vendor_item_id}")->assertNotFound();
    }

    /** @param array<string, mixed> $attributes */
    private function vendor(string $description, array $attributes = []): VendorItem
    {
        return VendorItem::factory()->create([
            'raw_description' => $description,
            'normalized_description' => CatalogText::normalize($description),
            'normalized_attributes' => [...['size_code' => null, 'variable_weight' => false, 'measurements' => [], 'packs' => [], 'case_quantity' => null, 'dimensions' => [], 'identifiers' => [], 'parser_confidence' => 'high', 'parser_warnings' => []], ...$attributes],
        ]);
    }
}
