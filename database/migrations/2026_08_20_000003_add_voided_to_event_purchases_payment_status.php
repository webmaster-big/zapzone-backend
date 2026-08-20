<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE event_purchases MODIFY payment_status ENUM('paid','partial','pending','refunded','voided') NOT NULL DEFAULT 'partial'");
    }

    public function down(): void
    {
        DB::statement("UPDATE event_purchases SET payment_status = 'refunded' WHERE payment_status = 'voided'");
        DB::statement("ALTER TABLE event_purchases MODIFY payment_status ENUM('paid','partial','pending','refunded') NOT NULL DEFAULT 'partial'");
    }
};
