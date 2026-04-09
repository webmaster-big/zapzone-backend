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
        Schema::table('promos', function (Blueprint $table) {
            $table->enum('code_mode', ['single', 'unique'])->default('single')->after('code');
            $table->uuid('batch_id')->nullable()->after('code_mode');

            $table->index('batch_id');
            $table->index('code_mode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('promos', function (Blueprint $table) {
            $table->dropIndex(['batch_id']);
            $table->dropIndex(['code_mode']);
            $table->dropColumn(['code_mode', 'batch_id']);
        });
    }
};
