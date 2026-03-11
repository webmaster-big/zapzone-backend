<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_purchases', function (Blueprint $table) {
            $table->json('applied_fees')->nullable()->after('total_amount');
        });
    }

    public function down(): void
    {
        Schema::table('event_purchases', function (Blueprint $table) {
            $table->dropColumn('applied_fees');
        });
    }
};
