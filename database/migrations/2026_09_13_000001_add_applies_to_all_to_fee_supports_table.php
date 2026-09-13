<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fee_supports') && ! Schema::hasColumn('fee_supports', 'applies_to_all')) {
            Schema::table('fee_supports', function (Blueprint $table) {
                $table->boolean('applies_to_all')->default(false)->after('entity_type');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('fee_supports') && Schema::hasColumn('fee_supports', 'applies_to_all')) {
            Schema::table('fee_supports', function (Blueprint $table) {
                $table->dropColumn('applies_to_all');
            });
        }
    }
};
