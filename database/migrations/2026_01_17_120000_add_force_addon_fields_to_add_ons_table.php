<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('add_ons', function (Blueprint $table) {
            $table->decimal('price', 10, 2)->nullable()->change();

            $table->boolean('is_force_add_on')->default(false)->after('is_active');

            $table->json('price_each_packages')->nullable()->after('is_force_add_on');
        });
    }

    public function down(): void
    {
        Schema::table('add_ons', function (Blueprint $table) {
            $table->dropColumn('is_force_add_on');
            $table->dropColumn('price_each_packages');

            $table->decimal('price', 10, 2)->default(0)->change();
        });
    }
};
