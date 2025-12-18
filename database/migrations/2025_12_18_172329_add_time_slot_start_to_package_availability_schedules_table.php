<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('package_availability_schedules', function (Blueprint $table) {
            // Add the missing time_slot_start column after day_configuration
            $table->time('time_slot_start')->after('day_configuration');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('package_availability_schedules', function (Blueprint $table) {
            $table->dropColumn('time_slot_start');
        });
    }
};
