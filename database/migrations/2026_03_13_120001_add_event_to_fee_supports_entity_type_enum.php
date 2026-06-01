<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE fee_supports MODIFY COLUMN entity_type ENUM('package', 'attraction', 'event') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE fee_supports MODIFY COLUMN entity_type ENUM('package', 'attraction') NOT NULL");
    }
};
