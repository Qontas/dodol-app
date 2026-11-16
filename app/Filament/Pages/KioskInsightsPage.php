<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

/**
 * "Laporan Kedai" untuk SUPER ADMIN di panel /admin. Komponen Livewire yang sama dengan halaman owner
 * (App\Livewire\Owner\KioskInsightDownloads) disematkan di sini, sehingga super admin tidak melihat sidebar owner.
 * Owner yang membuka /admin/laporan-kedai ditolak (canAccess) — owner memakai /kiosk-insights.
 */
class KioskInsightsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $navigationLabel = 'Laporan Kedai';

    protected static ?string $title = 'Laporan Kedai';

    protected static ?string $slug = 'laporan-kedai';

    // Tepat setelah "Invoice Fee" (sort 5).
    protected static ?int $navigationSort = 6;

    protected static string $view = 'filament.pages.kiosk-insights';

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }
}
