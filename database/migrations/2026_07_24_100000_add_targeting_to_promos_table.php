<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promos', function (Blueprint $table) {
            $table->json('location_ids')->nullable()->after('code');
            $table->json('package_ids')->nullable()->after('location_ids');
            $table->json('attraction_ids')->nullable()->after('package_ids');
            $table->json('event_ids')->nullable()->after('attraction_ids');
        });
    }

    public function down(): void
    {
        Schema::table('promos', function (Blueprint $table) {
            $table->dropColumn(['location_ids', 'package_ids', 'attraction_ids', 'event_ids']);
        });
    }
};
