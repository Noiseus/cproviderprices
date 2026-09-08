<?php

use App\Enums\SubscriptionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code')->unique('plans_code_unique');
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('plan_entitlements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('plan_id')->constrained()->restrictOnDelete();
            $table->string('key');
            $table->json('value');
            $table->timestampsTz();
            $table->unique(['plan_id', 'key'], 'plan_entitlements_plan_key_unique');
        });

        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('plan_id')->constrained()->restrictOnDelete();
            $table->enum('status', array_column(SubscriptionStatus::cases(), 'value'));
            $table->timestampTz('starts_at');
            $table->timestampTz('trial_ends_at')->nullable();
            $table->timestampTz('current_period_ends_at')->nullable();
            $table->timestampTz('canceled_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->timestampsTz();
            $table->index(['organization_id', 'status'], 'subscriptions_organization_status_index');
            $table->index(['plan_id', 'status'], 'subscriptions_plan_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plan_entitlements');
        Schema::dropIfExists('plans');
    }
};
