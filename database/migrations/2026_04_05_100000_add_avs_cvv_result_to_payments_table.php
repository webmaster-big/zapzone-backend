<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('avs_result_code', 1)->nullable()->after('card_last_four');
            $table->string('cvv_result_code', 1)->nullable()->after('avs_result_code');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['avs_result_code', 'cvv_result_code']);
        });
    }
};
