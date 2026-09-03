<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('waivers', function (Blueprint $table) {
            $table->unsignedBigInteger('package_id')->nullable()->after('booking_id');
            $table->unsignedBigInteger('attraction_id')->nullable()->after('package_id');
            $table->index('package_id');
            $table->index('attraction_id');
        });
    }

    public function down(): void
    {
        Schema::table('waivers', function (Blueprint $table) {
            $table->dropIndex(['attraction_id']);
            $table->dropIndex(['package_id']);
            $table->dropColumn(['attraction_id', 'package_id']);
        });
    }
};
