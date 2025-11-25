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
        Schema::create('package_time_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained()->onDelete('cascade');
            $table->foreignId('room_id')->constrained()->onDelete('cascade');
            $table->foreignId('booking_id')->constrained()->onDelete('cascade');
            $table->foreignId('customer_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null'); // Staff who processed
            $table->date('booked_date');
            $table->time('time_slot_start');
            $table->integer('duration'); // Duration value
            $table->enum('duration_unit', ['hours', 'minutes'])->default('hours');
            $table->enum('status', ['booked', 'completed', 'cancelled', 'no_show'])->default('booked');
            $table->text('notes')->nullable();
            $table->timestamps();

            // Indexes for quick lookups
            $table->index(['package_id', 'booked_date']);
            $table->index(['room_id', 'booked_date']);
            $table->index(['booking_id']);
            $table->index(['customer_id']);
            $table->index('status');

            // Unique constraint to prevent double booking
            $table->unique(['room_id', 'booked_date', 'time_slot_start'], 'unique_room_date_time');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('package_time_slots');
    }
};
