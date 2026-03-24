<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE bookings MODIFY COLUMN payment_status ENUM('paid', 'partial', 'pending', 'refunded') NULL");
        DB::statement("ALTER TABLE event_purchases MODIFY COLUMN payment_status ENUM('paid', 'partial', 'pending', 'refunded') DEFAULT 'partial'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE bookings MODIFY COLUMN payment_status ENUM('paid', 'partial', 'pending') NULL");
        DB::statement("ALTER TABLE event_purchases MODIFY COLUMN payment_status ENUM('paid', 'partial', 'pending') DEFAULT 'partial'");
    }
};
