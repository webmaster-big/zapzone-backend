<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\PhotoMessageTemplate;
use Illuminate\Database\Seeder;

class DefaultPhotoMessageTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $companies = Company::all();
        $created = 0;

        foreach ($companies as $company) {
            $created += self::seedForCompany($company);
        }

        $this->command?->info(
            "Default photo message templates seeded for {$companies->count()} companies ({$created} created, existing wording left untouched)."
        );
    }

    /**
     * Idempotent: only writes a template that does not exist yet, so a manager's edited
     * wording is never overwritten by a later deploy.
     */
    public static function seedForCompany(Company $company): int
    {
        $created = 0;

        foreach (PhotoMessageTemplate::defaults() as $kind => $definition) {
            $exists = PhotoMessageTemplate::where('company_id', $company->id)
                ->where('kind', $kind)
                ->exists();

            if ($exists) {
                continue;
            }

            PhotoMessageTemplate::create(array_merge($definition, [
                'company_id' => $company->id,
                'kind' => $kind,
                'is_active' => true,
            ]));

            $created++;
        }

        return $created;
    }
}
