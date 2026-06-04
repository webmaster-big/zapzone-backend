<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('membership_plans', 'billing_account_id')) {
            return;
        }

        Schema::table('membership_plans', function (Blueprint $table) {
            $table->foreignId('billing_account_id')
                ->nullable()
                ->after('location_id')
                ->constrained('authorize_net_accounts')
                ->nullOnDelete()
                ->comment('When set, all membership payments for this plan are processed through this Authorize.Net account directly (overrides member home-location lookup).');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('membership_plans', 'billing_account_id')) {
            return;
        }

        Schema::table('membership_plans', function (Blueprint $table) {
            $table->dropForeign(['billing_account_id']);
            $table->dropColumn('billing_account_id');
        });
    }
};
