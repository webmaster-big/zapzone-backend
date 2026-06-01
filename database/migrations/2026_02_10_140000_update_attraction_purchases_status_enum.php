<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("UPDATE attraction_purchases SET status = 'pending' WHERE status = 'completed'");

        DB::statement("ALTER TABLE attraction_purchases MODIFY COLUMN status ENUM('pending', 'confirmed', 'checked-in', 'cancelled', 'refunded') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        DB::statement("UPDATE attraction_purchases SET status = 'pending' WHERE status = 'confirmed'");
        DB::statement("UPDATE attraction_purchases SET status = 'pending' WHERE status = 'checked-in'");
        DB::statement("UPDATE attraction_purchases SET status = 'pending' WHERE status = 'refunded'");

        DB::statement("ALTER TABLE attraction_purchases MODIFY COLUMN status ENUM('pending', 'completed', 'cancelled') NOT NULL DEFAULT 'pending'");
    }
};
