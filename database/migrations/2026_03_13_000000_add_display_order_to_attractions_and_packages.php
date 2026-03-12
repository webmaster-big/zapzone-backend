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
        Schema::table('attractions', function (Blueprint $table) {
            $table->unsignedInteger('display_order')->default(0)->after('is_active');
            $table->index('display_order');
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->unsignedInteger('display_order')->default(0)->after('is_active');
            $table->index('display_order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attractions', function (Blueprint $table) {
            $table->dropIndex(['display_order']);
            $table->dropColumn('display_order');
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->dropIndex(['display_order']);
            $table->dropColumn('display_order');
        });
    }
};
