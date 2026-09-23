<?php

use App\Enums\ImportStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_accounts', function (Blueprint $table): void {
            $table->unique(['id', 'organization_id', 'restaurant_location_id'], 'supplier_accounts_import_scope_unique');
            $table->unique(['id', 'organization_id'], 'supplier_accounts_tenant_unique');
        });
        Schema::create('import_mapping_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('supplier_account_id');
            $table->string('name');
            $table->jsonb('headers');
            $table->jsonb('mapping');
            $table->jsonb('source_conventions');
            $table->timestampsTz();
            $table->foreign(['supplier_account_id', 'organization_id'], 'mapping_account_scope_fk')->references(['id', 'organization_id'])->on('supplier_accounts')->restrictOnDelete();
            $table->unique(['id', 'organization_id', 'supplier_account_id'], 'mapping_scope_unique');
            $table->unique(['organization_id', 'supplier_account_id', 'name'], 'mapping_account_name_unique');
        });
        Schema::create('import_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('restaurant_location_id');
            $table->uuid('supplier_account_id');
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->uuid('mapping_template_id')->nullable();
            $table->string('fingerprint', 64);
            $table->enum('status', array_column(ImportStatus::cases(), 'value'))->default('uploaded');
            $table->string('request_id', 128);
            $table->jsonb('mapping_snapshot')->nullable();
            $table->integer('processed_count')->default(0);
            $table->integer('warning_count')->default(0);
            $table->integer('failed_count')->default(0);
            $table->integer('retry_count')->default(0);
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
            $table->foreign(['supplier_account_id', 'organization_id', 'restaurant_location_id'], 'import_account_scope_fk')->references(['id', 'organization_id', 'restaurant_location_id'])->on('supplier_accounts')->restrictOnDelete();
            $table->foreign(['mapping_template_id', 'organization_id', 'supplier_account_id'], 'import_mapping_scope_fk')->references(['id', 'organization_id', 'supplier_account_id'])->on('import_mapping_templates')->restrictOnDelete();
            $table->unique(['id', 'organization_id'], 'import_run_scope_unique');
            $table->unique(['organization_id', 'supplier_account_id', 'fingerprint'], 'import_fingerprint_unique');
            $table->index(['organization_id', 'created_at'], 'imports_tenant_created_index');
            $table->index(['organization_id', 'supplier_account_id', 'created_at'], 'imports_account_created_index');
            $table->index(['status', 'updated_at'], 'imports_status_updated_index');
        });
        Schema::create('import_files', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('import_run_id')->unique();
            $table->string('original_filename');
            $table->string('storage_key', 1024)->unique();
            $table->bigInteger('size_bytes');
            $table->string('media_type', 127);
            $table->enum('format', ['csv', 'xlsx']);
            $table->jsonb('headers')->nullable();
            $table->jsonb('sample_rows')->nullable();
            $table->timestampsTz();
            $table->foreign(['import_run_id', 'organization_id'], 'file_run_scope_fk')->references(['id', 'organization_id'])->on('import_runs')->restrictOnDelete();
            $table->unique(['id', 'import_run_id', 'organization_id'], 'file_scope_unique');
        });
        Schema::create('raw_import_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('import_run_id');
            $table->uuid('import_file_id');
            $table->integer('source_row');
            $table->jsonb('raw_values');
            $table->jsonb('mapped_values');
            $table->enum('status', ['pending', 'normalized', 'failed'])->default('pending');
            $table->timestampsTz();
            $table->foreign(['import_file_id', 'import_run_id', 'organization_id'], 'raw_file_scope_fk')->references(['id', 'import_run_id', 'organization_id'])->on('import_files')->restrictOnDelete();
            $table->unique(['import_file_id', 'source_row'], 'raw_source_row_unique');
            $table->unique(['id', 'import_run_id', 'organization_id'], 'raw_scope_unique');
            $table->index(['organization_id', 'import_run_id', 'status', 'source_row'], 'raw_batch_index');
        });
        Schema::create('normalized_import_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('import_run_id');
            $table->uuid('raw_import_record_id');
            $table->string('parser_version', 64);
            $table->jsonb('normalized_values');
            $table->jsonb('warnings');
            $table->enum('confidence', ['high', 'medium', 'low']);
            $table->timestampsTz();
            $table->foreign(['raw_import_record_id', 'import_run_id', 'organization_id'], 'normalized_raw_scope_fk')->references(['id', 'import_run_id', 'organization_id'])->on('raw_import_records')->restrictOnDelete();
            $table->unique(['raw_import_record_id', 'parser_version'], 'normalized_version_unique');
            $table->index(['organization_id', 'import_run_id'], 'normalized_run_index');
        });
        Schema::create('import_errors', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('import_run_id');
            $table->integer('source_row')->nullable();
            $table->string('field', 64)->nullable();
            $table->string('code', 64);
            $table->string('message', 512);
            $table->string('error_key', 128);
            $table->timestampsTz();
            $table->foreign(['import_run_id', 'organization_id'], 'error_run_scope_fk')->references(['id', 'organization_id'])->on('import_runs')->restrictOnDelete();
            $table->unique(['import_run_id', 'error_key'], 'import_error_unique');
            $table->index(['organization_id', 'import_run_id', 'source_row'], 'import_error_row_index');
        });
        DB::statement("ALTER TABLE import_runs ADD CONSTRAINT import_fingerprint_check CHECK (fingerprint ~ '^[0-9a-f]{64}$')");
        DB::statement('ALTER TABLE import_runs ADD CONSTRAINT import_counters_check CHECK (processed_count >= 0 AND warning_count >= 0 AND failed_count >= 0 AND retry_count >= 0)');
        DB::statement('ALTER TABLE import_files ADD CONSTRAINT import_size_check CHECK (size_bytes > 0 AND size_bytes <= 10485760)');
        DB::statement('ALTER TABLE raw_import_records ADD CONSTRAINT raw_row_check CHECK (source_row >= 2)');
        DB::statement('ALTER TABLE import_errors ADD CONSTRAINT error_row_check CHECK (source_row IS NULL OR source_row >= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('import_errors');
        Schema::dropIfExists('normalized_import_records');
        Schema::dropIfExists('raw_import_records');
        Schema::dropIfExists('import_files');
        Schema::dropIfExists('import_runs');
        Schema::dropIfExists('import_mapping_templates');
        Schema::table('supplier_accounts', function (Blueprint $table): void {
            $table->dropUnique('supplier_accounts_import_scope_unique');
            $table->dropUnique('supplier_accounts_tenant_unique');
        });
    }
};
