<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bookings')) {
            return;
        }

        Schema::table('bookings', function (Blueprint $table) {
            if (! Schema::hasColumn('bookings', 'package_price_at_booking')) {
                $table->decimal('package_price_at_booking', 10, 2)->nullable()->after('participants');
            }
            if (! Schema::hasColumn('bookings', 'price_per_additional_at_booking')) {
                $table->decimal('price_per_additional_at_booking', 10, 2)->nullable()->after('package_price_at_booking');
            }
            if (! Schema::hasColumn('bookings', 'package_pricing_type_at_booking')) {
                $table->string('package_pricing_type_at_booking', 32)->nullable()->after('price_per_additional_at_booking');
            }
        });

        if (Schema::hasTable('packages')) {
            DB::statement('
                UPDATE bookings b
                JOIN packages p ON p.id = b.package_id
                SET b.package_price_at_booking = p.price,
                    b.price_per_additional_at_booking = p.price_per_additional,
                    b.package_pricing_type_at_booking = p.pricing_type
                WHERE b.package_id IS NOT NULL
                  AND b.package_price_at_booking IS NULL
            ');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('bookings')) {
            return;
        }

        Schema::table('bookings', function (Blueprint $table) {
            foreach (['package_price_at_booking', 'price_per_additional_at_booking', 'package_pricing_type_at_booking'] as $column) {
                if (Schema::hasColumn('bookings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
