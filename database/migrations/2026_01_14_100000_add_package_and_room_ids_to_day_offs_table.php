<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('day_offs', function (Blueprint $table) {
            $table->json('package_ids')->nullable()->after('time_end');

            $table->json('room_ids')->nullable()->after('package_ids');
        });

        Schema::table('day_offs', function (Blueprint $table) {
            $table->dropUnique(['location_id', 'date']);
        });

        Schema::table('day_offs', function (Blueprint $table) {
            $table->index(['location_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::table('day_offs', function (Blueprint $table) {
            $table->dropIndex(['location_id', 'date']);
            $table->dropColumn(['package_ids', 'room_ids']);
            $table->unique(['location_id', 'date']);
        });
    }
};
