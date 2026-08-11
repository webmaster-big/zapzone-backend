<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->string('timezone')->default('America/Detroit')->change();
        });

        DB::table('locations')
            ->whereNull('timezone')
            ->orWhere('timezone', '')
            ->orWhere('timezone', 'UTC')
            ->update(['timezone' => 'America/Detroit']);
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->string('timezone')->default('UTC')->change();
        });
    }
};
