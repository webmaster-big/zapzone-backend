<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Relation::morphMap([
            'booking' => \App\Models\Booking::class,
            'attraction_purchase' => \App\Models\AttractionPurchase::class,
            'event_purchase' => \App\Models\EventPurchase::class,
        ]);

        // Auto-seed default email notifications when a new company is created.
        \App\Models\Company::created(function (\App\Models\Company $company) {
            try {
                \Database\Seeders\DefaultEmailNotificationSeeder::seedForCompany($company);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error(
                    'Failed to seed default email notifications for new company',
                    ['company_id' => $company->id, 'error' => $e->getMessage()]
                );
            }
        });
    }
}
