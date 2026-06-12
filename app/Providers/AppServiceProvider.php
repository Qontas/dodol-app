<?php

namespace App\Providers;

use App\Http\Responses\Auth\LoginResponse;
use App\Http\Responses\Auth\LogoutResponse;
use App\Models\Delivery;
use App\Models\Settlement;
use App\Observers\DeliveryObserver;
use App\Observers\SettlementObserver;
use Filament\Http\Responses\Auth\Contracts\LoginResponse as LoginResponseContract;
use Filament\Http\Responses\Auth\Contracts\LogoutResponse as LogoutResponseContract;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(LoginResponseContract::class, LoginResponse::class);
        $this->app->bind(LogoutResponseContract::class, LogoutResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Settlement::observe(SettlementObserver::class);
        Delivery::observe(DeliveryObserver::class);

        $this->fixFilamentMapPickerZIndex();
    }

    /**
     * Cegah peta Leaflet (dotswan/filament-map-picker di KioskResource) menutupi
     * sidebar & topbar Filament.
     *
     * Leaflet menempatkan kontrol zoom/atribusi pada z-index 1000, jauh di atas
     * chrome panel — jadi sekadar menaikkan z-index sidebar tidak cukup. Solusi:
     * isolasi peta ke stacking-context-nya sendiri (z-index rendah) supaya z-index
     * internal Leaflet tidak pernah lolos ke atas chrome, lalu kunci chrome di atasnya.
     *
     * CSS ini disuntik lewat render hook karena panel Filament TIDAK memuat
     * resources/css/app.css (tidak ada custom viteTheme), sehingga app.css + npm
     * build tidak akan menyentuh panel.
     */
    private function fixFilamentMapPickerZIndex(): void
    {
        FilamentView::registerRenderHook(
            PanelsRenderHook::HEAD_END,
            fn (): string => <<<'HTML'
                <style>
                    .leaflet-container { position: relative; z-index: 0; isolation: isolate; }
                    .fi-sidebar, .fi-topbar { z-index: 30 !important; }
                </style>
                HTML,
        );
    }
}
