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
        \DB::statement("ALTER TABLE attraction_purchases MODIFY COLUMN payment_method ENUM('card', 'cash', 'paylater') NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        \DB::statement("ALTER TABLE attraction_purchases MODIFY COLUMN payment_method ENUM('card', 'cash') NULL");
    }
};
