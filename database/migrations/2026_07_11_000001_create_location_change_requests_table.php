<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('location_change_requests')) {
            return;
        }

        Schema::create('location_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->onDelete('cascade');
            $table->foreignId('from_location_id')->constrained('locations')->onDelete('cascade');
            $table->foreignId('to_location_id')->constrained('locations')->onDelete('cascade');
            $table->foreignId('room_id')->nullable()->constrained('rooms')->nullOnDelete();

            $table->text('reason')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->text('review_notes')->nullable();

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();

            $table->index('booking_id');
            $table->index('to_location_id');
            $table->index('from_location_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_change_requests');
    }
};
