<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attraction_purchases', function (Blueprint $table) {
            $table->foreignId('promo_id')->nullable()->after('applied_discounts')->constrained('promos')->nullOnDelete();
            $table->foreignId('gift_card_id')->nullable()->after('promo_id')->constrained('gift_cards')->nullOnDelete();
        });

        Schema::table('event_purchases', function (Blueprint $table) {
            $table->foreignId('promo_id')->nullable()->after('applied_discounts')->constrained('promos')->nullOnDelete();
            $table->foreignId('gift_card_id')->nullable()->after('promo_id')->constrained('gift_cards')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('attraction_purchases', function (Blueprint $table) {
            $table->dropForeign(['promo_id']);
            $table->dropForeign(['gift_card_id']);
            $table->dropColumn(['promo_id', 'gift_card_id']);
        });

        Schema::table('event_purchases', function (Blueprint $table) {
            $table->dropForeign(['promo_id']);
            $table->dropForeign(['gift_card_id']);
            $table->dropColumn(['promo_id', 'gift_card_id']);
        });
    }
};
