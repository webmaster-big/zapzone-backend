<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE membership_plans
             MODIFY COLUMN billing_cycle
             ENUM('monthly','quarterly','annual','one_time','custom')
             NOT NULL DEFAULT 'monthly'"
        );

        DB::statement(
            "ALTER TABLE membership_plans
             MODIFY COLUMN usage_type
             ENUM('limited','unlimited','limited_visits','punch_card')
             NOT NULL DEFAULT 'unlimited'"
        );

        Schema::table('membership_plans', function (Blueprint $table) {
            $table->unsignedInteger('term_length_months')->nullable()->after('custom_billing_days');

            $table->unsignedInteger('trial_days')->default(0)->after('price')
                  ->comment('Free trial length in days. 0 = no trial, charge immediately.');

            $table->unsignedInteger('punch_card_total')->nullable()->after('visits_per_term');

            $table->boolean('requires_photo')->default(false)->after('discount_percent');

            $table->boolean('is_family_or_group')->default(false)->after('requires_photo');
            $table->unsignedInteger('max_family_size')->nullable()->after('is_family_or_group');
        });
    }

    public function down(): void
    {
        Schema::table('membership_plans', function (Blueprint $table) {
            $table->dropColumn([
                'term_length_months',
                'trial_days',
                'punch_card_total',
                'requires_photo',
                'is_family_or_group',
                'max_family_size',
            ]);
        });

        DB::statement(
            "ALTER TABLE membership_plans
             MODIFY COLUMN billing_cycle
             ENUM('monthly','annual','custom')
             NOT NULL DEFAULT 'monthly'"
        );

        DB::statement(
            "ALTER TABLE membership_plans
             MODIFY COLUMN usage_type
             ENUM('limited','unlimited')
             NOT NULL DEFAULT 'unlimited'"
        );
    }
};
