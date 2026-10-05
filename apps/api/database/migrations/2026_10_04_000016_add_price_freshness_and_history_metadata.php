<?php

use App\Enums\PriceFreshness;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_accounts', function (Blueprint $table): void {
            $table->timestampTz('next_expected_sync_at')->nullable()->after('last_successful_sync_at');
            $table->timestampTz('last_sync_failed_at')->nullable()->after('next_expected_sync_at');
            // A safe stable code only. Supplier responses and credentials never persist here.
            $table->string('last_sync_failure_code', 64)->nullable()->after('last_sync_failed_at');
        });

        Schema::table('supplier_offers', function (Blueprint $table): void {
            $table->enum('freshness_state', array_column(PriceFreshness::cases(), 'value'))
                ->default(PriceFreshness::Stale->value)->after('is_active');
            $table->timestampTz('freshness_calculated_at')->nullable()->after('freshness_state');
        });

        Schema::table('price_observations', function (Blueprint $table): void {
            // Source and system times stay distinct. Existing source rows remain
            // valid without fabricated source-update timestamps.
            $table->timestampTz('source_updated_at')->nullable()->after('availability');
            $table->timestampTz('imported_at')->nullable()->after('observed_at');
            $table->jsonb('match_evidence')->nullable()->after('formula_version');
        });

        // `created_at` is the only trustworthy legacy processing timestamp; it
        // is deliberately not used as source observation/update evidence.
        DB::table('price_observations')->whereNull('imported_at')->update([
            'imported_at' => DB::raw('created_at'),
        ]);
    }

    public function down(): void
    {
        Schema::table('price_observations', function (Blueprint $table): void {
            $table->dropColumn(['source_updated_at', 'imported_at', 'match_evidence']);
        });
        Schema::table('supplier_offers', function (Blueprint $table): void {
            $table->dropColumn(['freshness_state', 'freshness_calculated_at']);
        });
        Schema::table('supplier_accounts', function (Blueprint $table): void {
            $table->dropColumn(['next_expected_sync_at', 'last_sync_failed_at', 'last_sync_failure_code']);
        });
    }
};
