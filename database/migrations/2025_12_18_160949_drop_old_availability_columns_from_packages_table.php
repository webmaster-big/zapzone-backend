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
            // Drop old availability columns - now handled by package_availability_schedules table
            $table->dropColumn([
                'availability_type',
                'available_days',
                'available_week_days',
                'available_month_days',
                'time_slot_start',
                'time_slot_end',
                'time_slot_interval',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            // Restore columns if rollback is needed
            $table->enum('availability_type', ['daily', 'weekly', 'monthly'])->default('daily');
            $table->json('available_days')->nullable();
            $table->json('available_week_days')->nullable();
            $table->json('available_month_days')->nullable();
            $table->time('time_slot_start')->nullable();
            $table->time('time_slot_end')->nullable();
            $table->integer('time_slot_interval')->default(30);
        });
    }
};
