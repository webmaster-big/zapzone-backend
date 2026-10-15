<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const WEBMASTER = 'webmaster@bestingames.com';

    public function up(): void
    {
        if (!Schema::hasTable('email_notifications')) {
            return;
        }

        if (!Schema::hasColumn('email_notifications', 'excluded_emails')) {
            Schema::table('email_notifications', function (Blueprint $table) {
                $table->json('excluded_emails')->nullable()->after('custom_emails');
            });
        }

        if (!Schema::hasTable('users')) {
            return;
        }

        $companyIds = DB::table('users')
            ->whereRaw('LOWER(TRIM(email)) = ?', [self::WEBMASTER])
            ->whereNotNull('company_id')
            ->distinct()
            ->pluck('company_id');

        if ($companyIds->isEmpty()) {
            return;
        }

        DB::table('email_notifications')
            ->whereIn('company_id', $companyIds)
            ->orderBy('id')
            ->get(['id', 'trigger_type', 'recipient_types', 'custom_emails', 'excluded_emails'])
            ->each(function ($row) {
                $types = json_decode((string) $row->recipient_types, true);
                $custom = json_decode((string) $row->custom_emails, true);
                $excluded = json_decode((string) $row->excluded_emails, true);
                $excluded = is_array($excluded) ? $excluded : [];

                $emailsAdmins = is_array($types) && in_array('company_admin', $types, true);
                $listsWebmaster = is_array($custom)
                    && in_array(self::WEBMASTER, array_map(fn ($email) => is_scalar($email) ? strtolower(trim((string) $email)) : '', $custom), true);

                $alertsVenueStaff = in_array($row->trigger_type, ['schedule_help_requested', 'checkout_abandoned', 'call_to_book_requested'], true);

                if ((!$emailsAdmins && !$listsWebmaster && !$alertsVenueStaff) || in_array(self::WEBMASTER, $excluded, true)) {
                    return;
                }

                DB::table('email_notifications')->where('id', $row->id)->update([
                    'excluded_emails' => json_encode(array_values([...$excluded, self::WEBMASTER])),
                ]);
            });
    }

    public function down(): void
    {
        if (Schema::hasTable('email_notifications') && Schema::hasColumn('email_notifications', 'excluded_emails')) {
            Schema::table('email_notifications', function (Blueprint $table) {
                $table->dropColumn('excluded_emails');
            });
        }
    }
};
