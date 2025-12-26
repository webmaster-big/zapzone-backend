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
        Schema::create('day_offs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained()->onDelete('cascade');
            $table->date('date');
            $table->string('reason')->nullable();
            $table->boolean('is_recurring')->default(false); // For annual recurring holidays
            $table->timestamps();

            // Indexes
            $table->index('location_id');
            $table->index('date');
            $table->unique(['location_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('day_offs');
    }
};
