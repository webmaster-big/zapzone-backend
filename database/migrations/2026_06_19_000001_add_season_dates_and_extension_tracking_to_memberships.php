<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('membership_plans', function (Blueprint $table) {
            if (! Schema::hasColumn('membership_plans', 'season_start_date')) {
                $table->date('season_start_date')->nullable()->after('term_length_months')
                    ->comment('Fixed plan start date (e.g. season pass opening). Informational/term baseline.');
            }
            if (! Schema::hasColumn('membership_plans', 'season_end_date')) {
                $table->date('season_end_date')->nullable()->after('season_start_date')
                    ->comment('Fixed plan expiration date. All memberships on this plan expire on this date unless manually extended.');
            }
        });

        Schema::table('memberships', function (Blueprint $table) {
            if (! Schema::hasColumn('memberships', 'manually_extended_at')) {
                $table->timestamp('manually_extended_at')->nullable()->after('current_term_end')
                    ->comment('When a staff member last manually extended this membership.');
            }
            if (! Schema::hasColumn('memberships', 'manually_extended_by_user_id')) {
                $table->foreignId('manually_extended_by_user_id')->nullable()->after('manually_extended_at')
                    ->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            if (Schema::hasColumn('memberships', 'manually_extended_by_user_id')) {
                $table->dropConstrainedForeignId('manually_extended_by_user_id');
            }
            if (Schema::hasColumn('memberships', 'manually_extended_at')) {
                $table->dropColumn('manually_extended_at');
            }
        });

        Schema::table('membership_plans', function (Blueprint $table) {
            if (Schema::hasColumn('membership_plans', 'season_end_date')) {
                $table->dropColumn('season_end_date');
            }
            if (Schema::hasColumn('membership_plans', 'season_start_date')) {
                $table->dropColumn('season_start_date');
            }
        });
    }
};
