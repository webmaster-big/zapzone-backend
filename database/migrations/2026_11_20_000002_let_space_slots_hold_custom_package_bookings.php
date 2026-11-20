<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('package_time_slots') || ! Schema::hasColumn('package_time_slots', 'package_id')) {
            return;
        }

        if ($this->packageIdIsNullable()) {
            return;
        }

        Schema::table('package_time_slots', function (Blueprint $table) {
            $table->unsignedBigInteger('package_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('package_time_slots') || ! Schema::hasColumn('package_time_slots', 'package_id')) {
            return;
        }

        if (! $this->packageIdIsNullable() || DB::table('package_time_slots')->whereNull('package_id')->exists()) {
            return;
        }

        Schema::table('package_time_slots', function (Blueprint $table) {
            $table->unsignedBigInteger('package_id')->nullable(false)->change();
        });
    }

    private function packageIdIsNullable(): bool
    {
        foreach (Schema::getColumns('package_time_slots') as $column) {
            if ($column['name'] === 'package_id') {
                return (bool) $column['nullable'];
            }
        }

        return false;
    }
};
