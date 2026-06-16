<?php

namespace App\Providers\Filament;

use App\Filament\Resources\UserResource;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets;
use Illuminate\Support\HtmlString;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->brandName('Cemilan Qontas')
            ->colors([
                'primary' => Color::Amber,
            ])
            // Anti-flash (FOUC) saat load pertama / cold cache di produksi.
            // Gejala: layout Filament sempat "berantakan/menumpuk" sebelum CSS
            // eksternal ter-apply, lalu normal. Solusi: sembunyikan body sampai
            // SEMUA aset (CSS/JS) selesai di-load (event `load`), baru fade-in.
            //
            // PENTING — fallback agar konten TIDAK pernah hilang permanen:
            //  1. Class `fi-flash-guard` HANYA ditambahkan oleh JS. Kalau JS mati
            //     total, class tak pernah ada → body tetap tampil normal.
            //  2. Reveal dipicu oleh DUA jalur independen: event `load` (jalur
            //     normal) DAN setTimeout 2 detik (jalur darurat). Walau `load`
            //     gantung / aset hang, timeout tetap memunculkan konten.
            //  3. Tidak bergantung pada Alpine sama sekali — murni vanilla JS,
            //     jadi kegagalan Alpine tidak menyembunyikan halaman.
            ->renderHook(
                PanelsRenderHook::HEAD_START,
                fn (): HtmlString => new HtmlString(<<<'HTML'
                    <style>
                        html.fi-flash-guard body { opacity: 0; }
                        body { transition: opacity .15s ease-in; }
                    </style>
                    <script>
                        (function () {
                            var el = document.documentElement;
                            el.classList.add('fi-flash-guard');
                            function reveal() { el.classList.remove('fi-flash-guard'); }
                            window.addEventListener('load', reveal);
                            // Fallback darurat: apa pun yang terjadi, tampilkan konten.
                            setTimeout(reveal, 2000);
                        })();
                    </script>
                    HTML),
            )
            // Admin panel sengaja TIDAK discover semua resource. Super admin cukup
            // mengelola akun (buat owner baru, reset password) lewat UserResource.
            // Resource operasional (Area, Supplier, Kios, Produk, Varian, Operator,
            // Pengadaan) hidup di owner panel /owner-panel, bukan di sini.
            ->resources([
                UserResource::class,
            ])
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            // FilamentInfoWidget (promo "filament v3...") sengaja dibuang — noise di
            // panel produksi. Widget pantau owner di-discover dari app/Filament/Widgets.
            ->widgets([
                Widgets\AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                \App\Http\Middleware\EnsureUserHasRole::class.':owner,super_admin',
            ]);
    }
}
