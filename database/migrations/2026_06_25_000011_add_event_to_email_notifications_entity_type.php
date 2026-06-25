<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('email_notifications')) {
            DB::statement("ALTER TABLE email_notifications MODIFY COLUMN entity_type ENUM('package','attraction','event','all') NOT NULL DEFAULT 'all'");
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('email_notifications')) {
            DB::statement("DELETE FROM email_notifications WHERE entity_type = 'event'");
            DB::statement("ALTER TABLE email_notifications MODIFY COLUMN entity_type ENUM('package','attraction','all') NOT NULL DEFAULT 'all'");
        }
    }
};
