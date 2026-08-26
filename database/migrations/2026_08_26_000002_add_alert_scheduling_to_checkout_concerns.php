<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkout_concerns', function (Blueprint $table) {
            $table->timestamp('alert_after')->nullable()->after('alerted');
            $table->timestamp('alerted_at')->nullable()->after('alert_after');
            $table->index(['alert_after', 'alerted_at']);
        });
    }

    public function down(): void
    {
        Schema::table('checkout_concerns', function (Blueprint $table) {
            $table->dropIndex(['alert_after', 'alerted_at']);
            $table->dropColumn(['alert_after', 'alerted_at']);
        });
    }
};
