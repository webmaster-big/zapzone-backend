<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Update bookings table: change 'cash' to 'in-store'
        \DB::statement("UPDATE bookings SET payment_method = 'in-store' WHERE payment_method = 'cash'");
        \DB::statement("ALTER TABLE bookings MODIFY COLUMN payment_method ENUM('card', 'in-store', 'paylater') NULL");

        // Update attraction_purchases table: change 'cash' to 'in-store'
        \DB::statement("UPDATE attraction_purchases SET payment_method = 'in-store' WHERE payment_method = 'cash'");
        \DB::statement("ALTER TABLE attraction_purchases MODIFY COLUMN payment_method ENUM('card', 'in-store', 'paylater') NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert bookings table: change 'in-store' back to 'cash'
        \DB::statement("UPDATE bookings SET payment_method = 'cash' WHERE payment_method = 'in-store'");
        \DB::statement("ALTER TABLE bookings MODIFY COLUMN payment_method ENUM('card', 'cash', 'paylater') NULL");

        // Revert attraction_purchases table: change 'in-store' back to 'cash'
        \DB::statement("UPDATE attraction_purchases SET payment_method = 'cash' WHERE payment_method = 'in-store'");
        \DB::statement("ALTER TABLE attraction_purchases MODIFY COLUMN payment_method ENUM('card', 'cash', 'paylater') NULL");
    }
};
