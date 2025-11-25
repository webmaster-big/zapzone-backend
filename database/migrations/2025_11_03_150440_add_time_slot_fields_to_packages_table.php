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
        Schema::table('packages', function (Blueprint $table) {
            $table->time('time_slot_start')->nullable()->after('availability_type');
            $table->time('time_slot_end')->nullable()->after('time_slot_start');
            $table->integer('time_slot_interval')->default(30)->after('time_slot_end'); // in minutes
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn(['time_slot_start', 'time_slot_end', 'time_slot_interval']);
        });
    }
};
