<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('gift_card_redemptions')) {
            return;
        }

        Schema::create('gift_card_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gift_card_id')->constrained('gift_cards');
            $table->string('payable_type', 60);
            $table->unsignedBigInteger('payable_id');
            $table->decimal('amount', 10, 2);
            $table->timestamps();

            $table->unique(['payable_type', 'payable_id'], 'gift_card_redemptions_payable_unique');
            $table->index('gift_card_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_card_redemptions');
    }
};
