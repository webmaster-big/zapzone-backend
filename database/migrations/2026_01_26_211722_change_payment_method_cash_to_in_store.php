<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        \DB::statement("UPDATE bookings SET payment_method = 'in-store' WHERE payment_method = 'cash'");
        \DB::statement("ALTER TABLE bookings MODIFY COLUMN payment_method ENUM('card', 'in-store', 'paylater') NULL");

        \DB::statement("UPDATE attraction_purchases SET payment_method = 'in-store' WHERE payment_method = 'cash'");
        \DB::statement("ALTER TABLE attraction_purchases MODIFY COLUMN payment_method ENUM('card', 'in-store', 'paylater') NULL");
    }

    public function down(): void
    {
        \DB::statement("UPDATE bookings SET payment_method = 'cash' WHERE payment_method = 'in-store'");
        \DB::statement("ALTER TABLE bookings MODIFY COLUMN payment_method ENUM('card', 'cash', 'paylater') NULL");

        \DB::statement("UPDATE attraction_purchases SET payment_method = 'cash' WHERE payment_method = 'in-store'");
        \DB::statement("ALTER TABLE attraction_purchases MODIFY COLUMN payment_method ENUM('card', 'cash', 'paylater') NULL");
    }
};
