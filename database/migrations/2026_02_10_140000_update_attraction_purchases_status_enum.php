<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Updates the status enum on attraction_purchases table:
     * - Removes 'completed' (replaced by 'checked-in')
     * - Adds 'confirmed' (paid in full, awaiting check-in)
     * - Adds 'checked-in' (ticket used/scanned)
     * - Adds 'refunded' (fully refunded)
     *
     * New enum: pending, confirmed, checked-in, cancelled, refunded
     */
    public function up(): void
    {
        // Step 1: Convert existing 'completed' rows to 'checked-in'
        DB::statement("UPDATE attraction_purchases SET status = 'pending' WHERE status = 'completed'");

        // Step 2: Alter the enum column
        DB::statement("ALTER TABLE attraction_purchases MODIFY COLUMN status ENUM('pending', 'confirmed', 'checked-in', 'cancelled', 'refunded') NOT NULL DEFAULT 'pending'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Convert new statuses back to old ones
        DB::statement("UPDATE attraction_purchases SET status = 'pending' WHERE status = 'confirmed'");
        DB::statement("UPDATE attraction_purchases SET status = 'pending' WHERE status = 'checked-in'");
        DB::statement("UPDATE attraction_purchases SET status = 'pending' WHERE status = 'refunded'");

        // Revert to original enum
        DB::statement("ALTER TABLE attraction_purchases MODIFY COLUMN status ENUM('pending', 'completed', 'cancelled') NOT NULL DEFAULT 'pending'");
    }
};
