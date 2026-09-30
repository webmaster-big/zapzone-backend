<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('email_notifications')) {
            return;
        }

        if (!Schema::hasColumn('email_notifications', 'promo_id')) {
            Schema::table('email_notifications', function (Blueprint $table) {
                $table->unsignedBigInteger('promo_id')->nullable()->after('email_template_id')->index();
            });
        }

        Schema::table('email_notifications', function (Blueprint $table) {
            if (!Schema::hasColumn('email_notifications', 'from_name')) {
                $table->string('from_name', 120)->nullable()->after('promo_id');
            }
            if (!Schema::hasColumn('email_notifications', 'review_url')) {
                $table->string('review_url', 500)->nullable()->after('from_name');
            }
            if (!Schema::hasColumn('email_notifications', 'activity_filter')) {
                $table->string('activity_filter', 32)->nullable()->after('review_url');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('email_notifications')) {
            return;
        }

        Schema::table('email_notifications', function (Blueprint $table) {
            foreach (['activity_filter', 'review_url', 'from_name'] as $column) {
                if (Schema::hasColumn('email_notifications', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        if (Schema::hasColumn('email_notifications', 'promo_id')) {
            Schema::table('email_notifications', function (Blueprint $table) {
                $table->dropIndex(['promo_id']);
                $table->dropColumn('promo_id');
            });
        }
    }
};
