<?php

use App\Enums\PreferredLanguage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('preferred_language', 2)
                ->default(PreferredLanguage::English->value)
                ->after('status');
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_preferred_language_check CHECK (preferred_language IN ('en', 'es'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_preferred_language_check');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('preferred_language');
        });
    }
};
