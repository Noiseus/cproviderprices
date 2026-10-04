<?php

use App\Enums\OfferAvailability;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // These composite uniqueness constraints allow the foreign keys below
        // to enforce the same organization/account ancestry at the database
        // boundary, not only in application queries.
        Schema::table('vendor_items', function (Blueprint $table): void {
            $table->unique(['id', 'organization_id', 'supplier_account_id'], 'vendor_items_scope_unique');
        });
        Schema::table('normalized_import_records', function (Blueprint $table): void {
            $table->unique(['id', 'import_run_id', 'organization_id'], 'normalized_import_records_scope_unique');
        });

        Schema::create('supplier_offers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('restaurant_location_id');
            $table->uuid('supplier_account_id');
            $table->uuid('vendor_item_id');
            $table->uuid('current_price_observation_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->foreign(['supplier_account_id', 'organization_id', 'restaurant_location_id'], 'offers_account_scope_fk')
                ->references(['id', 'organization_id', 'restaurant_location_id'])->on('supplier_accounts')->restrictOnDelete();
            $table->foreign(['vendor_item_id', 'organization_id', 'supplier_account_id'], 'offers_vendor_scope_fk')
                ->references(['id', 'organization_id', 'supplier_account_id'])->on('vendor_items')->restrictOnDelete();
            $table->unique(['organization_id', 'restaurant_location_id', 'supplier_account_id', 'vendor_item_id'], 'supplier_offers_identity_unique');
            $table->unique(['id', 'organization_id'], 'supplier_offers_tenant_unique');
        });

        Schema::create('price_observations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('supplier_offer_id');
            $table->uuid('import_run_id');
            $table->uuid('normalized_import_record_id');
            $table->decimal('case_price', 18, 6);
            $table->string('currency', 3);
            $table->jsonb('source_price_evidence');
            $table->jsonb('source_pack_evidence');
            $table->decimal('normalized_quantity', 24, 12)->nullable();
            $table->string('normalized_unit', 8)->nullable();
            $table->string('dimension', 16)->nullable();
            $table->enum('availability', array_column(OfferAvailability::cases(), 'value'))
                ->default(OfferAvailability::Unknown->value);
            $table->timestampTz('observed_at');
            $table->string('formula_version', 64);
            $table->timestampsTz();

            $table->foreign(['supplier_offer_id', 'organization_id'], 'observations_offer_scope_fk')
                ->references(['id', 'organization_id'])->on('supplier_offers')->restrictOnDelete();
            $table->foreign(['normalized_import_record_id', 'import_run_id', 'organization_id'], 'observations_normalized_scope_fk')
                ->references(['id', 'import_run_id', 'organization_id'])->on('normalized_import_records')->restrictOnDelete();
            $table->unique('normalized_import_record_id', 'price_observations_normalized_record_unique');
            // The current pointer must be an observation for this exact offer
            // in this exact tenant. A simple FK to observation ID would leave
            // an accidental cross-offer/cross-tenant assignment possible.
            $table->unique(['id', 'supplier_offer_id', 'organization_id'], 'observations_offer_scope_unique');
            $table->index(['supplier_offer_id', 'observed_at'], 'price_observations_offer_observed_index');
        });

        Schema::table('supplier_offers', function (Blueprint $table): void {
            $table->foreign(['current_price_observation_id', 'id', 'organization_id'], 'offers_current_observation_fk')
                ->references(['id', 'supplier_offer_id', 'organization_id'])->on('price_observations')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE price_observations ADD CONSTRAINT price_observations_case_price_check CHECK (case_price >= 0)');
        DB::statement('ALTER TABLE price_observations ADD CONSTRAINT price_observations_quantity_check CHECK (normalized_quantity IS NULL OR normalized_quantity > 0)');
        DB::statement("ALTER TABLE price_observations ADD CONSTRAINT price_observations_currency_check CHECK (currency ~ '^[A-Z]{3}$')");
        DB::statement("ALTER TABLE price_observations ADD CONSTRAINT price_observations_normalization_shape_check CHECK ((normalized_quantity IS NULL AND normalized_unit IS NULL AND dimension IS NULL) OR (normalized_quantity IS NOT NULL AND normalized_unit IN ('g', 'mL', 'ea') AND dimension IN ('weight', 'volume', 'count'))) ");
    }

    public function down(): void
    {
        Schema::table('supplier_offers', function (Blueprint $table): void {
            $table->dropForeign('offers_current_observation_fk');
        });
        Schema::dropIfExists('price_observations');
        Schema::dropIfExists('supplier_offers');
        Schema::table('normalized_import_records', function (Blueprint $table): void {
            $table->dropUnique('normalized_import_records_scope_unique');
        });
        Schema::table('vendor_items', function (Blueprint $table): void {
            $table->dropUnique('vendor_items_scope_unique');
        });
    }
};
