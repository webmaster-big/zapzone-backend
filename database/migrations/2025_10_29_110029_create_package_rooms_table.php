<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('package_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained()->onDelete('cascade');
            $table->foreignId('room_id')->constrained()->onDelete('cascade');
            $table->timestamps();

            $table->index('package_id');
            $table->index('room_id');

            $table->unique(['package_id', 'room_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_rooms');
    }
};
