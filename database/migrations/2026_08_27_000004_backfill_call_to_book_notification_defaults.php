<?php

use App\Models\Company;
use Database\Seeders\DefaultEmailNotificationSeeder;
use Database\Seeders\DefaultSmsNotificationSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
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
                Log::warning('Call-to-book notification backfill failed for company', [
                    'company_id' => $company->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    public function down(): void
    {
        $keys = ['call_to_book_staff'];

        if (Schema::hasTable('email_notifications')) {
            \App\Models\EmailNotification::whereIn('default_key', $keys)->where('is_default', true)->delete();
        }
        if (Schema::hasTable('sms_notifications')) {
            \App\Models\SmsNotification::whereIn('default_key', $keys)->where('is_default', true)->delete();
        }
    }
};
