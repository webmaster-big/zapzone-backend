<?php

use App\Models\Company;
use App\Models\EmailNotification;
use App\Models\SmsNotification;
use Database\Seeders\DefaultEmailNotificationSeeder;
use Database\Seeders\DefaultSmsNotificationSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Existing companies already have notification defaults, so the runtime
     * ensureDefaultsSeeded() guard never re-seeds them. This backfills the new
     * waiver notification defaults (idempotent — seedForCompany skips keys that
     * already exist) and refreshes the confirmation bodies that still match their
     * seeded default so the {{waiver_section}}/{{waiver_line}} link reaches them.
     * Customized confirmations (body edited by an admin) are left untouched.
     */
    public function up(): void
    {
        if (!Schema::hasTable('companies')) {
            return;
        }

        $hasEmail = Schema::hasTable('email_notifications');
        $hasSms = Schema::hasTable('sms_notifications');

        Company::query()->each(function (Company $company) use ($hasEmail, $hasSms) {
            try {
                if ($hasEmail) {
                    DefaultEmailNotificationSeeder::seedForCompany($company);
                }
                if ($hasSms) {
                    DefaultSmsNotificationSeeder::seedForCompany($company);
                }
            } catch (\Throwable $e) {
                Log::warning('Waiver notification backfill failed for company', [
                    'company_id' => $company->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });

        if ($hasEmail) {
            $this->refreshConfirmationBodies(
                EmailNotification::class,
                DefaultEmailNotificationSeeder::getDefaultDefinitions(),
                [
                    EmailNotification::DEFAULT_BOOKING_CONFIRMATION_CUSTOMER,
                    EmailNotification::DEFAULT_PURCHASE_CONFIRMATION_CUSTOMER,
                    EmailNotification::DEFAULT_EVENT_CONFIRMATION_CUSTOMER,
                ]
            );
        }

        if ($hasSms) {
            $this->refreshConfirmationBodies(
                SmsNotification::class,
                DefaultSmsNotificationSeeder::getDefaultDefinitions(),
                [
                    SmsNotification::DEFAULT_BOOKING_CONFIRMATION_CUSTOMER,
                    SmsNotification::DEFAULT_PURCHASE_CONFIRMATION_CUSTOMER,
                    SmsNotification::DEFAULT_EVENT_CONFIRMATION_CUSTOMER,
                ]
            );
        }
    }

    public function down(): void
    {
        $waiverKeys = [
            'waiver_staff_sent_customer',
            'waiver_reminder_customer',
            'waiver_signed_customer',
            'waiver_bulk_chaperone',
            'waiver_parent_invite',
        ];

        if (Schema::hasTable('email_notifications')) {
            EmailNotification::whereIn('default_key', $waiverKeys)->where('is_default', true)->delete();
        }
        if (Schema::hasTable('sms_notifications')) {
            SmsNotification::whereIn('default_key', $waiverKeys)->where('is_default', true)->delete();
        }
        // Confirmation-body refresh is intentionally not reverted.
    }

    /**
     * For each given default_key, refresh rows whose body still equals their seeded
     * default (i.e. not customized) to the latest seeder body, and always refresh the
     * default_body fallback. Customized rows keep their edited body.
     */
    private function refreshConfirmationBodies(string $model, array $definitions, array $keys): void
    {
        $byKey = collect($definitions)->keyBy('default_key');

        foreach ($keys as $key) {
            $def = $byKey->get($key);
            if (!$def || empty($def['body'])) {
                continue;
            }
            $newBody = $def['body'];

            // rows where the admin hasn't customized: body matches the old default_body
            $model::where('default_key', $key)
                ->where('is_default', true)
                ->whereColumn('body', 'default_body')
                ->update(['body' => $newBody]);

            // always refresh the fallback default_body (used when body is null)
            $model::where('default_key', $key)
                ->where('is_default', true)
                ->update(['default_body' => $newBody]);
        }
    }
};
