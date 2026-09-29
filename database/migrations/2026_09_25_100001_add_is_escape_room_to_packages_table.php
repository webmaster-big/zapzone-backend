<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('packages', 'is_escape_room')) {
            return;
        }

        Schema::table('packages', function (Blueprint $table) {
            $table->boolean('is_escape_room')->default(false);
            $table->index(['location_id', 'is_escape_room'], 'packages_location_escape_room_index');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('packages', 'is_escape_room')) {
            return;
        }

        Schema::table('packages', function (Blueprint $table) {
            $table->dropIndex('packages_location_escape_room_index');
            $table->dropColumn('is_escape_room');
        });
    }
};
