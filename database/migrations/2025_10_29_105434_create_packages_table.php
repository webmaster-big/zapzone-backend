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
        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->text('description');
            $table->string('category');
            $table->text('features')->nullable();
            $table->decimal('price', 10, 2);
            $table->decimal('price_per_additional', 10, 2)->nullable();
            $table->integer('max_participants');
            $table->integer('duration');
            $table->enum('duration_unit', ['hours', 'minutes'])->default('hours');
            $table->decimal('price_per_additional_30min', 10, 2)->nullable();
            $table->decimal('price_per_additional_1hr', 10, 2)->nullable();
            $table->enum('availability_type', ['daily', 'weekly', 'monthly'])->default('daily');
            $table->json('available_days')->nullable();
            $table->json('available_week_days')->nullable();
            $table->json('available_month_days')->nullable();
            $table->string('image')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Indexes
            $table->index('location_id');
            $table->index('category');
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('packages');
    }
};
