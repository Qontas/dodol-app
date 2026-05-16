<?php

namespace App\Providers;

use App\Models\Delivery;
use App\Models\Settlement;
use App\Observers\DeliveryObserver;
use App\Observers\SettlementObserver;
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
        Settlement::observe(SettlementObserver::class);
        Delivery::observe(DeliveryObserver::class);
    }
}
