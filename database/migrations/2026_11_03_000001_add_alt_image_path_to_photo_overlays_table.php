<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('photo_overlays') || Schema::hasColumn('photo_overlays', 'alt_image_path')) {
            return;
        }

        Schema::table('photo_overlays', function (Blueprint $table) {
            $table->string('alt_image_path')->nullable()->after('image_path');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('photo_overlays') || !Schema::hasColumn('photo_overlays', 'alt_image_path')) {
            return;
        }

        Schema::table('photo_overlays', function (Blueprint $table) {
            $table->dropColumn('alt_image_path');
        });
    }
};
