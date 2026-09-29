<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('escape_room_sessions')) {
            return;
        }

        Schema::create('escape_room_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();
            $table->date('session_date');
            $table->time('session_time');
            $table->foreignId('photo_session_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('escaped')->nullable();
            $table->unsignedInteger('completion_seconds')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['package_id', 'session_date', 'session_time'], 'escape_room_sessions_slot_unique');
            $table->index(['location_id', 'session_date'], 'escape_room_sessions_location_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('escape_room_sessions');
    }
};
