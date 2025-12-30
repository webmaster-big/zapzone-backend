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
        Schema::table('day_offs', function (Blueprint $table) {
            // Optional time fields for partial-day closures
            // time_start: Close starting at this time (e.g., 16:00 = close at 4 PM)
            // time_end: Delayed opening until this time (e.g., 16:00 = open at 4 PM)
            // If both null: entire day is blocked
            // If only time_start: blocked from that time until end of day
            // If only time_end: blocked from start of day until that time
            // If both set: blocked during that specific time range
            $table->time('time_start')->nullable()->after('date');
            $table->time('time_end')->nullable()->after('time_start');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('day_offs', function (Blueprint $table) {
            $table->dropColumn(['time_start', 'time_end']);
        });
    }
};
