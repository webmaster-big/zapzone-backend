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
        Schema::table('attraction_purchases', function (Blueprint $table) {
            $table->string('guest_address')->nullable()->after('guest_phone');
            $table->string('guest_city', 100)->nullable()->after('guest_address');
            $table->string('guest_state', 50)->nullable()->after('guest_city');
            $table->string('guest_zip', 20)->nullable()->after('guest_state');
            $table->string('guest_country', 100)->nullable()->after('guest_zip');
            $table->string('transaction_id')->nullable()->after('status')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attraction_purchases', function (Blueprint $table) {
            $table->dropColumn(['guest_address', 'guest_city', 'guest_state', 'guest_zip', 'guest_country', 'transaction_id']);
        });
    }
};
