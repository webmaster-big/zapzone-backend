<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rooms') || Schema::hasColumn('rooms', 'area_allows_simultaneous_starts')) {
            return;
        }

        Schema::table('rooms', function (Blueprint $table) {
            $table->boolean('area_allows_simultaneous_starts')->default(false)->after('booking_interval');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('rooms') || ! Schema::hasColumn('rooms', 'area_allows_simultaneous_starts')) {
            return;
        }

        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn('area_allows_simultaneous_starts');
        });
    }
};
