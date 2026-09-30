<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('locations', 'review_url')) {
            return;
        }

        Schema::table('locations', function (Blueprint $table) {
            $table->string('review_url', 500)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('locations', 'review_url')) {
            return;
        }

        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn('review_url');
        });
    }
};
