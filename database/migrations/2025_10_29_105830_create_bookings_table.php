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
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('reference_number')->unique();
            //customer id nullable to allow guest bookings
            $table->foreignId('customer_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('package_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('location_id')->constrained()->onDelete('cascade');
            $table->foreignId('room_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('gift_card_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('promo_id')->nullable()->constrained()->onDelete('set null');
            $table->enum('type', ['package'])->default('package');
            $table->date('booking_date');
            $table->time('booking_time');
            $table->integer('participants');
            $table->integer('duration');
            $table->enum('duration_unit', ['hours', 'minutes'])->default('hours');
            $table->decimal('total_amount', 10, 2);
            $table->decimal('amount_paid', 10, 2)->default(0);
            $table->decimal('discount_amount', 10, 2)->nullable();
            $table->enum('payment_method', ['credit', 'debit', 'cash'])->nullable();
            $table->enum('payment_status', ['paid', 'partial'])->default('partial');
            $table->enum('status', ['pending', 'confirmed', 'checked-in', 'completed', 'cancelled'])->default('pending');
            $table->text('notes')->nullable();
            $table->text('special_requests')->nullable();
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            // Indexes
            $table->index('customer_id');
            $table->index('location_id');
            $table->index('status');
            $table->index(['booking_date', 'booking_time']);
            $table->index('reference_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
