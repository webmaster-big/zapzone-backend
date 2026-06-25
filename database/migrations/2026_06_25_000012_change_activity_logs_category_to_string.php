<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE activity_logs MODIFY COLUMN category VARCHAR(50) NOT NULL DEFAULT 'other'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE activity_logs MODIFY COLUMN category ENUM('create', 'update', 'delete', 'view', 'login', 'logout', 'export', 'import', 'check-in', 'other') DEFAULT 'other'");
    }
};
