<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->string('area_group')->nullable()->after('break_time');
            $table->integer('booking_interval')->default(15)->after('area_group'); // Interval in minutes
            
            $table->index('area_group');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropIndex(['area_group']);
            $table->dropColumn(['area_group', 'booking_interval']);
        });
    }
};
