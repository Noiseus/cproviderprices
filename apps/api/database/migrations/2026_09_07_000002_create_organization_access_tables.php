<?php

use App\Enums\InvitationStatus;
use App\Enums\LocationStatus;
use App\Enums\MembershipStatus;
use App\Enums\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_memberships', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $table->enum('role', Role::organizationValues());
            $table->enum('status', array_column(MembershipStatus::cases(), 'value'));
            $table->foreignUuid('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('joined_at')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'user_id'], 'memberships_organization_user_unique');
            $table->index(['user_id', 'status'], 'memberships_user_status_index');
            $table->index(['organization_id', 'status'], 'memberships_organization_status_index');
        });

        Schema::create('restaurant_locations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('code');
            $table->enum('status', array_column(LocationStatus::cases(), 'value'))
                ->default(LocationStatus::Active->value);
            $table->string('timezone')->default('UTC');
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('city')->nullable();
            $table->string('region', 100)->nullable();
            $table->string('postal_code', 32)->nullable();
            $table->string('country_code', 2)->default('US');
            $table->timestampsTz();
            $table->unique(['organization_id', 'code'], 'locations_organization_code_unique');
            $table->index(['organization_id', 'status'], 'locations_organization_status_index');
        });

        Schema::create('invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->string('intended_email');
            $table->enum('intended_role', Role::organizationValues());
            $table->enum('status', array_column(InvitationStatus::cases(), 'value'))
                ->default(InvitationStatus::Pending->value);
            $table->char('token_hash', 64)->unique('invitations_token_hash_unique');
            $table->foreignUuid('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            $table->index(['organization_id', 'intended_email'], 'invitations_organization_email_index');
            $table->index(['status', 'expires_at'], 'invitations_status_expires_index');
        });

        DB::statement("ALTER TABLE organization_memberships ADD CONSTRAINT memberships_joined_at_status_check CHECK ((status = 'invited' AND joined_at IS NULL) OR (status <> 'invited' AND joined_at IS NOT NULL))");
        DB::statement('ALTER TABLE invitations ADD CONSTRAINT invitations_email_normalized_check CHECK (intended_email = lower(trim(intended_email)))');
        DB::statement("CREATE UNIQUE INDEX invitations_pending_organization_email_unique ON invitations (organization_id, lower(intended_email)) WHERE status = 'pending'");
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
        Schema::dropIfExists('restaurant_locations');
        Schema::dropIfExists('organization_memberships');
    }
};
