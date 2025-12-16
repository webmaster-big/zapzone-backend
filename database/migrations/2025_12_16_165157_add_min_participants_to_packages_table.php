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
        Schema::table('packages', function (Blueprint $table) {
            $table->integer('min_participants')->nullable()->after('price_per_additional');
        });

        // Make max_participants nullable
        Schema::table('packages', function (Blueprint $table) {
            $table->integer('max_participants')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn('min_participants');
        });

        // Revert max_participants to not nullable
        Schema::table('packages', function (Blueprint $table) {
            $table->integer('max_participants')->nullable(false)->change();
        });
    }
};
