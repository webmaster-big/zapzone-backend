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
        Schema::table('bookings', function (Blueprint $table) {
            $table->json('applied_discounts')->nullable()->after('applied_fees');
        });

        Schema::table('attraction_purchases', function (Blueprint $table) {
            $table->decimal('discount_amount', 10, 2)->default(0)->after('applied_fees');
            $table->json('applied_discounts')->nullable()->after('discount_amount');
        });

        Schema::table('event_purchases', function (Blueprint $table) {
            $table->json('applied_discounts')->nullable()->after('applied_fees');
        });
    }

    /**
     * Reverse the migrations.
     */
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
