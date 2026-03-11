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
    }
}
