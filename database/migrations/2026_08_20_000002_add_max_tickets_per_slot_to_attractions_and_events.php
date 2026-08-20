<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('attractions', 'max_tickets_per_slot')) {
            Schema::table('attractions', function (Blueprint $table) {
                $table->unsignedInteger('max_tickets_per_slot')->nullable()->after('max_capacity');
            });
        }

        if (!Schema::hasColumn('events', 'max_tickets_per_slot')) {
            Schema::table('events', function (Blueprint $table) {
                $table->unsignedInteger('max_tickets_per_slot')->nullable()->after('max_bookings_per_slot');
            });
        }
    }

    public function down(): void
    {
        Schema::table('attractions', function (Blueprint $table) {
            $table->dropColumn('max_tickets_per_slot');
        });

        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('max_tickets_per_slot');
        });
    }
};
