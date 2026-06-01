<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('special_pricings')) {
            Schema::create('special_pricings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->onDelete('cascade');
                $table->foreignId('location_id')->nullable()->constrained()->onDelete('cascade');
                $table->string('name'); // e.g., "Tuesday Special", "Black Friday Sale", "Monthly 15th Discount"
                $table->text('description')->nullable(); // Optional description for customers

                $table->decimal('discount_amount', 10, 2); // The discount value
                $table->enum('discount_type', ['fixed', 'percentage'])->default('percentage');

                $table->enum('recurrence_type', ['one_time', 'weekly', 'monthly'])->default('one_time');
                $table->unsignedTinyInteger('recurrence_value')->nullable();
                $table->date('specific_date')->nullable(); // For one_time discounts

                $table->date('start_date')->nullable(); // When this becomes effective (null = immediately)
                $table->date('end_date')->nullable(); // When this expires (null = indefinite)

                $table->time('time_start')->nullable(); // Discount only valid from this time
                $table->time('time_end')->nullable(); // Discount only valid until this time

                $table->enum('entity_type', ['package', 'attraction', 'all'])->default('all');
                $table->json('entity_ids')->nullable(); // Array of specific package/attraction IDs (null = all of entity_type)

                $table->unsignedInteger('priority')->default(0);

                $table->boolean('is_stackable')->default(false); // Can stack with other special pricing?

                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['company_id', 'location_id']);
                $table->index(['entity_type', 'is_active']);
                $table->index('is_active');
                $table->index('recurrence_type');
                $table->index('specific_date');
                $table->index(['start_date', 'end_date']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('special_pricings');
    }
};
