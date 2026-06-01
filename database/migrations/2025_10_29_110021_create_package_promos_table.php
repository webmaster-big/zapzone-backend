<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('package_promos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained()->onDelete('cascade');
            $table->foreignId('promo_id')->constrained()->onDelete('cascade');
            $table->timestamps();

            $table->index('package_id');
            $table->index('promo_id');

            $table->unique(['package_id', 'promo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_promos');
    }
};
