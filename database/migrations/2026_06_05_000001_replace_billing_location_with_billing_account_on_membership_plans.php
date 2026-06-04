<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('membership_plans', function (Blueprint $table) {
            // Drop the old billing_location_id column if it exists
            if (Schema::hasColumn('membership_plans', 'billing_location_id')) {
                $table->dropForeign(['billing_location_id']);
                $table->dropColumn('billing_location_id');
            }

            // Add the replacement column pointing to a specific Authorize.Net account
            if (! Schema::hasColumn('membership_plans', 'billing_account_id')) {
                $table->foreignId('billing_account_id')
                    ->nullable()
                    ->after('location_id')
                    ->constrained('authorize_net_accounts')
                    ->nullOnDelete()
                    ->comment('When set, all membership payments for this plan are processed through this Authorize.Net account directly (overrides member home-location lookup).');
            }
        });
    }

    public function down(): void
    {
        Schema::table('membership_plans', function (Blueprint $table) {
            if (Schema::hasColumn('membership_plans', 'billing_account_id')) {
                $table->dropForeign(['billing_account_id']);
                $table->dropColumn('billing_account_id');
            }

            if (! Schema::hasColumn('membership_plans', 'billing_location_id')) {
                $table->foreignId('billing_location_id')
                    ->nullable()
                    ->after('location_id')
                    ->constrained('locations')
                    ->nullOnDelete();
            }
        });
    }
};
