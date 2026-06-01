<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->decimal('duration', 8, 2)->change();
        });

        Schema::table('package_time_slots', function (Blueprint $table) {
            $table->decimal('duration', 8, 2)->change();
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->decimal('duration', 8, 2)->change();
        });

        DB::statement("ALTER TABLE packages MODIFY COLUMN duration_unit ENUM('hours', 'minutes', 'hours and minutes') DEFAULT 'hours'");
        
        DB::statement("ALTER TABLE package_time_slots MODIFY COLUMN duration_unit ENUM('hours', 'minutes', 'hours and minutes') DEFAULT 'hours'");

        DB::statement("ALTER TABLE bookings MODIFY COLUMN duration_unit ENUM('hours', 'minutes', 'hours and minutes') DEFAULT 'hours'");
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->integer('duration')->change();
        });

        Schema::table('package_time_slots', function (Blueprint $table) {
            $table->integer('duration')->change();
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->integer('duration')->change();
        });

        DB::statement("ALTER TABLE packages MODIFY COLUMN duration_unit ENUM('hours', 'minutes') DEFAULT 'hours'");
        DB::statement("ALTER TABLE package_time_slots MODIFY COLUMN duration_unit ENUM('hours', 'minutes') DEFAULT 'hours'");
        DB::statement("ALTER TABLE bookings MODIFY COLUMN duration_unit ENUM('hours', 'minutes') DEFAULT 'hours'");
    }
};
