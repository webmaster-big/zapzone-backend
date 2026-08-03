<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waiver_audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('waiver_id')->constrained()->onDelete('cascade');
            $table->string('event');
            $table->timestamp('occurred_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('waiver_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waiver_audit_events');
    }
};
