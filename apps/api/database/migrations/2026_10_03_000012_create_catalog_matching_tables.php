<?php

use App\Enums\MappingOrigin;
use App\Enums\MatchCandidateStatus;
use App\Enums\MatchClass;
use App\Enums\MatchConfidence;
use App\Enums\ReviewAction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('parent_id')->nullable();
            $table->string('code', 80)->unique('categories_code_unique');
            $table->string('slug', 120)->unique('categories_slug_unique');
            $table->string('english_name');
            $table->timestampsTz();
            $table->index(['parent_id', 'english_name'], 'categories_parent_name_index');
        });
        // Laravel's PostgreSQL grammar emits inline self-references before the
        // primary-key command. Add this constraint only after the table exists.
        Schema::table('categories', function (Blueprint $table): void {
            $table->foreign('parent_id')->references('id')->on('categories')->restrictOnDelete();
        });

        Schema::create('category_translations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('category_id')->constrained('categories')->restrictOnDelete();
            $table->string('locale', 10);
            $table->string('name');
            $table->string('normalized_name', 255);
            $table->timestampsTz();
            $table->unique(['category_id', 'locale'], 'category_translations_locale_unique');
            $table->index(['locale', 'normalized_name'], 'category_translations_lookup_index');
        });

        Schema::create('canonical_products', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('category_id')->constrained('categories')->restrictOnDelete();
            $table->string('english_name');
            $table->string('normalized_key', 255);
            $table->jsonb('attributes')->default(DB::raw("'{}'::jsonb"));
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['category_id', 'normalized_key'], 'canonical_products_category_key_unique');
            $table->index(['category_id', 'is_active', 'english_name'], 'canonical_products_category_active_index');
        });

        Schema::create('product_variants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('canonical_product_id')->constrained('canonical_products')->restrictOnDelete();
            $table->string('code', 100)->nullable();
            $table->string('normalized_key', 255);
            $table->jsonb('attributes')->default(DB::raw("'{}'::jsonb"));
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['canonical_product_id', 'normalized_key'], 'product_variants_product_key_unique');
            $table->unique(['canonical_product_id', 'code'], 'product_variants_product_code_unique');
        });

        Schema::create('product_aliases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('canonical_product_id')->constrained('canonical_products')->restrictOnDelete();
            $table->foreignUuid('product_variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            $table->string('locale', 10);
            $table->string('alias');
            $table->string('normalized_alias', 255);
            $table->timestampsTz();
            $table->index(['locale', 'normalized_alias'], 'product_aliases_lookup_index');
            $table->index(['canonical_product_id', 'product_variant_id'], 'product_aliases_target_index');
        });
        DB::statement('CREATE UNIQUE INDEX product_aliases_product_unique ON product_aliases (canonical_product_id, locale, normalized_alias) WHERE product_variant_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX product_aliases_variant_unique ON product_aliases (product_variant_id, locale, normalized_alias) WHERE product_variant_id IS NOT NULL');

        Schema::create('product_identifiers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('canonical_product_id')->constrained('canonical_products')->restrictOnDelete();
            $table->foreignUuid('product_variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            $table->enum('identifier_type', ['gtin', 'upc', 'plu']);
            $table->string('identifier_value', 64);
            $table->string('normalized_value', 64);
            $table->timestampsTz();
            $table->unique(['identifier_type', 'normalized_value'], 'product_identifiers_global_unique');
        });

        Schema::create('vendor_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('supplier_account_id');
            $table->uuid('first_normalized_import_record_id');
            $table->uuid('last_normalized_import_record_id');
            $table->string('supplier_item_identifier', 255)->nullable();
            $table->string('identity_key', 128);
            $table->string('raw_description', 4096);
            $table->string('normalized_description', 4096);
            $table->string('brand', 255)->nullable();
            $table->jsonb('normalized_attributes')->default(DB::raw("'{}'::jsonb"));
            $table->string('parser_version', 64);
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');
            $table->timestampsTz();
            $table->foreign(['supplier_account_id', 'organization_id'], 'vendor_items_account_scope_fk')->references(['id', 'organization_id'])->on('supplier_accounts')->restrictOnDelete();
            $table->foreign('first_normalized_import_record_id')->references('id')->on('normalized_import_records')->restrictOnDelete();
            $table->foreign('last_normalized_import_record_id')->references('id')->on('normalized_import_records')->restrictOnDelete();
            $table->unique(['organization_id', 'supplier_account_id', 'identity_key'], 'vendor_items_identity_unique');
            $table->index(['organization_id', 'supplier_account_id', 'last_seen_at'], 'vendor_items_account_seen_index');
        });

        Schema::create('vendor_item_mappings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('vendor_item_id')->constrained('vendor_items')->restrictOnDelete();
            $table->foreignUuid('canonical_product_id')->constrained('canonical_products')->restrictOnDelete();
            $table->foreignUuid('product_variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            // The candidate table follows this table because candidates themselves
            // reference vendor items. The foreign key is added after both exist.
            $table->uuid('match_candidate_id')->nullable();
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->uuid('supersedes_id')->nullable();
            $table->enum('match_class', array_column(MatchClass::cases(), 'value'));
            $table->enum('confidence', array_column(MatchConfidence::cases(), 'value'));
            $table->enum('origin', array_column(MappingOrigin::cases(), 'value'));
            $table->string('matcher_version', 64);
            $table->jsonb('evidence');
            $table->boolean('is_current')->default(true);
            $table->timestampTz('verified_at');
            $table->timestampTz('superseded_at')->nullable();
            $table->timestampsTz();
            $table->index(['vendor_item_id', 'is_current'], 'vendor_item_mappings_current_index');
        });
        Schema::table('vendor_item_mappings', function (Blueprint $table): void {
            $table->foreign('supersedes_id')->references('id')->on('vendor_item_mappings')->restrictOnDelete();
        });
        DB::statement('CREATE UNIQUE INDEX vendor_item_mappings_one_current ON vendor_item_mappings (vendor_item_id) WHERE is_current');

        Schema::create('match_candidates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('vendor_item_id')->constrained('vendor_items')->restrictOnDelete();
            $table->foreignUuid('canonical_product_id')->constrained('canonical_products')->restrictOnDelete();
            $table->foreignUuid('product_variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            $table->string('candidate_key', 80);
            $table->enum('proposed_match_class', array_column(MatchClass::cases(), 'value'));
            $table->enum('confidence', array_column(MatchConfidence::cases(), 'value'));
            $table->unsignedSmallInteger('score');
            $table->unsignedSmallInteger('rank');
            $table->jsonb('evidence');
            $table->string('evidence_fingerprint', 64);
            $table->string('matcher_version', 64);
            $table->enum('status', array_column(MatchCandidateStatus::cases(), 'value'))->default(MatchCandidateStatus::Pending->value);
            $table->timestampTz('generated_at');
            $table->timestampsTz();
            $table->unique(['vendor_item_id', 'candidate_key', 'matcher_version', 'evidence_fingerprint'], 'match_candidates_replay_unique');
            $table->index(['status', 'confidence', 'generated_at', 'id'], 'match_candidates_review_queue_index');
            $table->index(['vendor_item_id', 'matcher_version', 'evidence_fingerprint'], 'match_candidates_rejected_memory_index');
        });
        DB::statement('ALTER TABLE vendor_item_mappings ADD CONSTRAINT vendor_item_mappings_candidate_fk FOREIGN KEY (match_candidate_id) REFERENCES match_candidates (id) ON DELETE RESTRICT');

        Schema::create('match_reviews', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('vendor_item_id')->constrained('vendor_items')->restrictOnDelete();
            $table->foreignUuid('match_candidate_id')->nullable()->constrained('match_candidates')->restrictOnDelete();
            $table->foreignUuid('vendor_item_mapping_id')->nullable()->constrained('vendor_item_mappings')->restrictOnDelete();
            $table->foreignUuid('canonical_product_id')->nullable()->constrained('canonical_products')->restrictOnDelete();
            $table->foreignUuid('product_variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            $table->foreignUuid('actor_id')->constrained('users')->restrictOnDelete();
            $table->enum('action', array_column(ReviewAction::cases(), 'value'));
            $table->text('reason')->nullable();
            $table->jsonb('previous_state');
            $table->jsonb('resulting_state');
            $table->jsonb('evidence_context');
            $table->timestampTz('reviewed_at');
            $table->timestampsTz();
            $table->index(['vendor_item_id', 'reviewed_at'], 'match_reviews_vendor_history_index');
            $table->index(['match_candidate_id', 'action'], 'match_reviews_candidate_action_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_reviews');
        Schema::dropIfExists('vendor_item_mappings');
        Schema::dropIfExists('match_candidates');
        Schema::dropIfExists('vendor_items');
        Schema::dropIfExists('product_identifiers');
        DB::statement('DROP INDEX IF EXISTS product_aliases_variant_unique');
        DB::statement('DROP INDEX IF EXISTS product_aliases_product_unique');
        Schema::dropIfExists('product_aliases');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('canonical_products');
        Schema::dropIfExists('category_translations');
        Schema::dropIfExists('categories');
    }
};
