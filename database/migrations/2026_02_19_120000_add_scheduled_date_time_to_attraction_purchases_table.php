<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attraction_purchases', function (Blueprint $table) {
            $table->date('scheduled_date')->nullable()->after('purchase_date');
            $table->time('scheduled_time')->nullable()->after('scheduled_date');
        });
    }

    public function down(): void
    {
        Schema::table('attraction_purchases', function (Blueprint $table) {
            $table->dropColumn(['scheduled_date', 'scheduled_time']);
        });
    }
};
