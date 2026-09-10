<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE subscriptions DROP CONSTRAINT subscriptions_status_check');
        DB::table('subscriptions')->where('status', 'paused')->update(['status' => 'suspended']);
        DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_status_check CHECK (status IN ('trialing', 'active', 'past_due', 'suspended', 'canceled', 'expired'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE subscriptions DROP CONSTRAINT subscriptions_status_check');
        DB::table('subscriptions')->where('status', 'suspended')->update(['status' => 'paused']);
        DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_status_check CHECK (status IN ('trialing', 'active', 'past_due', 'paused', 'canceled', 'expired'))");
    }
};
