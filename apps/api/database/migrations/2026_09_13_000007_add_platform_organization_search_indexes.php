<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX organizations_lower_name_prefix_index ON organizations (lower(name) text_pattern_ops)');
        DB::statement('CREATE INDEX organizations_lower_slug_prefix_index ON organizations (lower(slug) text_pattern_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS organizations_lower_slug_prefix_index');
        DB::statement('DROP INDEX IF EXISTS organizations_lower_name_prefix_index');
    }
};
