<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('google_calendar_settings', function (Blueprint $table) {
            $table->dropColumn([
                'client_id',
                'client_secret',
                'frontend_redirect_url',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('google_calendar_settings', function (Blueprint $table) {
            $table->text('client_id')->nullable()->after('location_id');
            $table->text('client_secret')->nullable()->after('client_id');
            $table->string('frontend_redirect_url')->nullable()->after('client_secret');
        });
    }
};
