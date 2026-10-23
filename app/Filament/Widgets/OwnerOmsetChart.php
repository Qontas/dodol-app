<?php

namespace App\Filament\Widgets;

use App\Models\Settlement;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

/**
 * TUGAS 3 (BRIEF 23 Okt 2026) — line → BAR (stacked per owner) supaya terbaca
 * di HP & laptop: label tanggal tanpa rotasi + autoSkip, sumbu Y disingkat
 * (rb/jt), tooltip tanggal lengkap + Rp per owner + total, legend kecil di
 * atas, tinggi responsif, teks kosong kalau 30 hari nihil.
 *
 * ⚠️ ANGKA TIDAK BERUBAH — query SUM(amount_paid) di getData() IDENTIK versi
 * line chart sebelumnya (getOptions()/getType()/view saja yang baru, murni
 * tampilan). Lihat view kustom filament.widgets.omset-bar-chart untuk alasan
 * kenapa tinggi responsif & state kosong butuh view sendiri (bukan
 * getMaxHeight() bawaan Filament).
 */
class OwnerOmsetChart extends ChartWidget
{
    protected static ?string $heading = 'Tren Omset 30 Hari — Top 3 Owner';

    protected int | string | array $columnSpan = 'full';

    protected static string $view = 'filament.widgets.omset-bar-chart';

    private const BULAN_SINGKAT = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    private const BULAN_PANJANG = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    public static function canView(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * Teks "Belum ada omset 30 hari terakhir" dipakai view kustom kalau SEMUA
     * owner SEMUA hari nilainya 0 — dibaca dari data yang SAMA (getCachedData,
     * 0 query tambahan), bukan grafik batang rata nol yang membingungkan.
     */
    public function isEmpty(): bool
    {
        foreach ($this->getCachedData()['datasets'] ?? [] as $dataset) {
            foreach ($dataset['data'] ?? [] as $value) {
                if ((float) $value !== 0.0) {
                    return false;
                }
            }
        }

        return true;
    }

    protected function getData(): array
    {
        // Rentang 30 hari terakhir (termasuk hari ini).
        $days = collect(range(0, 29))
            ->map(fn ($i) => Carbon::today()->subDays(29 - $i)->toDateString());

        $start = $days->first();

        // Omset harian per owner = SUM(amount_paid) settlement, ditelusuri
        // settlement → delivery → trip → owner. IDENTIK versi line chart lama
        // — hanya type/options/view yang berubah di bawah.
        $rows = Settlement::query()
            ->join('deliveries', 'settlements.delivery_id', '=', 'deliveries.id')
            ->join('trips', 'deliveries.trip_id', '=', 'trips.id')
            ->join('users', 'trips.owner_id', '=', 'users.id')
            ->whereNull('trips.deleted_at') // join manual → tak kena SoftDeletes global scope
            ->whereDate('settlements.visit_date', '>=', $start)
            ->groupBy('trips.owner_id', 'users.name', 'settlements.visit_date')
            ->selectRaw('trips.owner_id as owner_id, users.name as owner_name, '
                . 'settlements.visit_date as d, SUM(settlements.amount_paid) as total')
            ->get();

        // Top 3 owner berdasarkan total omset 30 hari.
        $topOwners = $rows->groupBy('owner_id')
            ->map(fn ($g) => [
                'name' => $g->first()->owner_name,
                'total' => $g->sum('total'),
            ])
            ->sortByDesc('total')
            ->take(3);

        // Lookup cepat: "ownerId|tanggal" => total.
        $lookup = $rows->keyBy(fn ($r) => $r->owner_id . '|' . $r->d);

        // Warna SOLID per owner (bar) — palet sama dengan versi line chart lama
        // (border color dipakai sebagai backgroundColor bar).
        $palette = ['#d97706', '#2563eb', '#059669'];

        $datasets = [];
        $i = 0;
        foreach ($topOwners as $ownerId => $info) {
            $datasets[] = [
                'label' => $info['name'],
                'data' => $days->map(fn ($d) => (float) ($lookup[$ownerId . '|' . $d]->total ?? 0))->all(),
                'backgroundColor' => $palette[$i] ?? '#64748b',
            ];
            $i++;
        }

        return [
            'datasets' => $datasets,
            'labels' => $days->map(fn ($d) => (int) Carbon::parse($d)->day . ' ' . self::BULAN_SINGKAT[(int) Carbon::parse($d)->month])->all(),
            // Tanggal LENGKAP untuk judul tooltip — dibaca lewat chart.data.fullDates
            // di options JS (lihat getOptions()); Chart.js meneruskan properti apa
            // pun yang ditempel di objek `data`, tak cuma labels/datasets.
            'fullDates' => $days->map(fn ($d) => (int) Carbon::parse($d)->day . ' ' . self::BULAN_PANJANG[(int) Carbon::parse($d)->month] . ' ' . Carbon::parse($d)->year)->all(),
        ];
    }

    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
            {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                scales: {
                    x: {
                        stacked: true,
                        grid: { display: false },
                        ticks: {
                            maxRotation: 0,
                            minRotation: 0,
                            autoSkip: true,
                            maxTicksLimit: window.matchMedia('(max-width: 639px)').matches ? 6 : 10,
                        },
                    },
                    y: {
                        stacked: true,
                        beginAtZero: true,
                        ticks: {
                            maxTicksLimit: 4,
                            callback: function (value) {
                                value = Number(value) || 0;
                                var abs = Math.abs(value);
                                if (abs >= 1000000) {
                                    return (value / 1000000).toLocaleString('id-ID', { maximumFractionDigits: 1 }) + 'jt';
                                }
                                if (abs >= 1000) {
                                    return (value / 1000).toLocaleString('id-ID', { maximumFractionDigits: 0 }) + 'rb';
                                }
                                return value.toLocaleString('id-ID');
                            },
                        },
                    },
                },
                plugins: {
                    legend: {
                        position: 'top',
                        labels: { boxWidth: 10, font: { size: 11 } },
                    },
                    tooltip: {
                        callbacks: {
                            title: function (items) {
                                var chart = items[0].chart;
                                return chart.data.fullDates[items[0].dataIndex];
                            },
                            label: function (context) {
                                var value = Math.round(Number(context.parsed.y) || 0);
                                return context.dataset.label + ': Rp ' + value.toLocaleString('id-ID');
                            },
                            footer: function (items) {
                                var total = items.reduce(function (sum, item) {
                                    return sum + (Number(item.parsed.y) || 0);
                                }, 0);
                                return 'Total: Rp ' + Math.round(total).toLocaleString('id-ID');
                            },
                        },
                        footerFont: { weight: 'bold' },
                    },
                },
            }
            JS);
    }
}
