<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attraction_purchases', function (Blueprint $table) {
            if (!Schema::hasColumn('attraction_purchases', 'unit_price')) {
                $table->decimal('unit_price', 10, 2)->nullable();
            }
            if (!Schema::hasColumn('attraction_purchases', 'unit_price_after_discount')) {
                $table->decimal('unit_price_after_discount', 10, 2)->nullable();
            }
        });

        Schema::table('event_purchases', function (Blueprint $table) {
            if (!Schema::hasColumn('event_purchases', 'unit_price')) {
                $table->decimal('unit_price', 10, 2)->nullable();
            }
            if (!Schema::hasColumn('event_purchases', 'unit_price_after_discount')) {
                $table->decimal('unit_price_after_discount', 10, 2)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('attraction_purchases', function (Blueprint $table) {
            $table->dropColumn(['unit_price', 'unit_price_after_discount']);
        });

        Schema::table('event_purchases', function (Blueprint $table) {
            $table->dropColumn(['unit_price', 'unit_price_after_discount']);
        });
    }
};
