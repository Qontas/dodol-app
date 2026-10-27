<?php

namespace App\Filament\Widgets;

use App\Models\User;
use App\Models\Kiosk;
use App\Models\Trip;
use App\Models\Settlement;
use App\Support\TripAggregator;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SuperAdminStatsOverview extends BaseWidget
{
    // Tampil paling atas: ini ringkasan utama super admin, jangan terkubur di bawah.
    protected static ?int $sort = -3;

    public static function canView(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    protected function getStats(): array
    {
        $owners = User::where('role', 'owner')->where('is_active', true)->count();
        $operators = User::where('role', 'operator')->where('is_active', true)->count();

        $kiosks = Kiosk::where('is_active', true)->count();

        $trips = Trip::whereDate('trip_date', today())->count();

        $omset = Settlement::whereDate('visit_date', today())->sum('amount_paid');

        // 🔴 DIPERBAIKI 24 Okt 2026 (BRIEF "Satu Sumber Kebenaran Komisi", fix G) — dulu
        // Commission::whereDate('paid_at', today())->sum('commission_amount'): tabel
        // `commissions` memakai rumus reverse-engineer omset×margin×tarif% (beda &
        // sedikit beda rounding dari komisi_rian snapshot), DAN 'paid_at' = waktu trip
        // DIAKHIRI — bukan 'trip_date' seperti stat "Total Trip Hari Ini" di atas, jadi
        // trip yang mulai hari ini tapi baru diakhiri besok tak pernah masuk hitungan
        // "hari ini". Sekarang: trip NYATA (realOperational, exclude sentinel migrasi)
        // dengan trip_date hari ini — lintas SEMUA owner, aktif ATAU sudah selesai (sama
        // basis "Total Trip Hari Ini" & pola OwnerPerformanceTable::computeStatsByOwner())
        // — SATU query id trip + TripAggregator::for() SATU KALI (jumlah query konstan,
        // bukan N+1 per owner/operator). Fallback tarif 1000 HANYA untuk trip pra-
        // snapshot yang belum di-backfill — trip ber-snapshot baca tarifnya sendiri.
        $tripIdsHariIni = Trip::whereDate('trip_date', today())->realOperational()->pluck('id')->all();
        $komisi = array_sum(array_column(TripAggregator::for($tripIdsHariIni, 1000), 'komisi'));

        // Rata-rata omset per owner aktif (guard pembagian nol).
        $avgOmsetPerOwner = $owners > 0 ? $omset / $owners : 0;

        return [
            Stat::make('Owner & Operator Aktif', $owners + $operators)
                ->description("{$owners} Owner, {$operators} Operator")
                ->icon('heroicon-o-users'),
            Stat::make('Total Kios Terdaftar', $kiosks)
                ->description('Semua kios aktif dari semua owner')
                ->icon('heroicon-o-building-storefront'),
            // BRIEF 27 Okt 2026 "TRIP BERJALAN" — $trips (atas) hitung SEMUA trip trip_date
            // hari ini lintas owner, TERMASUK yang belum diakhiri (ended_at null). Riwayat
            // Trip owner (TripHistoryController::index()) hanya menampilkan trip SELESAI di
            // tabel utamanya (trip berjalan ditampilkan terpisah di atasnya) — caption ini
            // supaya angka di sini tak disalahpahami "trip selesai" saat dibandingkan owner.
            Stat::make('Total Trip Hari Ini', $trips)
                ->description('Semua owner, termasuk yang masih berjalan')
                ->icon('heroicon-o-truck'),
            Stat::make('Total Omset Global Hari Ini', 'Rp ' . number_format($omset, 0, ',', '.'))
                ->icon('heroicon-o-currency-dollar'),
            Stat::make('Rata-rata Omset per Owner Hari Ini', 'Rp ' . number_format($avgOmsetPerOwner, 0, ',', '.'))
                ->description("Dibagi {$owners} owner aktif")
                ->icon('heroicon-o-scale'),
            Stat::make('Total Komisi Operator Global Hari Ini', 'Rp ' . number_format($komisi, 0, ',', '.'))
                ->icon('heroicon-o-gift'),
        ];
    }
}
