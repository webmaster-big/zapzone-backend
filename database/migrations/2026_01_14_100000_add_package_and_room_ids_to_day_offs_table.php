<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * This migration adds package_ids and room_ids columns to allow
     * blocking specific packages and/or rooms instead of the entire location.
     * 
     * Blocking Logic:
     * - If both package_ids and room_ids are NULL/empty: blocks entire location (existing behavior)
     * - If only package_ids is set: blocks only those specific packages
     * - If only room_ids is set: blocks only those specific rooms
     * - If both are set: blocks both the specified packages AND rooms
     */
    public function up(): void
    {
        Schema::table('day_offs', function (Blueprint $table) {
            // JSON array of package IDs to block (null means all packages if room_ids is also null)
            $table->json('package_ids')->nullable()->after('time_end');
            
            // JSON array of room IDs to block (null means all rooms if package_ids is also null)
            $table->json('room_ids')->nullable()->after('package_ids');
        });

        // Drop the unique constraint that prevents multiple day offs on same date
        // We need to allow multiple entries for different resource combinations
        Schema::table('day_offs', function (Blueprint $table) {
            $table->dropUnique(['location_id', 'date']);
        });

        // Add a new index for efficient querying
        Schema::table('day_offs', function (Blueprint $table) {
            $table->index(['location_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('day_offs', function (Blueprint $table) {
            $table->dropIndex(['location_id', 'date']);
            $table->dropColumn(['package_ids', 'room_ids']);
            $table->unique(['location_id', 'date']);
        });
    }
};
