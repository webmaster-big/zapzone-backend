<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('google_calendar_settings', function (Blueprint $table) {
            $table->foreignId('location_id')
                ->nullable()
                ->after('id')
                ->constrained('locations')
                ->cascadeOnDelete();

            $table->unique('location_id');
        });
    }

    public function down(): void
    {
        Schema::table('google_calendar_settings', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropUnique(['location_id']);
            $table->dropColumn('location_id');
        });
    }
};
