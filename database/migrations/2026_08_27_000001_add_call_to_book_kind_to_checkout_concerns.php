<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('checkout_concerns') || DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE checkout_concerns MODIFY kind ENUM('schedule_help', 'abandoned_checkout', 'call_to_book') NOT NULL");
    }

    public function down(): void
    {
        if (!Schema::hasTable('checkout_concerns') || DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::table('checkout_concerns')->where('kind', 'call_to_book')->update(['kind' => 'schedule_help']);
        DB::statement("ALTER TABLE checkout_concerns MODIFY kind ENUM('schedule_help', 'abandoned_checkout') NOT NULL");
    }
};
