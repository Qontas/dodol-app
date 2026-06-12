<?php

namespace App\Providers\Filament;

use App\Filament\Resources\ClusterResource;
use App\Filament\Resources\KioskResource;
use App\Filament\Resources\OperatorResource;
use App\Filament\Resources\ProcurementBatchResource;
use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductVariantResource;
use App\Filament\Resources\SupplierResource;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Panel khusus Owner di /owner-panel.
 *
 * Resource yang dipakai SAMA dengan admin panel (kelas yang sama, bukan copy).
 * Scoping per-owner sudah ditangani oleh masing-masing Resource via
 * getEloquentQuery() + trait BelongsToOwner (auto-set owner_id saat create),
 * jadi tidak ada scope manual tambahan di sini.
 */
class OwnerPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('owner')
            ->path('owner-panel')
            ->login()
            ->brandName('Cemilan Qontas — Owner')
            ->darkMode(false)
            ->colors([
                'primary' => Color::Amber,
            ])
            ->resources([
                KioskResource::class,
                ClusterResource::class,
                SupplierResource::class,
                ProductResource::class,
                ProductVariantResource::class,
                ProcurementBatchResource::class,
                OperatorResource::class,
            ])
            ->pages([
                \App\Filament\Pages\Dashboard::class,
            ])
            ->navigationItems([
                \Filament\Navigation\NavigationItem::make('Kembali ke Dashboard')
                    ->url(fn() => route('owner.dashboard'))
                    ->icon('heroicon-o-arrow-left')
                    ->sort(-1),
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
                \App\Http\Middleware\EnsureUserHasRole::class.':owner',
            ]);
    }
}
