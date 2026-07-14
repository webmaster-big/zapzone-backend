<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('waiver_templates', function (Blueprint $table) {
            $table->text('photo_video_release_text')->nullable()->after('photo_video_release_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('waiver_templates', function (Blueprint $table) {
            $table->dropColumn('photo_video_release_text');
        });
    }
};
