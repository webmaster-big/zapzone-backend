<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('photo_deliveries') && !Schema::hasColumn('photo_deliveries', 'link_nonce')) {
            Schema::table('photo_deliveries', function (Blueprint $table) {
                $column = $table->string('link_nonce', 16)->nullable();

                if (Schema::hasColumn('photo_deliveries', 'receipt_confirmed_via')) {
                    $column->after('receipt_confirmed_via');
                }
            });
        }

        if (Schema::hasTable('sms_sender_verifications') && !Schema::hasColumn('sms_sender_verifications', 'provider_sid')) {
            Schema::table('sms_sender_verifications', function (Blueprint $table) {
                $table->string('provider_sid', 64)->nullable()->after('photo_delivery_id');
            });
        }

        if (Schema::hasTable('sms_sender_verifications') && !Schema::hasIndex('sms_sender_verifications', 'sms_sender_verifications_sid_idx')) {
            Schema::table('sms_sender_verifications', fn (Blueprint $table) => $table->index('provider_sid', 'sms_sender_verifications_sid_idx'));
        }

        if (Schema::hasTable('photo_deliveries') && !Schema::hasIndex('photo_deliveries', 'photo_deliveries_text_recent_idx')) {
            Schema::table('photo_deliveries', fn (Blueprint $table) => $table->index(['channel', 'created_at'], 'photo_deliveries_text_recent_idx'));
        }

        if (Schema::hasTable('photo_deliveries') && !Schema::hasIndex('photo_deliveries', 'photo_deliveries_receipt_via_idx')) {
            Schema::table('photo_deliveries', fn (Blueprint $table) => $table->index(['receipt_confirmed_via', 'sent_at'], 'photo_deliveries_receipt_via_idx'));
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('photo_deliveries') && Schema::hasIndex('photo_deliveries', 'photo_deliveries_receipt_via_idx')) {
            Schema::table('photo_deliveries', fn (Blueprint $table) => $table->dropIndex('photo_deliveries_receipt_via_idx'));
        }

        if (Schema::hasTable('photo_deliveries') && Schema::hasIndex('photo_deliveries', 'photo_deliveries_text_recent_idx')) {
            Schema::table('photo_deliveries', fn (Blueprint $table) => $table->dropIndex('photo_deliveries_text_recent_idx'));
        }

        if (Schema::hasTable('sms_sender_verifications')) {
            if (Schema::hasIndex('sms_sender_verifications', 'sms_sender_verifications_sid_idx')) {
                Schema::table('sms_sender_verifications', fn (Blueprint $table) => $table->dropIndex('sms_sender_verifications_sid_idx'));
            }
            if (Schema::hasColumn('sms_sender_verifications', 'provider_sid')) {
                Schema::table('sms_sender_verifications', fn (Blueprint $table) => $table->dropColumn('provider_sid'));
            }
        }

        if (Schema::hasTable('photo_deliveries') && Schema::hasColumn('photo_deliveries', 'link_nonce')) {
            Schema::table('photo_deliveries', fn (Blueprint $table) => $table->dropColumn('link_nonce'));
        }
    }
};
