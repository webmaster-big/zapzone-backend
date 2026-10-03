<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('staff_terminals')) {
            return;
        }

        Schema::create('staff_terminals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->string('device_id', 100)->unique();
            $table->string('label');
            $table->string('token_hash', 64);
            $table->foreignId('enrolled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ceiling_role', 50);
            $table->unsignedInteger('idle_seconds')->nullable();
            $table->boolean('idle_disabled')->default(false);
            $table->unsignedInteger('elevated_idle_seconds')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'location_id']);
            $table->index('token_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_terminals');
    }
};
