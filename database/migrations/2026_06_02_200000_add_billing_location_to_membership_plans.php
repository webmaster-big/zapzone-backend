<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('membership_plans', function (Blueprint $table) {
            $table->foreignId('billing_location_id')
                ->nullable()
                ->after('location_id')
                ->constrained('locations')
                ->nullOnDelete()
                ->comment('When set, membership payments for this plan are processed through this location\'s Authorize.Net account instead of the member\'s home location.');
        });
    }

    public function down(): void
    {
        Schema::table('membership_plans', function (Blueprint $table) {
            $table->dropForeign(['billing_location_id']);
            $table->dropColumn('billing_location_id');
        });
    }
};
