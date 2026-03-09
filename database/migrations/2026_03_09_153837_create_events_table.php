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
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('image')->nullable();
            $table->enum('date_type', ['one_time', 'date_range'])->default('one_time');
            $table->date('start_date');
            $table->date('end_date')->nullable(); // null for one_time, set for date_range
            $table->time('time_start');
            $table->time('time_end');
            $table->integer('interval_minutes')->default(60); // slot interval in minutes
            $table->integer('max_bookings_per_slot')->nullable(); // null = unlimited
            $table->decimal('price', 10, 2)->default(0);
            $table->text('features')->nullable();
            $table->json('add_ons_order')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('location_id');
            $table->index('is_active');
            $table->index(['start_date', 'end_date']);
        });

        Schema::create('event_purchases', function (Blueprint $table) {
            $table->id();
            $table->string('reference_number')->unique();
            $table->foreignId('event_id')->constrained()->onDelete('cascade');
            $table->foreignId('customer_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('location_id')->constrained()->onDelete('cascade');
            $table->string('guest_name')->nullable();
            $table->string('guest_email')->nullable();
            $table->string('guest_phone')->nullable();
            $table->date('purchase_date');
            $table->time('purchase_time');
            $table->integer('quantity')->default(1);
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->decimal('amount_paid', 10, 2)->default(0);
            $table->decimal('discount_amount', 10, 2)->nullable();
            $table->enum('payment_method', ['card', 'in-store', 'paylater', 'authorize.net'])->nullable();
            $table->enum('payment_status', ['paid', 'partial', 'pending'])->default('partial');
            $table->enum('status', ['pending', 'confirmed', 'checked-in', 'completed', 'cancelled'])->default('pending');
            $table->string('transaction_id')->nullable();
            $table->text('notes')->nullable();
            $table->text('special_requests')->nullable();
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('event_id');
            $table->index('customer_id');
            $table->index('location_id');
            $table->index('status');
            $table->index(['purchase_date', 'purchase_time']);
            $table->index('reference_number');
        });

        Schema::create('event_purchase_add_ons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_purchase_id')->constrained()->onDelete('cascade');
            $table->foreignId('add_on_id')->constrained()->onDelete('cascade');
            $table->integer('quantity')->default(1);
            $table->decimal('price_at_purchase', 10, 2)->default(0);
            $table->timestamps();

            $table->index('event_purchase_id');
            $table->index('add_on_id');
        });

        Schema::create('event_add_ons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->onDelete('cascade');
            $table->foreignId('add_on_id')->constrained()->onDelete('cascade');
            $table->timestamps();

            $table->unique(['event_id', 'add_on_id']);
            $table->index('event_id');
            $table->index('add_on_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_add_ons');
        Schema::dropIfExists('event_purchase_add_ons');
        Schema::dropIfExists('event_purchases');
        Schema::dropIfExists('events');
    }
};
