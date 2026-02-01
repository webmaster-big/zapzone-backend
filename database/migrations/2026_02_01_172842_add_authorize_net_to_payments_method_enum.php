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
        // MySQL doesn't support direct ALTER for ENUM, so we use raw SQL
        DB::statement("ALTER TABLE payments MODIFY COLUMN method ENUM('card', 'cash', 'authorize.net') NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert back to original enum values
        DB::statement("ALTER TABLE payments MODIFY COLUMN method ENUM('card', 'cash') NOT NULL");
    }
};
