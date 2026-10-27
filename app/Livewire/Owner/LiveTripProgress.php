<?php

namespace App\Livewire\Owner;

use Livewire\Component;
use App\Models\Trip;
use App\Models\Kiosk;
use App\Models\KioskVisit;
use App\Support\TripAreas;
use Illuminate\Support\Collection;

class LiveTripProgress extends Component
{
    /**
     * TUGAS 2 (BRIEF 23 Okt 2026, lanjutan, rekomendasi audit performa #1) —
     * OwnerDashboardController SUDAH menghitung $activeTrips dengan eager
     * load yang sama persis untuk widget lain di halaman yang sama; render
     * awal komponen ini dulu meng-query ULANG trip aktif (duplikat). Sekarang
     * controller mengoper koleksi itu lewat mount(), dipakai HANYA untuk
     * render pertama.
     *
     * SENGAJA protected (bukan public): properti public di Livewire 3
     * ikut di-hydrate dari snapshot di SETIAP request susulan (termasuk
     * poll 30 detik), jadi kalau public, data trip aktif bisa jadi basi
     * (poll tak pernah lihat trip baru/berakhir). Properti protected TIDAK
     * ikut snapshot — mount() hanya jalan sekali di render awal, jadi di
     * setiap poll berikutnya nilainya otomatis balik null → render()
     * jatuh ke query ulang di bawah, PERSIS perilaku lama.
     */
    protected ?Collection $preloadedActiveTrips = null;

    /**
     * 🔴 $activeTrips SENGAJA TANPA type-hint `?Collection` di parameter ini
     * (beda dari properti di atas) — Livewire mem-resolve parameter mount()
     * yang tak terisi lewat Laravel container (`Container::call()`), dan
     * `Illuminate\Support\Collection` BISA di-instantiate tanpa argumen.
     * Kalau di-type-hint, container diam-diam menyuntik Collection KOSONG
     * ganti null waktu parameter tak dioper (mis. test `Livewire::test()`
     * tanpa mount params) — default `= null` di signature TAK PERNAH
     * kepakai, dan `??` di render() di bawah jadi tak pernah fallback ke
     * query. Tanpa type-hint, container tak bisa resolve apa-apa untuk
     * parameter ini, jadi default null benar-benar terpakai saat kosong.
     */
    public function mount($activeTrips = null): void
    {
        $this->preloadedActiveTrips = $activeTrips;
    }

