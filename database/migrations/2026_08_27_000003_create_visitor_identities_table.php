<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visitor_identities', function (Blueprint $table) {
            $table->id();
            $table->string('visitor_id', 64)->unique();
            $table->string('name', 120);
            $table->string('phone', 40);
            $table->string('email', 190)->nullable();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index('phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitor_identities');
    }
};
