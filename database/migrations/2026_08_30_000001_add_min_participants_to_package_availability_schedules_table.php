<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('package_availability_schedules', function (Blueprint $table) {
            $table->unsignedInteger('min_participants')->nullable()->after('time_slot_interval');
        });
    }

    public function down(): void
    {
        Schema::table('package_availability_schedules', function (Blueprint $table) {
            $table->dropColumn('min_participants');
        });
    }
};
