<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
  {
        Schema::create('package_add_ons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained()->onDelete('cascade');
            $table->foreignId('add_on_id')->constrained()->onDelete('cascade');
            $table->timestamps();

            $table->index('package_id');
            $table->index('add_on_id');

            $table->unique(['package_id', 'add_on_id']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('package_add_ons');
    }
};
