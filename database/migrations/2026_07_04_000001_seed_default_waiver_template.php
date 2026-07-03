<?php

use App\Models\Company;
use Database\Seeders\DefaultWaiverTemplateSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Seed one default draft waiver template for every existing company.
     * New companies receive it automatically via the same seeder at registration.
     * Idempotent — DefaultWaiverTemplateSeeder::seedForCompany() skips companies
     * that already have a template with the default key.
     */
    public function up(): void
    {
        if (!Schema::hasTable('waiver_templates') || !Schema::hasTable('companies')) {
            return;
        }

        Company::query()->each(function (Company $company) {
            try {
                DefaultWaiverTemplateSeeder::seedForCompany($company);
            } catch (\Throwable $e) {
                Log::warning('Waiver template backfill failed for company', [
                    'company_id' => $company->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Remove only the system-generated default templates, never admin-created ones.
        if (!Schema::hasTable('waiver_templates')) {
            return;
        }

        \App\Models\WaiverTemplate::where('internal_description', 'like', '%' . DefaultWaiverTemplateSeeder::DEFAULT_KEY . '%')
            ->each(function ($template) {
                \App\Models\WaiverTemplateVersion::where('waiver_template_id', $template->id)->delete();
                $template->forceDelete();
            });
    }
};
