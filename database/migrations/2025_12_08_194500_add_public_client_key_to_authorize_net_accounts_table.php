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
        Schema::table('authorize_net_accounts', function (Blueprint $table) {
            $table->text('public_client_key')->nullable()->after('transaction_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('authorize_net_accounts', function (Blueprint $table) {
            $table->dropColumn('public_client_key');
        });
    }
};
