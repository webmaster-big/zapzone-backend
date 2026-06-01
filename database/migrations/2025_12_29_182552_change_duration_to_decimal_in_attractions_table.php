<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attractions', function (Blueprint $table) {
            $table->decimal('duration', 8, 2)->nullable()->change();
            
            $table->dropColumn('duration_unit');
        });
        
        Schema::table('attractions', function (Blueprint $table) {
            $table->enum('duration_unit', ['hours', 'minutes', 'hours and minutes'])->nullable()->after('duration');
        });
    }

    public function down(): void
    {
        Schema::table('attractions', function (Blueprint $table) {
            $table->integer('duration')->nullable()->change();
            
            $table->dropColumn('duration_unit');
        });
        
        Schema::table('attractions', function (Blueprint $table) {
            $table->enum('duration_unit', ['hours', 'minutes'])->nullable()->after('duration');
        });
    }
};
