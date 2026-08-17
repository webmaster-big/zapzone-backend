<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            if (!Schema::hasColumn('locations', 'latitude')) {
                $table->decimal('latitude', 10, 7)->nullable()->after('zip_code');
            }
            if (!Schema::hasColumn('locations', 'longitude')) {
                $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            }
            if (!Schema::hasColumn('locations', 'geocode_precision')) {
                $table->string('geocode_precision', 16)->nullable()->after('longitude');
            }
            if (!Schema::hasColumn('locations', 'geocoded_at')) {
                $table->timestamp('geocoded_at')->nullable()->after('geocode_precision');
            }
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            foreach (['geocoded_at', 'geocode_precision', 'longitude', 'latitude'] as $column) {
                if (Schema::hasColumn('locations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
