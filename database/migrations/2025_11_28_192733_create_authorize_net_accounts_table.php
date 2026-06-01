<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('authorize_net_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->unique()->constrained()->onDelete('cascade');
            $table->text('api_login_id'); // Encrypted
            $table->text('transaction_key'); // Encrypted
            $table->enum('environment', ['sandbox', 'production'])->default('sandbox');
            $table->boolean('is_active')->default(true);
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->timestamps();

            $table->index('location_id');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('authorize_net_accounts');
    }
};
