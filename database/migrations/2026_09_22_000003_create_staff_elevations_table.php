<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('staff_elevations')) {
            return;
        }

        Schema::create('staff_elevations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('capability', 60);
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('staff_terminal_id')->nullable()->constrained('staff_terminals')->nullOnDelete();
            $table->string('target_type', 60)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('token_hash', 64)->unique();
            $table->unsignedSmallInteger('uses_remaining')->default(1);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'capability']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_elevations');
    }
};
