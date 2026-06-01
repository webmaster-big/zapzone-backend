<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('package_availability_schedules', function (Blueprint $table) {
            $table->time('time_slot_start')->after('day_configuration');
        });
    }

    public function down(): void
    {
        Schema::table('package_availability_schedules', function (Blueprint $table) {
            $table->dropColumn('time_slot_start');
        });
    }
};
