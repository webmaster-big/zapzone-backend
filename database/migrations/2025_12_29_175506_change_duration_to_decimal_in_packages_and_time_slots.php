<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Change duration column in packages table from integer to decimal
        Schema::table('packages', function (Blueprint $table) {
            $table->decimal('duration', 8, 2)->change();
        });

        // Change duration column in package_time_slots table from integer to decimal
        Schema::table('package_time_slots', function (Blueprint $table) {
            $table->decimal('duration', 8, 2)->change();
        });

        // Change duration column in bookings table from integer to decimal
        Schema::table('bookings', function (Blueprint $table) {
            $table->decimal('duration', 8, 2)->change();
        });

        // Update duration_unit enum to include 'hours and minutes' option
        // For packages table
        DB::statement("ALTER TABLE packages MODIFY COLUMN duration_unit ENUM('hours', 'minutes', 'hours and minutes') DEFAULT 'hours'");
        
        // For package_time_slots table
        DB::statement("ALTER TABLE package_time_slots MODIFY COLUMN duration_unit ENUM('hours', 'minutes', 'hours and minutes') DEFAULT 'hours'");

        // For bookings table
        DB::statement("ALTER TABLE bookings MODIFY COLUMN duration_unit ENUM('hours', 'minutes', 'hours and minutes') DEFAULT 'hours'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert duration column in packages table back to integer
        Schema::table('packages', function (Blueprint $table) {
            $table->integer('duration')->change();
        });

        // Revert duration column in package_time_slots table back to integer
        Schema::table('package_time_slots', function (Blueprint $table) {
            $table->integer('duration')->change();
        });

        // Revert duration column in bookings table back to integer
        Schema::table('bookings', function (Blueprint $table) {
            $table->integer('duration')->change();
        });

        // Revert duration_unit enum
        DB::statement("ALTER TABLE packages MODIFY COLUMN duration_unit ENUM('hours', 'minutes') DEFAULT 'hours'");
        DB::statement("ALTER TABLE package_time_slots MODIFY COLUMN duration_unit ENUM('hours', 'minutes') DEFAULT 'hours'");
        DB::statement("ALTER TABLE bookings MODIFY COLUMN duration_unit ENUM('hours', 'minutes') DEFAULT 'hours'");
    }
};
