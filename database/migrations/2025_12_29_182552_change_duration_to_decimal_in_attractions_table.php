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
            // Change duration from integer to decimal
            $table->decimal('duration', 8, 2)->nullable()->change();
            
            // Update duration_unit enum to include 'hours and minutes'
            $table->dropColumn('duration_unit');
        });
        
        Schema::table('attractions', function (Blueprint $table) {
            $table->enum('duration_unit', ['hours', 'minutes', 'hours and minutes'])->nullable()->after('duration');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attractions', function (Blueprint $table) {
            // Revert duration back to integer
            $table->integer('duration')->nullable()->change();
            
            // Revert duration_unit back to original enum
            $table->dropColumn('duration_unit');
        });
        
        Schema::table('attractions', function (Blueprint $table) {
            $table->enum('duration_unit', ['hours', 'minutes'])->nullable()->after('duration');
        });
    }
};
