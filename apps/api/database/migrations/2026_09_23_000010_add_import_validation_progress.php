<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_runs', function (Blueprint $table): void {
            $table->unsignedInteger('validation_cursor')->default(1);
            $table->timestampTz('validation_started_at')->nullable();
            $table->timestampTz('validation_completed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('import_runs', fn (Blueprint $table) => $table->dropColumn([
            'validation_cursor', 'validation_started_at', 'validation_completed_at',
        ]));
    }
};
