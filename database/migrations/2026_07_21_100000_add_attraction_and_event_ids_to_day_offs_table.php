<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('day_offs', function (Blueprint $table) {
            $table->json('attraction_ids')->nullable()->after('room_ids');

            $table->json('event_ids')->nullable()->after('attraction_ids');
        });
    }

    public function down(): void
    {
        Schema::table('day_offs', function (Blueprint $table) {
            $table->dropColumn(['attraction_ids', 'event_ids']);
        });
    }
};
