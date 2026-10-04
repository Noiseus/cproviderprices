<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Measured on the 5,000-product pilot fixture: a selective canonical
        // `beefstak` typo and a bilingual alias `beef` + `tomato` lookup were
        // sequential scans before these indexes and targeted GIN bitmap scans
        // after them. Broad tomato/tomate paths correctly remain ordered btree
        // scans. The equivalent variant plan stayed sequential at pilot scale,
        // so it is intentionally not indexed yet. No vendor/offers index is
        // added until its final query shape requires one.
        DB::statement('CREATE INDEX canonical_products_normalized_key_trgm_index ON canonical_products USING gin (normalized_key gin_trgm_ops) WHERE is_active');
        DB::statement('CREATE INDEX product_aliases_normalized_alias_trgm_index ON product_aliases USING gin (normalized_alias gin_trgm_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS product_aliases_normalized_alias_trgm_index');
        DB::statement('DROP INDEX IF EXISTS canonical_products_normalized_key_trgm_index');
    }
};
