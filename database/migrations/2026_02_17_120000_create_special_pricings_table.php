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
        if (!Schema::hasTable('special_pricings')) {
            Schema::create('special_pricings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->onDelete('cascade');
                $table->foreignId('location_id')->nullable()->constrained()->onDelete('cascade');
                $table->string('name'); // e.g., "Tuesday Special", "Black Friday Sale", "Monthly 15th Discount"
                $table->text('description')->nullable(); // Optional description for customers

                // Discount configuration
                $table->decimal('discount_amount', 10, 2); // The discount value
                $table->enum('discount_type', ['fixed', 'percentage'])->default('percentage');

                // Recurrence configuration
                $table->enum('recurrence_type', ['one_time', 'weekly', 'monthly'])->default('one_time');
                // For weekly: 0=Sunday, 1=Monday, 2=Tuesday, 3=Wednesday, 4=Thursday, 5=Friday, 6=Saturday
                // For monthly: 1-31 (day of month)
                // For one_time: null (uses specific_date instead)
                $table->unsignedTinyInteger('recurrence_value')->nullable();
                $table->date('specific_date')->nullable(); // For one_time discounts

                // Effective date range (when this special pricing is active)
                $table->date('start_date')->nullable(); // When this becomes effective (null = immediately)
                $table->date('end_date')->nullable(); // When this expires (null = indefinite)

                // Optional time range restriction
                $table->time('time_start')->nullable(); // Discount only valid from this time
                $table->time('time_end')->nullable(); // Discount only valid until this time

                // Entity targeting (packages, attractions, or all)
                $table->enum('entity_type', ['package', 'attraction', 'all'])->default('all');
                $table->json('entity_ids')->nullable(); // Array of specific package/attraction IDs (null = all of entity_type)

                // Priority for handling multiple discounts (higher = applied first/takes precedence)
                $table->unsignedInteger('priority')->default(0);

                // Stacking behavior
                $table->boolean('is_stackable')->default(false); // Can stack with other special pricing?

                $table->boolean('is_active')->default(true);
                $table->timestamps();

                // Indexes
                $table->index(['company_id', 'location_id']);
                $table->index(['entity_type', 'is_active']);
                $table->index('is_active');
                $table->index('recurrence_type');
                $table->index('specific_date');
                $table->index(['start_date', 'end_date']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('special_pricings');
    }
};
