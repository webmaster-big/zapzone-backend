<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('waiver_settings', function (Blueprint $table) {
            $table->boolean('gps_capture_enabled')->default(false)->after('kiosk_disable_autofill');
        });
    }

    public function down(): void
    {
        Schema::table('waiver_settings', function (Blueprint $table) {
            $table->dropColumn('gps_capture_enabled');
        });
    }
};
