<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('day_off_change_logs')) {
            Schema::table('day_off_change_logs', function (Blueprint $table) {
                if (! Schema::hasColumn('day_off_change_logs', 'previous_location_id')) {
                    $table->unsignedBigInteger('previous_location_id')->nullable()->index()->after('location_id');
                }

                if (! Schema::hasColumn('day_off_change_logs', 'company_id')) {
                    $table->unsignedBigInteger('company_id')->nullable()->index()->after('location_id');
                }
            });

            return;
        }

        Schema::create('day_off_change_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('day_off_id')->nullable()->index();
            $table->unsignedBigInteger('location_id')->nullable()->index();
            $table->unsignedBigInteger('previous_location_id')->nullable()->index();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->string('action', 40);
            $table->unsignedBigInteger('changed_by')->nullable()->index();
            $table->string('changed_by_name')->nullable();
            $table->string('changed_by_email')->nullable();
            $table->string('changed_by_role', 40)->nullable();
            $table->string('summary', 500);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->json('changed_fields')->nullable();
            $table->json('context')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('day_off_change_logs')) {
            return;
        }

        if (DB::table('day_off_change_logs')->exists()) {
            Schema::rename('day_off_change_logs', 'day_off_change_logs_archive_' . now()->format('YmdHis'));

            return;
        }

        Schema::drop('day_off_change_logs');
    }
};
