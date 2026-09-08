<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            // Nullable on purpose: the table already holds thousands of historical rows that
            // predate the requirement, and a NOT NULL column would either reject them or
            // backfill a fake reason. "Required" is enforced in the request layer instead.
            $table->text('reason')->nullable()->after('description');

            // user_id is ON DELETE SET NULL, so deleting an employee silently strips the
            // attribution off every log they ever wrote. Snapshot who acted at write time so
            // the employee name survives independently of the users table.
            $table->string('actor_name')->nullable()->after('user_id');
            $table->string('actor_role', 50)->nullable()->after('actor_name');
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropColumn(['reason', 'actor_name', 'actor_role']);
        });
    }
};
