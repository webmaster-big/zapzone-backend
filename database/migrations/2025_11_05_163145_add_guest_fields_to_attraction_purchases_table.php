<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attraction_purchases', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->change();

            $table->string('guest_name')->nullable()->after('created_by');
            $table->string('guest_email')->nullable()->after('guest_name');
            $table->string('guest_phone')->nullable()->after('guest_email');

            $table->index('guest_email');
        });
    }

    public function down(): void
    {
        Schema::table('attraction_purchases', function (Blueprint $table) {
            $table->dropIndex(['guest_email']);
            $table->dropColumn(['guest_name', 'guest_email', 'guest_phone']);

            $table->foreignId('customer_id')->nullable(false)->change();
        });
    }
};
