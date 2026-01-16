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
        Schema::table('add_ons', function (Blueprint $table) {
            // Make price nullable (for force add-ons with package-specific pricing)
            $table->decimal('price', 10, 2)->nullable()->change();

            // Add force add-on flag
            $table->boolean('is_force_add_on')->default(false)->after('is_active');

            // Add package-specific pricing as JSON
            $table->json('price_each_packages')->nullable()->after('is_force_add_on');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('add_ons', function (Blueprint $table) {
            $table->dropColumn('is_force_add_on');
            $table->dropColumn('price_each_packages');

            // Revert price to non-nullable
            $table->decimal('price', 10, 2)->default(0)->change();
        });
    }
};
