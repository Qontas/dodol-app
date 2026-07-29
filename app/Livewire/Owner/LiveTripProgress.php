<?php

namespace App\Livewire\Owner;

use Livewire\Component;
use App\Models\Trip;
use App\Models\Kiosk;
use App\Support\TripAreas;

class LiveTripProgress extends Component
{
    /**
     * Komponen ini di-poll tiap 30 detik dari dashboard owner, jadi semua
     * angka per-trip dihitung di sini dengan eager load — bukan lewat accessor
     * finansial Trip (yang query sendiri-sendiri) atau query Kiosk di blade.
     */
    public function render()
    {
        // Multi-tenant: owner hanya lihat trip bisnisnya sendiri.
        $activeTrips = Trip::whereNull('ended_at')
            ->where('owner_id', auth()->id())
            ->with([
                'operator',
                'startingCluster',
                'owner',
                'visits.kiosk',
                'visits.newDelivery',
                'visits.settledDelivery.settlement',
                'deliveries',
            ])
            ->get();

        // Kios aktif milik owner: 1 query untuk semua trip.
        $ownerKiosks = Kiosk::where('is_active', true)
            ->whereHas('cluster', fn ($q) => $q->where('owner_id', auth()->id()))
            ->get(['id', 'cluster_id', 'name']);

        // Area yang RELEVAN per trip (area awal + area yang sudah disentuh). 2 query
        // batch untuk semua trip sekaligus — bukan per-baris.
        $areas = TripAreas::for($activeTrips->pluck('id')->all());

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
            // delivery: visit extension bisa menunjuk delivery yang sama.
            $settlements = $trip->visits
                ->filter(fn ($v) => $v->settledDelivery?->settlement)
                ->unique('settled_delivery_id')
                ->map(fn ($v) => $v->settledDelivery->settlement);

            $omset = (float) $settlements->sum('amount_paid');
            $mikaTerjual = $settlements->sum('qty_sold') / 15;

            // Komisi berjalan (Opsi Y — basis DROP): tarif x mika yang DILETAKKAN,
            // exclude BS daur-ulang. Sama dengan accessor Trip::komisi_rian /
            // getTotalDropReal(), dihitung dari deliveries yang sudah di-eager-load.
            $komisiPerMika = (float) ($trip->owner?->komisi_per_mika ?? 1000);
            $mikaKomisi = (float) $trip->deliveries
                ->where('delivery_type', '!=', 'bs_redistribution')
                ->sum('qty_delivered');

            $komisi = $mikaKomisi * $komisiPerMika;

            $visitedIds = $trip->visits->pluck('kiosk_id')->all();

            $tripStats[$trip->id] = [
                'area_label' => $area['label'] ?? ($trip->startingCluster->name ?? 'Semua Kios'),
                'area_crossed' => $area['crossed'] ?? false,
                'total_kios' => $kiosksForTrip->count(),
                'unvisited_kiosks' => $kiosksForTrip->reject(fn ($k) => in_array($k->id, $visitedIds, true))->values(),
                'omset' => $omset,
                'komisi' => $komisi,
                'sorted_visits' => $trip->visits->sortByDesc('visited_at')->values(),
            ];
        }

        return view('livewire.owner.live-trip-progress', compact('activeTrips', 'tripStats'));
    }
}
