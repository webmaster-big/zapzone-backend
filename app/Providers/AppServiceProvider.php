<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        Relation::morphMap([
            'booking' => \App\Models\Booking::class,
            'attraction_purchase' => \App\Models\AttractionPurchase::class,
            'event_purchase' => \App\Models\EventPurchase::class,
        ]);

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

        $this->registerCacheInvalidation();
    }

    private function registerCacheInvalidation(): void
    {
        $dashboards = \App\Support\CacheGroups::DASHBOARDS;
        $packages = \App\Support\CacheGroups::PACKAGES;
        $attractions = \App\Support\CacheGroups::ATTRACTIONS;
        $events = \App\Support\CacheGroups::EVENTS;
        $plans = \App\Support\CacheGroups::MEMBERSHIP_PLANS;
        $locations = \App\Support\CacheGroups::LOCATIONS;

        $map = [
            \App\Models\Package::class => [$packages, $locations, $dashboards],
            \App\Models\PackageAvailabilitySchedule::class => [$packages],
            \App\Models\Attraction::class => [$attractions, $dashboards],
            \App\Models\Event::class => [$events, $dashboards],
            \App\Models\MembershipPlan::class => [$plans, $dashboards],
            \App\Models\MembershipPlanBenefit::class => [$plans, $dashboards],
            \App\Models\Location::class => [$packages, $attractions, $events, $plans, $locations, $dashboards],
            \App\Models\SpecialPricing::class => [$packages, $attractions, $events, $dashboards],
            \App\Models\Booking::class => [$dashboards],
            \App\Models\AttractionPurchase::class => [$dashboards],
            \App\Models\EventPurchase::class => [$dashboards],
            \App\Models\Payment::class => [$dashboards],
            \App\Models\Membership::class => [$dashboards],
            \App\Models\MembershipPayment::class => [$dashboards],
            \App\Models\MembershipVisit::class => [$dashboards],
            \App\Models\MembershipBenefitRedemption::class => [$dashboards],
        ];

        foreach ($map as $model => $tags) {
            $flush = fn () => \App\Support\CacheGroups::flush($tags);
            $model::created($flush);
            $model::updated($flush);
            $model::deleted($flush);

            if (in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($model), true)) {
                $model::restored($flush);
                $model::forceDeleted($flush);
            }
        }
    }
}
