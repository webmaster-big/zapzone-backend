<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('package_time_slots', 'overlap_seq')) {
            Schema::table('package_time_slots', function (Blueprint $table) {
                $table->unsignedSmallInteger('overlap_seq')->default(0)->after('time_slot_start');
            });
        }

        if (! Schema::hasIndex('package_time_slots', 'unique_room_date_time_seq')) {
            Schema::table('package_time_slots', function (Blueprint $table) {
                $table->unique(['room_id', 'booked_date', 'time_slot_start', 'overlap_seq'], 'unique_room_date_time_seq');
            });
        }

        if (Schema::hasIndex('package_time_slots', 'unique_room_date_time')) {
            Schema::table('package_time_slots', function (Blueprint $table) {
                $table->dropUnique('unique_room_date_time');
            });
        }
    }

    public function down(): void
    {
        $shared = DB::table('package_time_slots')
            ->select('room_id', 'booked_date', 'time_slot_start')
            ->groupBy('room_id', 'booked_date', 'time_slot_start')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if (! $shared && ! Schema::hasIndex('package_time_slots', 'unique_room_date_time')) {
            Schema::table('package_time_slots', function (Blueprint $table) {
                $table->unique(['room_id', 'booked_date', 'time_slot_start'], 'unique_room_date_time');
            });
        }

        if (Schema::hasIndex('package_time_slots', 'unique_room_date_time_seq')) {
            Schema::table('package_time_slots', function (Blueprint $table) {
                $table->dropUnique('unique_room_date_time_seq');
            });
        }

        if (Schema::hasColumn('package_time_slots', 'overlap_seq')) {
            Schema::table('package_time_slots', function (Blueprint $table) {
                $table->dropColumn('overlap_seq');
            });
        }
    }
};
