<?php

use App\Models\Company;
use App\Models\EmailNotification;
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
                Log::warning('End of day sales report backfill failed for company', [
                    'company_id' => $company->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('email_notifications')) {
            EmailNotification::where('default_key', EmailNotification::DEFAULT_END_OF_DAY_SALES_REPORT)
                ->where('is_default', true)
                ->delete();
        }
    }
};
