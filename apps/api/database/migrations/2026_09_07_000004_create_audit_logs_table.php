<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->nullable();
            $table->uuid('actor_id')->nullable();
            $table->string('actor_type')->nullable();
            $table->string('actor_label')->nullable();
            $table->string('action');
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestampTz('occurred_at');
            $table->index(['organization_id', 'occurred_at'], 'audit_logs_organization_occurred_index');
            $table->index(['actor_id', 'occurred_at'], 'audit_logs_actor_occurred_index');
            $table->index(['subject_type', 'subject_id'], 'audit_logs_subject_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
