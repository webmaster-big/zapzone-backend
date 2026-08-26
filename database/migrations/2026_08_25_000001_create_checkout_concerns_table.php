<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_concerns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            $table->foreignId('location_id')->constrained()->onDelete('cascade');
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();

            $table->enum('kind', ['schedule_help', 'abandoned_checkout'])->index();

            $table->string('name');
            $table->string('phone');
            $table->string('email')->nullable();
            $table->text('message')->nullable();

            $table->string('entity_type')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('entity_name')->nullable();

            $table->date('preferred_date')->nullable();
            $table->string('preferred_time', 20)->nullable();

            $table->json('context')->nullable();

            $table->enum('status', ['new', 'contacted', 'resolved'])->default('new');
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->text('resolution_note')->nullable();

            $table->json('alerted')->nullable();
            $table->string('fingerprint', 191)->nullable();

            $table->timestamps();

            $table->index(['location_id', 'status']);
            $table->index(['kind', 'created_at']);
            $table->index('fingerprint');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_concerns');
    }
};
