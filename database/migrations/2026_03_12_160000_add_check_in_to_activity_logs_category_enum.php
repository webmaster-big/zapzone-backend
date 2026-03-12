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
        // Modify the ENUM to include 'check-in'
        DB::statement("ALTER TABLE activity_logs MODIFY COLUMN category ENUM('create', 'update', 'delete', 'view', 'login', 'logout', 'export', 'import', 'check-in', 'other') DEFAULT 'other'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert to original ENUM values
        // Note: This will fail if there are 'check-in' values in the table
        DB::statement("ALTER TABLE activity_logs MODIFY COLUMN category ENUM('create', 'update', 'delete', 'view', 'login', 'logout', 'export', 'import', 'other') DEFAULT 'other'");
    }
};
