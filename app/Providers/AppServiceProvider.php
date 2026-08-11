<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        $this->registerPhotoRateLimiters();
    }

    /**
     * Named limiters for the public photo routes.
     *
     * The string form of the throttle middleware ("throttle:120,1") keys unauthenticated
     * requests as sha1(domain|ip) — ONE counter shared by every route on the domain. At a
     * venue, the slideshow polls its feed and streams images from the same public IP as the
     * kiosk, so the display quietly spent the kiosk's allowance and the kiosk answered
     * "Too Many Attempts" when a customer pressed Start.
     *
     * Each purpose below gets its own key, and it includes the location, so one device can
     * never starve another and one venue can never affect a different venue.
     */
    private function registerPhotoRateLimiters(): void
    {
        $key = function (string $purpose, Request $request): string {
            $location = $request->route('locationId') ?? 'na';

            return 'photo:' . $purpose . ':' . $location . ':' . $request->ip();
        };

        // Passcode entry. Tight enough to stop guessing, loose enough for real typing.
        RateLimiter::for('photo-unlock', fn (Request $request) => Limit::perMinute(12)->by($key('unlock', $request)));

        // Kiosk session traffic: start, capture, retake, accept, timeout, context.
        RateLimiter::for('photo-kiosk', fn (Request $request) => Limit::perMinute(180)->by($key('kiosk', $request)));

        // The display polls this every 10 seconds.
        RateLimiter::for('photo-slideshow', fn (Request $request) => Limit::perMinute(120)->by($key('slideshow', $request)));

        // Customer photo pages and QR scans, keyed per IP only.
        RateLimiter::for('photo-customer', fn (Request $request) => Limit::perMinute(120)->by($key('customer', $request)));

        // Image streaming: a rotating slideshow plus several phones downloading at once.
        RateLimiter::for('photo-media', fn (Request $request) => Limit::perMinute(1200)->by($key('media', $request)));
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
            \App\Models\Waiver::class => [$dashboards],
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
