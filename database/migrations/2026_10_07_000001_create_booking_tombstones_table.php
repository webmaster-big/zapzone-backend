<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('booking_tombstones')) {
            return;
        }

        Schema::create('booking_tombstones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('booking_id');
            $table->unsignedBigInteger('location_id')->nullable();
            $table->timestamp('removed_at')->index();
            $table->index(['location_id', 'removed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_tombstones');
    }
};
