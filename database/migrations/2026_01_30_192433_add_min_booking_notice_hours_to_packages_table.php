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
        Schema::table('packages', function (Blueprint $table) {
            // Minimum hours notice required before a booking can be made
            // NULL means no restriction, 0 means bookings can be made anytime
            // Example: 24 means booking must be made at least 24 hours before the time slot
            $table->integer('min_booking_notice_hours')->nullable()->default(null)->after('booking_window_days');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn('min_booking_notice_hours');
        });
    }
};
