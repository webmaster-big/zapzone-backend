<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('locations', 'logo_path')) {
            return;
        }

        Schema::table('locations', function (Blueprint $table) {
            $table->string('logo_path')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('locations', 'logo_path')) {
            return;
        }

        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn('logo_path');
        });
    }
};
