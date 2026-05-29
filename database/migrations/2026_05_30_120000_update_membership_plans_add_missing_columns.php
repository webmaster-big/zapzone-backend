<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds trial_days, requires_photo, is_family_or_group, max_family_size,
 * term_length_months, and punch_card_total to membership_plans.
 * Also extends the billing_cycle and usage_type enums to match frontend types.
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- Extend enums (MySQL ALTER TABLE) ---
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

        // --- Add missing columns ---
        Schema::table('membership_plans', function (Blueprint $table) {
            // Billing
            $table->unsignedInteger('term_length_months')->nullable()->after('custom_billing_days');

            // Trial period: if > 0, billing is deferred until trial_days after start
            $table->unsignedInteger('trial_days')->default(0)->after('price')
                  ->comment('Free trial length in days. 0 = no trial, charge immediately.');

            // Punch-card: total number of visits for the life of the punch-card
            $table->unsignedInteger('punch_card_total')->nullable()->after('visits_per_term');

            // Photo requirement for check-in
            $table->boolean('requires_photo')->default(false)->after('discount_percent');

            // Family / group plan flags
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
