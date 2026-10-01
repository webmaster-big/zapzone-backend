<?php

use App\Models\Company;
use Database\Seeders\DefaultEmailNotificationSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('companies') || !Schema::hasTable('email_notifications')) {
            return;
        }

        Company::query()->each(function (Company $company) {
            try {
                DefaultEmailNotificationSeeder::seedForCompany($company);
            } catch (\Throwable $e) {
                Log::warning('Visit follow-up email backfill failed for company', [
                    'company_id' => $company->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('email_notifications')) {
            \App\Models\EmailNotification::whereIn('default_key', ['thanks_for_playing_customer', 'review_request_customer'])
                ->where('is_default', true)
                ->delete();
        }
    }
};
