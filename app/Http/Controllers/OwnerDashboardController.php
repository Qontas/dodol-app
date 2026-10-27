<?php

namespace App\Http\Controllers;

use App\Models\Cluster;
use App\Models\Delivery;
use App\Models\Kiosk;
use App\Models\KioskVisit;
use App\Models\ProcurementBatch;
use App\Models\Settlement;
use App\Models\Supplier;
use App\Models\Trip;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class OwnerDashboardController extends Controller
{
    public function index(): View
    {
        $user = auth()->user();

        // Multi-tenant: owner hanya lihat data bisnisnya sendiri.
        $ownerId = $user->id;

        // Settlement Level 2 → owner lewat delivery.kiosk.cluster.owner_id.
        $settlementOwnerScope = fn ($q) => $q->whereHas(
            'delivery.kiosk.cluster',
            fn ($c) => $c->where('owner_id', $ownerId)
        );

        $stats = [
            'total_kios' => Kiosk::excludeWalkInSentinel()->whereHas('cluster', fn ($q) => $q->where('owner_id', $ownerId))->count(),
            'total_cluster' => Cluster::excludeWalkInSentinel()->where('owner_id', $ownerId)->count(),
            'total_supplier' => Supplier::where('owner_id', $ownerId)->count(),
            // realOperators() = AKTIF & bukan akun sistem (fix 23 Okt 2026) — dulu
            // operator nonaktif/akun sistem (operator.migrasi.*) ikut terhitung di sini.
            'total_operator' => $user->realOperators()->count(),
        ];

        // Widget 1 — Omset hari ini: total uang masuk dari settlement hari ini
        // where('visit_date', ...) (bukan whereDate) → tidak membungkus kolom dengan DATE()
        // sehingga index `visit_date` kepakai. visit_date bertipe DATE, jadi hasil identik.
        $omsetHariIni = Settlement::where('visit_date', today()->toDateString())
            ->where($settlementOwnerScope)
            ->sum('amount_paid');

        // Widget 2 — Kios overdue: kios aktif yang lewat target interval kunjungan.
        // Kunjungan terakhir di-batch 1 query (hindari N+1 per kios).
        $ownerKiosks = Kiosk::where('is_active', true)
            ->whereHas('cluster', fn ($q) => $q->where('owner_id', $ownerId))
            ->get();

        $lastVisitPerKiosk = KioskVisit::active()
            ->whereIn('kiosk_id', $ownerKiosks->pluck('id'))
            ->groupBy('kiosk_id')
            ->selectRaw('kiosk_id, MAX(visited_at) as last_visit')
            ->pluck('last_visit', 'kiosk_id');

        $overdueCount = $ownerKiosks
            ->filter(function ($kiosk) use ($lastVisitPerKiosk, $user) {
                $lastVisit = $lastVisitPerKiosk[$kiosk->id] ?? null;

                if (! $lastVisit) {
                    return true; // belum pernah dikunjungi = overdue
                }

                $threshold = $kiosk->target_visit_interval_days ?: $user->getDefaultTargetVisitIntervalDaysValue();

                // abs(): Carbon 3 diffInDays bertanda (tanggal lampau = negatif),
                // tanpa abs() kios yang sudah pernah dikunjungi tak pernah overdue.
                return abs(now()->diffInDays(\Illuminate\Support\Carbon::parse($lastVisit))) > $threshold;
            })
            ->count();

        // Widget 3 — Total outstanding: sisa tagihan dari settlement pending
        $totalOutstanding = Settlement::where('status', 'pending')
            ->where($settlementOwnerScope)
            ->selectRaw('SUM(amount_due - amount_paid) as total')
            ->value('total') ?? 0;

        // Widget — Kerugian titipan bulan ini: dari "Stop Tanpa Tagih" (write-off),
        // di mana seluruh sisa titipan dianggap basi/hilang (uang Rp 0). Nilai modal
        // = mika hilang x HPP PER-BARIS snapshot delivery-nya sendiri (bukan lagi satu
        // HPP flat owner) — join ke deliveries, akumulasi per baris, karena owner bisa
        // punya >1 isi/HPP sekaligus. Fallback HPP owner saat ini untuk baris lolos
        // cost_snapshot NULL (jalur delivery lama sebelum snapshot ada).
        $kerugianRows = Settlement::query()
            ->join('deliveries', 'deliveries.id', '=', 'settlements.delivery_id')
            ->where('settlements.is_writeoff', true)
            ->whereBetween('settlements.visit_date', [today()->startOfMonth(), today()->endOfMonth()])
            ->where($settlementOwnerScope)
            ->get([
                'settlements.qty_returned_expired',
                'deliveries.cost_snapshot',
                'deliveries.units_per_pack_snapshot',
            ]);

        $fallbackHppKerugian = $user->getHppPerMikaValue();
        $kerugianBiji = 0;
        $kerugianMika = 0.0;
        $kerugianNilai = 0.0;
        foreach ($kerugianRows as $row) {
            $biji = (int) $row->qty_returned_expired;
            $units = max(1, (int) ($row->units_per_pack_snapshot ?? 15));
            $cost = $row->cost_snapshot !== null ? (float) $row->cost_snapshot : $fallbackHppKerugian;

            $kerugianBiji += $biji;
            $kerugianMika += $biji / $units;
            $kerugianNilai += ($biji / $units) * $cost;
        }
        $kerugianTitipan = [
            'biji' => $kerugianBiji,
            'mika' => $kerugianMika,
            'nilai' => (int) round($kerugianNilai),
        ];

        // Widget — Untung bersih hari ini: jumlah untung_bersih_owner dari trip
        // milik owner yang sudah selesai hari ini.
        // where('trip_date', ...) (bukan whereDate) → index kepakai; trip_date bertipe DATE.
        // ->with(['operator', 'owner']) WAJIB (ditambahkan 2026-10-23): accessor
        // untung_bersih_owner→komisi_rian→mika_komisi memanggil isMigrationSentinel()
        // (lazy-load 'operator' kalau belum dimuat) DAN untung_kotor→salesSnapshotTotals()
        // →getHpp() (lazy-load 'owner' utk fallback HPP) — tanpa eager load KEDUANYA,
        // ->sum() di atas N trip hari ini menembak 2×N query tambahan (N+1). Eager load
        // membuatnya 2 query batch (1 per relasi), berapa pun banyak trip hari ini (lihat
        // DashboardQueryCountTest::test_eager_load_operator_prevents_n_plus_one_pada_isMigrationSentinel
        // untuk bukti jumlah query). Query per-trip LAIN (settlement/delivery agregat
        // salesSnapshotTotals & getTotalDropReal) tetap ada — itu agregat SQL asli per
        // trip (bukan relation lazy-load), sudah begitu sejak sebelum isMigrationSentinel()
        // ada, di luar lingkup perbaikan N+1 relasi ini.
        $untungBersihHariIni = Trip::where('owner_id', $ownerId)
            ->where('trip_date', today()->toDateString())
            ->whereNotNull('ended_at')
            ->realOperational()
            ->with(['operator', 'owner'])
            ->get()
            ->sum('untung_bersih_owner');

        // Widget — RINGKASAN BULAN INI: omset, untung bersih, komisi operator untuk
        // bulan berjalan (1 s/d hari ini). Rumus & scope IDENTIK totals
        // MonthlyReportController (trip ended, realOperational, tidak diarsip) supaya
        // angka tak pernah beda dua versi. Perbandingan vs bulan lalu murah (agregat
        // yang sama, bulan sebelumnya). Lihat monthlySummary().
        //
        // 🔴 DIPERBAIKI 24 Okt 2026 (BRIEF "Satu Sumber Kebenaran Komisi", fix A) —
        // dulu komisi bulan ini & bulan lalu dihitung SUM(qty_delivered) × tarif owner
        // LIVE ($komisiPerMikaVal) di monthlySummary() lama: begitu owner ganti tarif
        // hari ini, angka BULAN LALU pun ikut berubah (trip lama dihitung ulang pakai
        // tarif baru) — padahal trip itu sudah membeku tarifnya sendiri sejak dimulai
        // (trips.komisi_per_mika_snapshot, lihat Trip::getKomisiPerMika()). Sekarang
        // monthlySummary() delegasi ke TripAggregator::for() yang membaca snapshot
        // PER TRIP (fallback tarif live HANYA untuk trip pra-snapshot yang belum
        // di-backfill) — identik pola yang sudah benar di MonthlyReportController/
        // TripHistoryController/OwnerPerformanceTable. $komisiPerMikaVal di bawah ini
        // kini HANYA dipakai sebagai fallback tersebut, bukan lagi tarif yang menentukan
        // SELURUH komisi bulan ini/lalu.
        $komisiPerMikaVal = $user->getKomisiPerMikaValue();

        $now = today();
        $lastMonth = $now->copy()->subMonthNoOverflow();

        // Trip + TripAggregator bulan ini dihitung SEKALI di sini — dipakai utk
        // ringkasan total ($ringkasanBulanIni) DAN breakdown per operator
        // ($komisiPerOperator) di bawah, supaya tak menembak TripAggregator dua kali
        // untuk trip id yang sama persis.
        $tripsBulanIni = Trip::where('owner_id', $ownerId)
            ->whereNotNull('ended_at')
            ->whereYear('trip_date', $now->year)
            ->whereMonth('trip_date', $now->month)
            ->realOperational()
            ->with('operator')
            ->get(['id', 'operator_id']);

        $aggBulanIni = \App\Support\TripAggregator::for($tripsBulanIni->pluck('id')->all(), $komisiPerMikaVal);

        $ringkasanBulanIni = $this->sumAgg($aggBulanIni);
        $ringkasanBulanLalu = $this->monthlySummary($ownerId, $lastMonth->year, $lastMonth->month, $komisiPerMikaVal);

        // Delta % vs bulan lalu (null kalau bulan lalu 0 → tak ada basis banding).
        $pctDelta = function ($now, $prev) {
            if ($prev == 0) {
                return null;
            }
            return round((($now - $prev) / abs($prev)) * 100, 1);
        };
        $ringkasanDelta = [
            'omset' => $pctDelta($ringkasanBulanIni['omset'], $ringkasanBulanLalu['omset']),
            'untung_bersih' => $pctDelta($ringkasanBulanIni['untung_bersih'], $ringkasanBulanLalu['untung_bersih']),
            'komisi' => $pctDelta($ringkasanBulanIni['komisi'], $ringkasanBulanLalu['komisi']),
        ];

        // Rincian komisi per-operator bulan ini ($tripsBulanIni/$aggBulanIni, dihitung
        // di atas). Ditampilkan hanya kalau owner punya >1 operator — kalau 1, angka
        // total sudah = angka operator itu.
        //
        // 🔴 DIPERBAIKI 24 Okt 2026 (BRIEF "Satu Sumber Kebenaran Komisi", fix B) —
        // dulu SUM(qty_delivered) × tarif owner LIVE ($komisiPerMikaVal), jadi SALAH
        // kalau operator punya tarif KHUSUS (komisi_per_mika_khusus) berbeda dari
        // default owner, dan ikut berubah untuk trip LAMA saat owner ganti tarif hari
        // ini. Sekarang dijumlahkan per operator_id dari $aggBulanIni (TripAggregator,
        // tarif beku per-trip) di PHP, bukan query/rumus baru.
        $komisiPerOperator = $tripsBulanIni
            ->groupBy('operator_id')
            ->map(function ($trips) use ($aggBulanIni) {
                $komisi = 0;
                foreach ($trips as $trip) {
                    $komisi += $aggBulanIni[$trip->id]['komisi'] ?? 0;
                }

                return [
                    'operator' => $trips->first()->operator?->name ?? '—',
                    'komisi' => (int) round($komisi),
                ];
            })
            ->sortByDesc('komisi')
            ->values();

        $bulanIdArr = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        $bulanShortArr = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
        $ringkasanPeriode = sprintf('%s %d (1–%d %s)', $bulanIdArr[$now->month], $now->year, $now->day, $bulanShortArr[$now->month]);

        // Data: sum(amount_paid) per hari 30 hari terakhir
        $startDate = today()->subDays(29);
        $endDate = today();

        $dailyOmsetRaw = Settlement::whereBetween('visit_date', [$startDate, $endDate])
            ->where($settlementOwnerScope)
            ->groupBy('visit_date')
            ->selectRaw('visit_date, SUM(amount_paid) as total_omset')
            ->pluck('total_omset', 'visit_date')
            ->all();

        $chartLabels = [];
        $chartFullDates = [];
        $chartData = [];

        // Label singkat Indonesia "15 Okt" (BRIEF TUGAS 3, 23 Okt 2026) — APP_LOCALE
        // proyek ini 'en' & tak ada Carbon::setLocale(), jadi format('d M') bawaan
        // akan jadi "15 Oct" (Inggris). $bulanShortArr di atas SUDAH ada untuk
        // kebutuhan sama (ringkasan periode) — dipakai ulang di sini, bukan
        // ditulis dobel. chartFullDates (tanggal lengkap) untuk judul tooltip JS.
        for ($date = clone $startDate; $date <= $endDate; $date->addDay()) {
            $formattedDate = $date->format('Y-m-d');
            $chartLabels[] = $date->day.' '.$bulanShortArr[$date->month];
            $chartFullDates[] = $date->day.' '.$bulanIdArr[$date->month].' '.$date->year;
            $chartData[] = (int) ($dailyOmsetRaw[$formattedDate] ?? 0);
        }

        // Active trips real-time progress. Eager load LENGKAP (bukan cuma 4
        // relasi lama) karena koleksi ini SEKARANG dioper langsung ke
        // <livewire:owner.live-trip-progress :active-trips="..."> sebagai
        // render awal (TUGAS 2, BRIEF 23 Okt 2026, lanjutan, rekomendasi
        // audit performa #1) — menghilangkan query duplikat yang dulu
        // dilakukan ulang di dalam komponen itu sendiri. Daftar relasi di
        // sini HARUS sama dengan yang dibutuhkan LiveTripProgress::render(),
        // lihat app/Livewire/Owner/LiveTripProgress.php.
        $activeTrips = Trip::whereNull('ended_at')
            ->where('owner_id', $ownerId)
            ->with([
                'operator',
                'startingCluster',
                'owner',
                'visits.kiosk',
                'visits.newDelivery',
                'visits.settledDelivery.settlement',
                'deliveries',
                // BRIEF 27 Okt 2026 "PENGAMAN STOK DI MOTOR" — dibutuhkan supaya
                // LiveTripProgress bisa hitung stok di motor & "90 + 20 tambahan"
                // dari koleksi yang sudah di-eager-load (lihat docblock di sana),
                // bukan lewat Trip::getStokDiMotor()/mika_dibawa_display (query
                // baru per trip, pecah anti-N+1 widget ini).
                'stockAdditions',
            ])
            ->get();

        // Completed trips for ended trip reports — trip sentinel migrasi dikecualikan
        // (lihat Trip::scopeRealOperational()), supaya tak muncul di widget "5 trip
        // terbaru" saat owner baru punya sedikit trip operasional.
        $completedTrips = Trip::whereNotNull('ended_at')
            ->where('owner_id', $ownerId)
            ->realOperational()
            ->with(['operator', 'startingCluster', 'visits.kiosk', 'deliveries'])
            ->latest('ended_at')
            ->take(5)
            ->get();

        // Agregat finansial completedTrips DIBATCH (bukan accessor per-baris yang N+1).
        // Angka IDENTIK accessor Trip — lihat TripAggregator. Kunci: [trip_id => [...]].
        $completedAgg = \App\Support\TripAggregator::for(
            $completedTrips->pluck('id')->all(),
            $komisiPerMikaVal,
        );

        // Widget stok batch — sisa mika per batch (scoped owner).
        $batchStok = ProcurementBatch::where('owner_id', $ownerId)
            ->with('deliveries')
            ->orderBy('created_at')
            ->get()
            ->map(function ($batch) {
                // Hitung stok SEKALI (pakai koleksi deliveries yang sudah di-eager-load),
                // turunkan flag dari nilai itu — jangan panggil ulang accessor is_habis/
                // is_hampis_habis yang tiap kali menghitung ulang stok. Nilai identik.
                $stok = $batch->stok_tersisa;

                return [
                    'id' => $batch->id,
                    'purchase_date' => $batch->purchase_date,
                    'qty_packs' => $batch->qty_packs,
                    'stok_tersisa' => $stok,
                    'is_habis' => $stok <= 0,
                    'is_hampis_habis' => $stok > 0 && $stok <= 10,
                    'cost_per_pack' => $batch->cost_per_pack,
                ];
            });

        $totalStokTersisa = $batchStok->sum('stok_tersisa');

        // Widget prediksi dodol habis — kios yang dicek sisa bijinya oleh
        // operator (skenario check + sisa biji), max 5 terbaru agar query
        // accessor prediksi tetap terbatas.
        $prediksiKios = $ownerKiosks
            ->load('latestCheckVisit')
            ->filter(fn ($k) => $k->latestCheckVisit && $k->latestCheckVisit->sisa_biji)
            ->sortByDesc(fn ($k) => $k->latestCheckVisit->visited_at)
            ->take(5)
            ->map(fn ($k) => [
                'name' => $k->name,
                'sisa_biji' => (int) $k->latestCheckVisit->sisa_biji,
                'dicek_pada' => $k->latestCheckVisit->visited_at,
                'prediksi' => $k->prediksi_habis,
            ])
            ->values();

        // Widget — Kios berhenti (cut off): kios non-aktif milik owner, max 20
        // terbaru. Kosong → section tidak tampil (sama seperti prediksi habis).
        $stoppedKiosks = Kiosk::excludeWalkInSentinel()
            ->whereHas('cluster', fn ($q) => $q->where('owner_id', $ownerId))
            ->where('is_active', false)
            ->orderByDesc('stopped_at')
            ->take(20)
            ->get()
            ->map(fn ($k) => [
                'id' => $k->id,
                'name' => $k->name,
                'reason' => $k->stop_reason_label ?? '—',
                'stopped_at' => $k->stopped_at,
                'stopped_by' => $k->stopped_by,
            ]);

        // Widget — Titipan Tertunda (Tahap 2): kios aktif yang titipannya ditandai
        // "belum bisa bayar" (Cek Sisa) + janji bayar. Ditampilkan dalam MIKA (barang),
        // BUKAN rupiah karangan — titipan tetap pending & ditagih akurat saat settle
        // kunjungan berikutnya (Realisasi B). Kosong → section tak tampil.
        $ownerKioskIds = $ownerKiosks->pluck('id');
        $pendingByKiosk = Delivery::whereIn('kiosk_id', $ownerKioskIds)
            ->doesntHave('settlement')
            ->get(['id', 'kiosk_id', 'qty_delivered'])
            ->groupBy('kiosk_id');
        $deferredByKiosk = KioskVisit::active()
            ->whereIn('kiosk_id', $ownerKioskIds)
            ->where('alasan_check', 'belum_bisa_bayar')
            ->orderByDesc('visited_at')
            ->get(['kiosk_id', 'visited_at', 'notes'])
            ->groupBy('kiosk_id');

        $titipanTertunda = $ownerKiosks
            ->map(function ($k) use ($pendingByKiosk, $deferredByKiosk) {
                $pendings = $pendingByKiosk->get($k->id);
                $deferred = optional($deferredByKiosk->get($k->id))->first();

                // Hanya kios yang MASIH punya titipan pending DAN ditandai belum-bisa-bayar.
                if (! $pendings || ! $deferred) {
                    return null;
                }

                return [
                    'name' => $k->name,
                    'mika' => (int) $pendings->sum('qty_delivered'),
                    'janji' => $deferred->notes,
                    'sejak' => $deferred->visited_at,
                ];
            })
            ->filter()
            ->sortByDesc('sejak')
            ->take(20)
            ->values();

        // Widget — Belum Bayar PER-KIOS (Tahap 3): rincian piutang (Settlement pending)
        // dikelompokkan per kios + janji bayar (notes). Rupiah (utang pasti), beda dari
        // "Titipan Tertunda" (mika). Owner tahu kios mana berutang berapa.
        $belumBayarPerKios = Settlement::where('status', 'pending')
            ->where($settlementOwnerScope)
            ->with('delivery.kiosk:id,name')
            ->orderBy('id')
            ->get(['id', 'delivery_id', 'amount_due', 'amount_paid', 'notes'])
            ->groupBy(fn ($s) => optional($s->delivery)->kiosk_id)
            ->map(function ($group) {
                $kiosk = optional($group->first()->delivery)->kiosk;

                return [
                    'name' => $kiosk?->name ?? '—',
                    'rupiah' => (int) $group->sum(fn ($s) => (int) $s->amount_due - (int) $s->amount_paid),
                    'janji' => optional($group->filter(fn ($s) => $s->notes)->last())->notes,
                ];
            })
            ->filter(fn ($row) => $row['rupiah'] > 0)
            ->sortByDesc('rupiah')
            ->take(20)
            ->values();

        return view('owner.dashboard', compact(
            'user',
            'stats',
            'omsetHariIni',
            'overdueCount',
            'totalOutstanding',
            'belumBayarPerKios',
            'untungBersihHariIni',
            'chartLabels',
            'chartFullDates',
            'chartData',
            'activeTrips',
            'completedTrips',
            'completedAgg',
            'batchStok',
            'totalStokTersisa',
            'prediksiKios',
            'stoppedKiosks',
            'titipanTertunda',
            'kerugianTitipan',
            'ringkasanBulanIni',
            'ringkasanBulanLalu',
            'ringkasanDelta',
            'ringkasanPeriode',
            'komisiPerOperator'
        ));
    }

    /**
     * Ringkasan finansial satu bulan untuk owner, HANYA dari trip yang SUDAH SELESAI
     * (ended_at), NYATA (realOperational, exclude sentinel migrasi) dan TIDAK DIARSIP
     * (SoftDeletes global scope Trip — query model otomatis exclude deleted_at) — scope
     * IDENTIK totals MonthlyReportController::buildMonthlyData supaya angka tak pernah
     * beda dua versi.
     *
     * 🔴 DIROMBAK 24 Okt 2026 (BRIEF "Satu Sumber Kebenaran Komisi", fix A) — dulu
     * method ini menghitung ULANG omset/untung_kotor per-baris settlement (duplikat
     * Trip::salesSnapshotTotals()) dan komisi = SUM(qty_delivered) × $komisiPerMika
     * (tarif owner LIVE, parameter ini) — trip bulan LALU pun ikut berubah komisinya
     * begitu owner ganti tarif HARI INI, karena tak pernah membaca
     * trips.komisi_per_mika_snapshot. Sekarang: ambil ID trip bulan itu SEKALI, lalu
     * TripAggregator::for() (SATU SUMBER KEBENARAN yang sama dipakai MonthlyReportController/
     * TripHistoryController/OwnerPerformanceTable) — rumusnya membaca tarif BEKU
     * per-trip, $komisiPerMika di sini HANYA fallback untuk trip pra-snapshot yang
     * belum di-backfill.
     */
    private function monthlySummary(int $ownerId, int $year, int $mon, float $komisiPerMika): array
    {
        $tripIds = Trip::where('owner_id', $ownerId)
            ->whereNotNull('ended_at')
            ->whereYear('trip_date', $year)
            ->whereMonth('trip_date', $mon)
            ->realOperational()
            ->pluck('id')
            ->all();

        return $this->sumAgg(\App\Support\TripAggregator::for($tripIds, $komisiPerMika));
    }

    /**
     * Jumlahkan hasil TripAggregator::for() (keyed by trip id) menjadi satu ringkasan
     * bulan — dipakai monthlySummary() (bulan lalu) dan langsung dari $aggBulanIni
     * di index() (bulan ini, lihat komentar di sana) supaya trip id yang sama tak
     * pernah dihitung TripAggregator dua kali.
     */
    private function sumAgg(array $agg): array
    {
        $omset = 0.0;
        $untungKotor = 0.0;
        $komisi = 0.0;
        $untungBersih = 0.0;
        $mikaTerjual = 0.0;
        $dropMika = 0.0;

        foreach ($agg as $row) {
            $omset += $row['omset'];
            $untungKotor += $row['untung_kotor'];
            $komisi += $row['komisi'];
            $untungBersih += $row['untung_bersih'];
            $mikaTerjual += $row['mika_terjual'];
            $dropMika += $row['mika_komisi'];
        }

        return [
            'omset' => $omset,
            'untung_kotor' => $untungKotor,
            'komisi' => $komisi,
            'untung_bersih' => $untungBersih,
            'mika_terjual' => $mikaTerjual,
            'drop_mika' => $dropMika,
        ];
    }

    /**
     * Aktifkan kembali kios yang di-stop (dari section "Kios Berhenti").
     * Reset jejak stop. Otorisasi: kios harus milik owner yang login.
     */
    public function reactivateKiosk(Kiosk $kiosk): RedirectResponse
    {
        abort_unless($kiosk->owner_id === auth()->id(), 403);

        $kiosk->update([
            'is_active' => true,
            'stopped_at' => null,
            'stop_reason' => null,
            'stopped_by' => null,
        ]);

        return back()->with('kiosk_reactivated', "Kios {$kiosk->name} diaktifkan kembali.");
    }
}