    /**
     * Komponen ini di-poll tiap 30 detik dari dashboard owner, jadi semua
     * angka per-trip dihitung di sini dengan eager load — bukan lewat accessor
     * finansial Trip (yang query sendiri-sendiri) atau query Kiosk di blade.
     */
    public function render()
    {
        // Multi-tenant: owner hanya lihat trip bisnisnya sendiri. Render awal
        // pakai koleksi dari controller (lihat docblock $preloadedActiveTrips
        // di atas); setiap poll susulan query ulang di sini seperti biasa.
        //
        // BRIEF 27 Okt 2026 "TRIP LUPA DIAKHIRI" item 4 — whereDate('trip_date',
        // today()) HARUS SAMA dengan query OwnerDashboardController (lihat
        // docblock $preloadedActiveTrips) — tanpa ini, poll 30 detik susulan
        // akan kembali menampilkan trip lama tanpa tanggal begitu
        // $preloadedActiveTrips habis (null lagi setelah poll pertama).
        $activeTrips = $this->preloadedActiveTrips ?? Trip::whereNull('ended_at')
            ->where('owner_id', auth()->id())
            ->whereDate('trip_date', today())
            ->with([
                'operator',
                'startingCluster',
                'owner',
                'visits.kiosk',
                'visits.newDelivery',
                'visits.settledDelivery.settlement',
                'deliveries',
                'stockAdditions',
            ])
            ->get();

        // Kios aktif milik owner: 1 query untuk semua trip.
        $ownerKiosks = Kiosk::where('is_active', true)
            ->whereHas('cluster', fn ($q) => $q->where('owner_id', auth()->id()))
            ->get(['id', 'cluster_id', 'name']);

        // Area yang RELEVAN per trip (area awal + area yang sudah disentuh). 2 query
        // batch untuk semua trip sekaligus — bukan per-baris.
        $areas = TripAreas::for($activeTrips->pluck('id')->all());

        // 🔴 Kios yang sudah dikunjungi HARI INI oleh TRIP MANAPUN milik owner ini —
        // termasuk trip operator lain, atau trip yang sudah ditutup hari ini (tidak
        // ikut $activeTrips). 1 query batch dipakai bersama SEMUA trip aktif di bawah
        // (bukan per-trip) supaya "belum dikunjungi" tak menghitung kios yang
        // sebenarnya sudah didatangi operator lain pagi ini — cegah kesan kunjungan
        // dobel lintas operator di dashboard owner (18 Okt 2026, sama prinsipnya
        // dengan guard ActiveTrip::pendingDeliveryBlocksSettleToday()).
        $visitedTodayByAnyTripKioskIds = KioskVisit::active()
            ->whereDate('visited_at', today())
            ->whereHas('trip', fn ($q) => $q->where('owner_id', auth()->id()))
            ->pluck('kiosk_id')
            ->unique()
            ->all();

        $tripStats = [];
        foreach ($activeTrips as $trip) {
            $area = $areas[$trip->id] ?? null;

            // 🔴 DENOMINATOR "sudah visit X dari Y kios" (fix 29 Juli 2026).
            //
            // Dulu: kios di `starting_cluster_id` saja. Sejak operator boleh
            // menyeberang area di tengah trip, angka itu jadi bohong dua arah —
            // pembilang bisa melampaui penyebut ("6 dari 5 kios").
            //
            // Sekarang: kios di area AWAL + SETIAP area yang sudah benar-benar
            // disentuh trip ini. Dipilih karena paling tidak membingungkan owner:
            // selama operator masih di satu area, angkanya PERSIS seperti dulu
            // ("5 dari 54"); begitu ia menyeberang, target tumbuh hanya sebesar
            // area yang memang sudah ia masuki — tak pernah melompat ke seluruh
            // kios bisnis (yang akan membuat progres terlihat runtuh tanpa sebab).
            $relevant = $area['relevant_cluster_ids'] ?? [];

            $kiosksForTrip = $trip->starting_cluster_id
                ? $ownerKiosks->whereIn('cluster_id', $relevant ?: [$trip->starting_cluster_id])
                : $ownerKiosks; // Trip Bebas memang menyasar semua kios — tak berubah.

            // Settlement titipan yang di-settle pada trip ini. unique() per
            // delivery: visit extension bisa menunjuk delivery yang sama. Delivery
            // dibawa SERTA settlement (bukan cuma settlement sendiri) karena
            // mika_terjual per baris butuh units_per_pack_snapshot MILIK delivery itu —
            // bukan konstanta 15 global (sudah di-eager-load, tanpa query baru).
            $settledPairs = $trip->visits
                ->filter(fn ($v) => $v->settledDelivery?->settlement)
                ->unique('settled_delivery_id')
                ->map(fn ($v) => [
                    'settlement' => $v->settledDelivery->settlement,
                    'delivery' => $v->settledDelivery,
                ]);

            $omset = (float) $settledPairs->sum(fn ($p) => (float) $p['settlement']->amount_paid);
            $mikaTerjual = (float) $settledPairs->sum(function ($p) {
                $units = max(1, (int) ($p['delivery']->units_per_pack_snapshot ?? 15));

                return ((float) $p['settlement']->qty_sold) / $units;
            });

            // Komisi berjalan (Opsi Y — basis DROP): tarif x mika yang DILETAKKAN,
            // exclude BS daur-ulang. Sama dengan accessor Trip::komisi_rian /
            // getTotalDropReal(), dihitung dari deliveries yang sudah di-eager-load.
            //
            // 🔴 DIPERBAIKI 24 Okt 2026 (BRIEF "Satu Sumber Kebenaran Komisi", fix C) —
            // dulu $trip->owner?->komisi_per_mika (tarif owner LIVE), mengabaikan
            // trips.komisi_per_mika_snapshot DAN tarif khusus operator — trip aktif milik
            // operator bertarif khusus salah, dan angka ikut melompat kalau owner ganti
            // tarif SAAT trip masih berjalan. Baris di bawah ini IDENTIK (bukan hasil
            // panggilan, Trip::getKomisiPerMika() private) Trip::getKomisiPerMika().
            $komisiPerMika = (float) ($trip->komisi_per_mika_snapshot ?? $trip->owner?->komisi_per_mika ?? 1000);
            $mikaKomisi = (float) $trip->deliveries
                ->where('delivery_type', '!=', 'bs_redistribution')
                ->sum('qty_delivered');

            $komisi = $mikaKomisi * $komisiPerMika;

            // Stok di motor & "Mika Dibawa" (BRIEF 27 Okt 2026 "PENGAMAN STOK DI
            // MOTOR") — formula IDENTIK Trip::getStokDiMotor() / getMikaDibawaDisplayAttribute(),
            // tapi dihitung dari koleksi yang SUDAH di-eager-load ($trip->deliveries,
            // $trip->stockAdditions) bukan lewat accessor Trip (yang query sendiri-
            // sendiri lewat relation method ->deliveries()/->stockAdditions(), persis
            // alasan $mikaKomisi di atas tidak memakai accessor Trip).
            $totalDropReal = (int) $trip->deliveries
                ->where('delivery_type', '!=', 'bs_redistribution')
                ->sum('qty_delivered');
            $qtyAddedTotal = (int) $trip->stockAdditions->sum('qty');
            $qtyAwal = (int) ($trip->qty_carried_total ?? 0);
            $qtyCarriedEffective = $qtyAwal + $qtyAddedTotal;
            $stokDiMotor = $qtyCarriedEffective - $totalDropReal;
            $mikaDibawaDisplay = $qtyAddedTotal <= 0
                ? (string) $qtyAwal
                : "{$qtyAwal} + {$qtyAddedTotal} tambahan = {$qtyCarriedEffective}";

            // Visited sendiri + visited HARI INI oleh trip lain (lintas operator) —
            // digabung di sini, bukan dua array terpisah, supaya pemakai di bawah
            // (unvisited_kiosks) tak perlu tahu soal cross-operator sama sekali.
            $visitedIds = array_values(array_unique(array_merge(
                $trip->visits->pluck('kiosk_id')->all(),
                $visitedTodayByAnyTripKioskIds,
            )));

            $tripStats[$trip->id] = [
                'area_label' => $area['label'] ?? ($trip->startingCluster->name ?? 'Semua Kios'),
                'area_crossed' => $area['crossed'] ?? false,
                'total_kios' => $kiosksForTrip->count(),
                'unvisited_kiosks' => $kiosksForTrip->reject(fn ($k) => in_array($k->id, $visitedIds, true))->values(),
                'omset' => $omset,
                'mika_terjual' => $mikaTerjual,
                'komisi' => $komisi,
                'stok_di_motor' => $stokDiMotor,
                'mika_dibawa_display' => $mikaDibawaDisplay,
                'sorted_visits' => $trip->visits->sortByDesc('visited_at')->values(),
            ];
        }

        return view('livewire.owner.live-trip-progress', compact('activeTrips', 'tripStats'));
    }
}
