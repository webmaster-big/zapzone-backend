<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('events')) {
            return;
        }

        Schema::table('events', function (Blueprint $table) {
            $table->time('time_start')->nullable()->change();
            $table->time('time_end')->nullable()->change();
            $table->integer('interval_minutes')->nullable()->default(60)->change();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('events')) {
            return;
        }

        DB::table('events')->whereNull('time_start')->update(['time_start' => '09:00:00']);
        DB::table('events')->whereNull('time_end')->update(['time_end' => '17:00:00']);
        DB::table('events')->whereNull('interval_minutes')->update(['interval_minutes' => 60]);

        Schema::table('events', function (Blueprint $table) {
            $table->time('time_start')->nullable(false)->change();
            $table->time('time_end')->nullable(false)->change();
            $table->integer('interval_minutes')->nullable(false)->default(60)->change();
        });
    }
};
