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
        Schema::table('membership_plan_benefits', function (Blueprint $table) {
            $table->boolean('requires_manual_redemption')->default(false)->after('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_plan_benefits', function (Blueprint $table) {
            $table->dropColumn('requires_manual_redemption');
        });
    }
};
