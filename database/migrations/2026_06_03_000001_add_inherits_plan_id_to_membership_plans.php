<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('membership_plans', function (Blueprint $table) {
            if (! Schema::hasColumn('membership_plans', 'inherits_plan_id')) {
                $table->unsignedBigInteger('inherits_plan_id')
                    ->nullable()
                    ->after('tier')
                    ->comment('Optional parent plan whose benefits this plan also inherits');

                $table->foreign('inherits_plan_id')
                    ->references('id')
                    ->on('membership_plans')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('membership_plans', function (Blueprint $table) {
            if (Schema::hasColumn('membership_plans', 'inherits_plan_id')) {
                $table->dropForeign(['inherits_plan_id']);
                $table->dropColumn('inherits_plan_id');
            }
        });
    }
};
