<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('location_photo_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('kiosk_countdown_seconds')->default(10)->after('slideshow_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('location_photo_settings', function (Blueprint $table) {
            $table->dropColumn('kiosk_countdown_seconds');
        });
    }
};
