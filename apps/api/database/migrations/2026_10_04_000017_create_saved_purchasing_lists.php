<?php

use App\Enums\SavedListStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Composite keys make list location and selected-offer ancestry a
        // database invariant in addition to the tenant-scoped service checks.
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->unique(['id', 'canonical_product_id'], 'product_variants_id_product_unique');
        });
        Schema::table('supplier_offers', function (Blueprint $table): void {
            $table->unique(['id', 'organization_id', 'restaurant_location_id'], 'offers_id_tenant_location_unique');
        });

        Schema::create('saved_lists', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('restaurant_location_id');
            $table->string('name', 120);
            $table->enum('status', array_column(SavedListStatus::cases(), 'value'))
                ->default(SavedListStatus::Active->value);
            $table->unsignedBigInteger('version')->default(1);
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign(['restaurant_location_id', 'organization_id'], 'saved_lists_location_scope_fk')
                ->references(['id', 'organization_id'])->on('restaurant_locations')->restrictOnDelete();
            $table->unique(['id', 'organization_id', 'restaurant_location_id'], 'saved_lists_scope_unique');
            $table->index(['organization_id', 'restaurant_location_id', 'status', 'updated_at'], 'saved_lists_scope_status_updated_index');
        });

        Schema::create('saved_list_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('saved_list_id');
            $table->uuid('organization_id');
            $table->uuid('restaurant_location_id');
            $table->uuid('canonical_product_id');
            $table->uuid('product_variant_id')->nullable();
            $table->uuid('selected_offer_id')->nullable();
            $table->decimal('quantity_target', 18, 6)->default('1');
            $table->string('preferred_unit', 8)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('sort_order');
            $table->unsignedBigInteger('version')->default(1);
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->foreign(['saved_list_id', 'organization_id', 'restaurant_location_id'], 'saved_list_items_list_scope_fk')
                ->references(['id', 'organization_id', 'restaurant_location_id'])->on('saved_lists')->cascadeOnDelete();
            $table->foreign('canonical_product_id')->references('id')->on('canonical_products')->restrictOnDelete();
            $table->foreign(['product_variant_id', 'canonical_product_id'], 'saved_list_items_variant_product_fk')
                ->references(['id', 'canonical_product_id'])->on('product_variants')->restrictOnDelete();
            $table->foreign(['selected_offer_id', 'organization_id', 'restaurant_location_id'], 'saved_list_items_offer_scope_fk')
                ->references(['id', 'organization_id', 'restaurant_location_id'])->on('supplier_offers')->restrictOnDelete();
            $table->index(['saved_list_id', 'sort_order', 'id'], 'saved_list_items_order_index');
        });

        DB::statement('ALTER TABLE saved_list_items ADD CONSTRAINT saved_list_items_quantity_target_check CHECK (quantity_target > 0)');
        DB::statement('CREATE UNIQUE INDEX saved_list_items_canonical_without_variant_unique ON saved_list_items (saved_list_id, canonical_product_id) WHERE product_variant_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX saved_list_items_canonical_with_variant_unique ON saved_list_items (saved_list_id, canonical_product_id, product_variant_id) WHERE product_variant_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_list_items');
        Schema::dropIfExists('saved_lists');
        Schema::table('supplier_offers', function (Blueprint $table): void {
            $table->dropUnique('offers_id_tenant_location_unique');
        });
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropUnique('product_variants_id_product_unique');
        });
    }
};
