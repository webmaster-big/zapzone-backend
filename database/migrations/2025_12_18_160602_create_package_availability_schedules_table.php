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
        Schema::create('package_availability_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained()->onDelete('cascade');

            // Availability configuration
            $table->enum('availability_type', ['daily', 'weekly', 'monthly']); // Type of availability

            // For weekly: store array of days ['monday', 'friday']
            // For monthly: store array of day configurations ['first-monday', 'last-sunday']
            // For daily: can be null (applies to all days)
            $table->json('day_configuration')->nullable(); // e.g., ['monday', 'friday'], ['last-sunday']
            $table->time('time_slot_end');   // e.g., '00:00' for 12am (midnight)
            $table->integer('time_slot_interval')->default(30); // in minutes

            // Optional: priority if multiple schedules match
            $table->integer('priority')->default(0);

            // Active status
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // Indexes for better query performance
            $table->index(['package_id', 'availability_type']);
            $table->index(['package_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('package_availability_schedules');
    }
};
