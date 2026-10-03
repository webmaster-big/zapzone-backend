<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('locations', 'show_on_main_page')) {
            return;
        }

        Schema::table('locations', function (Blueprint $table) {
            $table->boolean('show_on_main_page')->default(true)->after('is_active');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('locations', 'show_on_main_page')) {
            return;
        }

        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn('show_on_main_page');
        });
    }
};
