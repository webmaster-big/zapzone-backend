<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bookings')) {
            return;
        }

        Schema::table('bookings', function (Blueprint $table) {
            if (! Schema::hasColumn('bookings', 'is_custom_package')) {
                $table->boolean('is_custom_package')->default(false)->index()->after('package_id');
            }
            if (! Schema::hasColumn('bookings', 'custom_package_name')) {
                $table->string('custom_package_name', 120)->nullable()->after('is_custom_package');
            }
            if (! Schema::hasColumn('bookings', 'custom_package_category')) {
                $table->string('custom_package_category', 255)->nullable()->after('custom_package_name');
            }
            if (! Schema::hasColumn('bookings', 'custom_package_terms')) {
                $table->json('custom_package_terms')->nullable()->after('custom_package_category');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('bookings')) {
            return;
        }

        if (Schema::hasIndex('bookings', 'bookings_is_custom_package_index')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->dropIndex('bookings_is_custom_package_index');
            });
        }

        Schema::table('bookings', function (Blueprint $table) {
            foreach (['custom_package_terms', 'custom_package_category', 'custom_package_name', 'is_custom_package'] as $column) {
                if (Schema::hasColumn('bookings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
