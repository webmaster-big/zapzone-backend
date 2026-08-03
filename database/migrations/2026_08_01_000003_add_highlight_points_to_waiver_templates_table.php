<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('waiver_templates', function (Blueprint $table) {
            $table->text('highlight_points')->nullable()->after('body_text');
        });
    }

    public function down(): void
    {
        Schema::table('waiver_templates', function (Blueprint $table) {
            $table->dropColumn('highlight_points');
        });
    }
};
