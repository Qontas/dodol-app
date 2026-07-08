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
use Illuminate\Support\Facades\URL;
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
        // Produksi (Railway di balik proxy HTTPS): paksa skema https agar URL & aset
        // Vite tidak ter-generate http → cegah mixed content / aset gagal load.
        if (config('app.env') === 'production') {
            URL::forceScheme('https');
        }

        Settlement::observe(SettlementObserver::class);
        Delivery::observe(DeliveryObserver::class);

        $this->fixFilamentMapPickerZIndex();
        $this->fixChoicesSelectArrowKeyFocusLeak();
        $this->injectPwaHeadIntoFilament();
    }

    /**
     * Suntik manifest + meta PWA + registrasi service worker ke <head> SEMUA
     * panel Filament (admin & owner-panel) lewat render hook, karena panel
     * Filament tidak memakai layout Blade kustom kita (operator/owner/guest).
     */
    private function injectPwaHeadIntoFilament(): void
    {
        FilamentView::registerRenderHook(
            PanelsRenderHook::HEAD_END,
            fn (): string => view('partials.pwa-head')->render(),
        );
    }

    /**
     * Cegah peta Leaflet (LeafletMapPicker di KioskResource) menutupi
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

    /**
     * Bug upstream Choices.js (dipakai semua Select ->searchable() Filament,
     * termasuk field Area di KioskResource): saat dropdown tertutup dan Esc
     * baru saja memindahkan fokus DOM ke containerOuter (bukan ke search
     * input), _onKeyDown() Choices salah mengira ArrowDown/ArrowUp adalah
     * "printable character" (bug String.fromCharCode(keyCode) — keyCode 40
     * kebetulan sama dengan charCode '('). Karena input-nya tidak fokus,
     * Choices lalu menyisipkan teks mentah "arrowdown"/"arrowup" ke search
     * input via input.value += e.key. Tidak bisa diperbaiki dengan memaksa
     * fokus balik ke search input itu SEBELUM Choices sempat baca kondisinya:
     * search input Choices untuk select-one ada DI DALAM panel dropdown yang
     * display:none saat tertutup, sehingga .focus() ke situ adalah no-op
     * selama dropdown masih tertutup.
     *
     * Fix ini TIDAK menyentuh Choices.js — cukup rekam isi search input
     * SEBELUM event tsb diproses (listener capture-phase di document, jalan
     * lebih dulu drpd listener capture-phase Choices di containerOuter), lalu
     * di tick berikutnya cek: kalau isinya sekarang persis "isi-lama + nama
     * tombol yang ditekan" (tanda tangan bug ini persis), balikin ke isi
     * semula. Navigasi/reopen dropdown dari Choices sendiri tidak disentuh
     * sama sekali (tidak ada preventDefault/stopPropagation).
     */
    private function fixChoicesSelectArrowKeyFocusLeak(): void
    {
        FilamentView::registerRenderHook(
            PanelsRenderHook::HEAD_END,
            fn (): string => <<<'HTML'
                <script>
                    document.addEventListener('keydown', function (e) {
                        if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') {
                            return;
                        }

                        var container = e.target.closest('.choices');

                        if (!container || container.classList.contains('is-open') || container.classList.contains('is-disabled')) {
                            return;
                        }

                        var input = container.querySelector('.choices__input--cloned');

                        if (!input) {
                            return;
                        }

                        var valueBefore = input.value;
                        var pollutedValue = valueBefore + e.key.toLowerCase();

                        setTimeout(function () {
                            if (input.value === pollutedValue) {
                                input.value = valueBefore;
                            }
                        }, 0);
                    }, true);
                </script>
                HTML,
        );
    }
}
