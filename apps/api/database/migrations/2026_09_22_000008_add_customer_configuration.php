<?php

use App\Enums\SupplierAccountStatus;
use App\Enums\SupplierRefreshCadence;
use App\Enums\SupplierSourceMethod;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_locations', function (Blueprint $table): void {
            $table->string('external_reference')->nullable()->after('country_code');
            $table->unique(['id', 'organization_id'], 'locations_id_organization_unique');
        });

        Schema::create('supplier_definitions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code')->unique('supplier_definitions_code_unique');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('supplier_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('restaurant_location_id');
            $table->foreignUuid('supplier_definition_id')->constrained()->restrictOnDelete();
            $table->string('display_name');
            $table->string('masked_account_reference', 64)->nullable();
            $table->enum('source_method', array_column(SupplierSourceMethod::cases(), 'value'));
            $table->enum('expected_refresh_cadence', array_column(SupplierRefreshCadence::cases(), 'value'));
            $table->enum('status', array_column(SupplierAccountStatus::cases(), 'value'))
                ->default(SupplierAccountStatus::Active->value);
            $table->timestampTz('last_successful_sync_at')->nullable();
            $table->timestampsTz();
            $table->unique(
                ['organization_id', 'restaurant_location_id', 'supplier_definition_id'],
                'supplier_accounts_org_location_supplier_unique',
            );
            $table->index(
                ['organization_id', 'restaurant_location_id', 'status'],
                'supplier_accounts_org_location_status_index',
            );
            $table->foreign(['restaurant_location_id', 'organization_id'], 'supplier_accounts_location_organization_foreign')
                ->references(['id', 'organization_id'])->on('restaurant_locations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_accounts');
        Schema::dropIfExists('supplier_definitions');
        Schema::table('restaurant_locations', function (Blueprint $table): void {
            $table->dropUnique('locations_id_organization_unique');
            $table->dropColumn('external_reference');
        });
    }
};
