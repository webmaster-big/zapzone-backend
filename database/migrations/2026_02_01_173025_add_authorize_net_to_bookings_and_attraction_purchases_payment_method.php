<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add 'authorize.net' to bookings payment_method enum
        DB::statement("ALTER TABLE bookings MODIFY COLUMN payment_method ENUM('card', 'in-store', 'paylater', 'authorize.net') NULL");

        // Add 'authorize.net' to attraction_purchases payment_method enum
        DB::statement("ALTER TABLE attraction_purchases MODIFY COLUMN payment_method ENUM('card', 'in-store', 'paylater', 'authorize.net') NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert bookings payment_method enum (remove 'authorize.net')
        DB::statement("ALTER TABLE bookings MODIFY COLUMN payment_method ENUM('card', 'in-store', 'paylater') NULL");

        // Revert attraction_purchases payment_method enum (remove 'authorize.net')
        DB::statement("ALTER TABLE attraction_purchases MODIFY COLUMN payment_method ENUM('card', 'in-store', 'paylater') NULL");
    }
};
