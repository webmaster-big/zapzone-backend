<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Adds scheduled_date and scheduled_time columns to attraction_purchases table
     * so customers can select when they want to use their tickets based on the
     * attraction's availability schedule.
     */
    public function up(): void
    {
        Schema::table('attraction_purchases', function (Blueprint $table) {
            $table->date('scheduled_date')->nullable()->after('purchase_date');
            $table->time('scheduled_time')->nullable()->after('scheduled_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attraction_purchases', function (Blueprint $table) {
            $table->dropColumn(['scheduled_date', 'scheduled_time']);
        });
    }
};
