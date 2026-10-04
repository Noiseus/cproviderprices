<?php

namespace Database\Factories;

use App\Models\ImportFile;
use App\Models\ImportRun;
use App\Models\NormalizedImportRecord;
use App\Models\RawImportRecord;
use App\Models\SupplierAccount;
use App\Models\User;
use App\Models\VendorItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<VendorItem> */
class VendorItemFactory extends Factory
{
    protected $model = VendorItem::class;

    public function definition(): array
    {
        $account = SupplierAccount::factory()->create();
        $run = ImportRun::query()->create(['organization_id' => $account->organization_id, 'restaurant_location_id' => $account->restaurant_location_id, 'supplier_account_id' => $account->id, 'created_by' => User::factory()->create()->id, 'fingerprint' => hash('sha256', (string) Str::uuid7()), 'request_id' => 'catalog-factory-'.Str::uuid7()]);
        $file = ImportFile::query()->create(['organization_id' => $account->organization_id, 'import_run_id' => $run->id, 'original_filename' => 'catalog.csv', 'storage_key' => 'catalog/'.Str::uuid7(), 'size_bytes' => 1, 'media_type' => 'text/csv', 'format' => 'csv']);
        $raw = RawImportRecord::query()->create(['organization_id' => $account->organization_id, 'import_run_id' => $run->id, 'import_file_id' => $file->id, 'source_row' => 2, 'raw_values' => ['SKU' => 'CAT-001', 'Description' => 'Catalog tomato'], 'mapped_values' => ['supplier_sku' => 'CAT-001', 'description' => 'Catalog tomato'], 'status' => 'normalized']);
        $normalized = NormalizedImportRecord::query()->create(['organization_id' => $account->organization_id, 'import_run_id' => $run->id, 'raw_import_record_id' => $raw->id, 'parser_version' => '1.0.0', 'normalized_values' => ['measurements' => [], 'packs' => [], 'size_code' => null, 'variable_weight' => false], 'warnings' => [], 'confidence' => 'high']);

        return ['organization_id' => $account->organization_id, 'supplier_account_id' => $account->id, 'first_normalized_import_record_id' => $normalized->id, 'last_normalized_import_record_id' => $normalized->id, 'supplier_item_identifier' => 'CAT-001', 'identity_key' => 'sku:CAT-001', 'raw_description' => 'Catalog tomato', 'normalized_description' => 'catalog tomato', 'brand' => null, 'normalized_attributes' => [], 'parser_version' => '1.0.0', 'first_seen_at' => now(), 'last_seen_at' => now()];
    }
}
