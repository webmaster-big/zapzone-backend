<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->integer('min_participants')->nullable()->after('price_per_additional');
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->integer('max_participants')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn('min_participants');
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->integer('max_participants')->nullable(false)->change();
        });
    }
};
