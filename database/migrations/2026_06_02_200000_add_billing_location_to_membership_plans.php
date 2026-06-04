<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// NOTE: This migration originally added `billing_location_id` (FK → locations).
// That column has since been superseded by `billing_account_id` (FK → authorize_net_accounts)
// via the 2026_06_05_000001 migration. This file is kept as-is for historical accuracy.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('membership_plans', 'billing_location_id')) {
            return;
        }

        Schema::table('membership_plans', function (Blueprint $table) {
            $table->foreignId('billing_location_id')
                ->nullable()
                ->after('location_id')
                ->constrained('locations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('membership_plans', 'billing_location_id')) {
            return;
        }

        Schema::table('membership_plans', function (Blueprint $table) {
            $table->dropForeign(['billing_location_id']);
            $table->dropColumn('billing_location_id');
        });
    }
};
