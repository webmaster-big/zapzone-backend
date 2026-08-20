<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('bookings', 'applied_discounts')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->json('applied_discounts')->nullable();
            });
        }

        Schema::table('attraction_purchases', function (Blueprint $table) {
            if (!Schema::hasColumn('attraction_purchases', 'discount_amount')) {
                $table->decimal('discount_amount', 10, 2)->default(0);
            }
            if (!Schema::hasColumn('attraction_purchases', 'applied_discounts')) {
                $table->json('applied_discounts')->nullable();
            }
        });

        if (!Schema::hasColumn('event_purchases', 'applied_discounts')) {
            Schema::table('event_purchases', function (Blueprint $table) {
                $table->json('applied_discounts')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('applied_discounts');
        });

        Schema::table('attraction_purchases', function (Blueprint $table) {
            $table->dropColumn(['discount_amount', 'applied_discounts']);
        });

        Schema::table('event_purchases', function (Blueprint $table) {
            $table->dropColumn('applied_discounts');
        });
    }
};
