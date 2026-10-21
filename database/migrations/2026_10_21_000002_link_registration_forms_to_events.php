<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('registration_events') || Schema::hasColumn('registration_events', 'event_id')) {
            return;
        }

        Schema::table('registration_events', function (Blueprint $table) {
            $table->foreignId('event_id')->nullable()->after('location_id')->constrained('events')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('registration_events') || ! Schema::hasColumn('registration_events', 'event_id')) {
            return;
        }

        Schema::table('registration_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('event_id');
        });
    }
};
