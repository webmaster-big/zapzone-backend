<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('booking_tombstones') || Schema::hasColumn('booking_tombstones', 'company_id')) {
            return;
        }

        Schema::table('booking_tombstones', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable()->after('location_id');
            $table->index(['company_id', 'removed_at']);
        });

        DB::table('booking_tombstones')
            ->whereNull('company_id')
            ->update(['company_id' => DB::raw('(select locations.company_id from locations where locations.id = booking_tombstones.location_id)')]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('booking_tombstones') || ! Schema::hasColumn('booking_tombstones', 'company_id')) {
            return;
        }

        Schema::table('booking_tombstones', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'removed_at']);
            $table->dropColumn('company_id');
        });
    }
};
