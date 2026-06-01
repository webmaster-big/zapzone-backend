<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add multi-target support: a benefit can apply to several specific entities.
        Schema::table('membership_plan_benefits', function (Blueprint $table) {
            if (! Schema::hasColumn('membership_plan_benefits', 'scope_ids')) {
                $table->json('scope_ids')->nullable()->after('scope_id');
            }
        });

        // Allow scoping a benefit to a specific add-on (enum was missing 'addon').
        DB::statement(
            "ALTER TABLE membership_plan_benefits MODIFY scope_type "
            . "ENUM('any','package','attraction','event','addon','category','location') "
            . "NOT NULL DEFAULT 'any'"
        );
    }

    public function down(): void
    {
        DB::statement(
            "ALTER TABLE membership_plan_benefits MODIFY scope_type "
            . "ENUM('any','package','attraction','event','category','location') "
            . "NOT NULL DEFAULT 'any'"
        );

        Schema::table('membership_plan_benefits', function (Blueprint $table) {
            if (Schema::hasColumn('membership_plan_benefits', 'scope_ids')) {
                $table->dropColumn('scope_ids');
            }
        });
    }
};
