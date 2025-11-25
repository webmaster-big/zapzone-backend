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
        Schema::create('attractions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->text('description');
            $table->decimal('price', 10, 2);
            $table->string('pricing_type')->default('per_person');
            $table->integer('max_capacity');
            $table->string('category');
            $table->string('unit')->nullable();
            $table->integer('duration')->nullable();
            $table->enum('duration_unit', ['hours', 'minutes'])->nullable();
            $table->json('availability')->nullable();
            $table->string('image')->nullable();
            $table->decimal('rating', 3, 2)->nullable();
            $table->integer('min_age')->nullable();
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
        Schema::dropIfExists('attractions');
    }
};
