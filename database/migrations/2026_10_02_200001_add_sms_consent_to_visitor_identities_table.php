<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitor_identities', function (Blueprint $table) {
            $table->boolean('sms_consent')->default(false)->after('location_id');
            $table->timestamp('sms_consent_at')->nullable()->after('sms_consent');
        });
    }

    public function down(): void
    {
        Schema::table('visitor_identities', function (Blueprint $table) {
            $table->dropColumn(['sms_consent', 'sms_consent_at']);
        });
    }
};
