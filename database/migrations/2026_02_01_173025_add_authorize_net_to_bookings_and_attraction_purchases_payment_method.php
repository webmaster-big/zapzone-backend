<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE bookings MODIFY COLUMN payment_method ENUM('card', 'in-store', 'paylater', 'authorize.net') NULL");

        DB::statement("ALTER TABLE attraction_purchases MODIFY COLUMN payment_method ENUM('card', 'in-store', 'paylater', 'authorize.net') NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE bookings MODIFY COLUMN payment_method ENUM('card', 'in-store', 'paylater') NULL");

        DB::statement("ALTER TABLE attraction_purchases MODIFY COLUMN payment_method ENUM('card', 'in-store', 'paylater') NULL");
    }
};
