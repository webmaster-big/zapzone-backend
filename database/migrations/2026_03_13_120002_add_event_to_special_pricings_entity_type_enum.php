<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Modify the entity_type ENUM to include 'event'
        DB::statement("ALTER TABLE special_pricings MODIFY COLUMN entity_type ENUM('package', 'attraction', 'event', 'all') NOT NULL DEFAULT 'all'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert to original ENUM values (this will fail if any 'event' records exist)
        DB::statement("ALTER TABLE special_pricings MODIFY COLUMN entity_type ENUM('package', 'attraction', 'all') NOT NULL DEFAULT 'all'");
    }
};
