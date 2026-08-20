<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ticket_orders')) {
            return;
        }

        Schema::create('ticket_orders', function (Blueprint $table) {
            $table->id();
            $table->string('reference_number')->unique();

            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('membership_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('guest_name')->nullable();
            $table->string('guest_email')->nullable();
            $table->string('guest_phone')->nullable();
            $table->string('guest_address')->nullable();
            $table->string('guest_city', 100)->nullable();
            $table->string('guest_state', 50)->nullable();
            $table->string('guest_zip', 20)->nullable();
            $table->string('guest_country', 100)->nullable();

            $table->date('purchase_date');
            $table->unsignedInteger('item_count')->default(0);
            $table->unsignedInteger('ticket_count')->default(0);

            $table->decimal('subtotal', 10, 2)->default(0);
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->decimal('membership_discount', 10, 2)->default(0);
            $table->decimal('fee_total', 10, 2)->default(0);
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->decimal('amount_paid', 10, 2)->default(0);

            $table->json('applied_fees')->nullable();
            $table->json('applied_discounts')->nullable();
            $table->foreignId('promo_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('gift_card_id')->nullable()->constrained()->nullOnDelete();

            $table->enum('payment_method', ['card', 'in-store', 'paylater', 'authorize.net'])->nullable();
            $table->enum('status', ['draft', 'pending', 'confirmed', 'checked-in', 'cancelled', 'refunded'])
                ->default('draft');
            $table->string('transaction_id')->nullable();

            $table->text('notes')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('purchase_date');
            $table->index(['location_id', 'status']);
            $table->index('transaction_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_orders');
    }
};
