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
        if (!Schema::hasTable('fee_supports')) {
            Schema::create('fee_supports', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->onDelete('cascade');
                $table->foreignId('location_id')->nullable()->constrained()->onDelete('cascade');
                $table->string('fee_name'); // e.g. "Service Fee", "Tax", "Processing Fee"
                $table->decimal('fee_amount', 10, 2); // The fee value (dollar amount or percentage value)
                $table->enum('fee_calculation_type', ['fixed', 'percentage'])->default('fixed'); // How the fee is calculated
                $table->enum('fee_application_type', ['additive', 'inclusive'])->default('additive');
                // additive = fee is ADDED on top of base price (base $200 + 10% fee = $220 total)
                // inclusive = fee is INCLUDED in the base price (base shows $180 + fee $20 = $200 total, original price is $200)
                $table->json('entity_ids'); // Array of package IDs or attraction IDs this fee applies to
                $table->enum('entity_type', ['package', 'attraction']); // Whether this fee applies to packages or attractions
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                // Indexes
                $table->index(['company_id', 'location_id']);
                $table->index(['entity_type', 'is_active']);
                $table->index('is_active');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fee_supports');
    }
};
