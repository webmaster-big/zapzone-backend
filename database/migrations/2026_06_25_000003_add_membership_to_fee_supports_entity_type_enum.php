<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Allow fees to be attached to membership plans (in addition to packages,
     * attractions and events). Mirrors the earlier migration that added 'event'.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE fee_supports MODIFY COLUMN entity_type ENUM('package','attraction','event','membership') NOT NULL");
    }

    public function down(): void
    {
        // Drop any membership fees first so the narrowed enum doesn't reject existing rows.
        DB::table('fee_supports')->where('entity_type', 'membership')->delete();
        DB::statement("ALTER TABLE fee_supports MODIFY COLUMN entity_type ENUM('package','attraction','event') NOT NULL");
    }
};
