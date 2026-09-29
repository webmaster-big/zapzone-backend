<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('photo_overlays', 'package_id')) {
            return;
        }

        Schema::table('photo_overlays', function (Blueprint $table) {
            $table->foreignId('package_id')->nullable()->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('photo_overlays', 'package_id')) {
            return;
        }

        Schema::table('photo_overlays', function (Blueprint $table) {
            $table->dropConstrainedForeignId('package_id');
        });
    }
};
