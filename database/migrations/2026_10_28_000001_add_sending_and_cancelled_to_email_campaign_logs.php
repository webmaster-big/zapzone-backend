<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OLD = ['pending', 'sent', 'failed', 'bounced', 'opened', 'clicked'];

    private const NEW = ['pending', 'sent', 'failed', 'bounced', 'opened', 'clicked', 'sending', 'cancelled'];

    public function up(): void
    {
        if (!Schema::hasTable('email_campaign_logs')) {
            return;
        }

        Schema::table('email_campaign_logs', function (Blueprint $table) {
            $table->enum('status', self::NEW)->default('pending')->change();
        });

        DB::table('email_campaign_logs')
            ->where('status', 'pending')
            ->whereIn('email_campaign_id', DB::table('email_campaigns')->whereIn('status', ['completed', 'failed', 'cancelled'])->select('id'))
            ->update(['status' => 'cancelled', 'error_message' => 'Not sent: the campaign was cancelled.']);
    }

    public function down(): void
    {
        if (!Schema::hasTable('email_campaign_logs')) {
            return;
        }

        $stopped = DB::table('email_campaign_logs')->where('status', 'sending')->distinct()->pluck('email_campaign_id');

        DB::table('email_campaign_logs')->where('status', 'sending')->update(['status' => 'failed', 'error_message' => 'Not sent: sending stopped part-way through.']);
        DB::table('email_campaign_logs')->where('status', 'cancelled')->update(['status' => 'pending', 'error_message' => null]);

        foreach ($stopped as $campaignId) {
            DB::table('email_campaigns')->where('id', $campaignId)->update([
                'failed_count' => DB::table('email_campaign_logs')->where('email_campaign_id', $campaignId)->where('status', 'failed')->count(),
            ]);
        }

        Schema::table('email_campaign_logs', function (Blueprint $table) {
            $table->enum('status', self::OLD)->default('pending')->change();
        });
    }
};
