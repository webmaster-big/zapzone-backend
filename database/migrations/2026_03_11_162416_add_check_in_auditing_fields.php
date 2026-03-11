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
        // Add checked_in_by to bookings table
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('checked_in_by')->nullable()->after('checked_in_at')->constrained('users')->nullOnDelete();
        });

        // Add check-in auditing fields to attraction_purchases table
        Schema::table('attraction_purchases', function (Blueprint $table) {
            $table->timestamp('checked_in_at')->nullable()->after('status');
            $table->foreignId('checked_in_by')->nullable()->after('checked_in_at')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropForeign(['checked_in_by']);
            $table->dropColumn('checked_in_by');
        });

        Schema::table('attraction_purchases', function (Blueprint $table) {
            $table->dropForeign(['checked_in_by']);
            $table->dropColumn(['checked_in_at', 'checked_in_by']);
        });
    }
};
