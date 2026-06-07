# Brief: List Kios Per Cluster di Active Trip

## KONTEKS

45 PASS. Core trip flow: operator belum bisa lihat daftar kios yang harus dikunjungi.
ActiveTrip page sekarang cuma tampil skeleton "Coming Soon".
Tugas: tampilkan list kios per cluster starting_cluster, dengan status visited/unvisited.

## BUSINESS RULES (LOCKED)

- List kios = kios aktif (is_active=true) di cluster starting_cluster trip
- Status per kios:
    - BELUM DIKUNJUNGI = belum ada kiosk_visit untuk trip ini + kiosk_id ini
    - SUDAH DIKUNJUNGI = ada kiosk_visit WHERE trip_id = trip.id AND kiosk_id = kiosk.id
- Urutan default: kios yang belum dikunjungi dulu, sudah dikunjungi di bawah
- Tap kios = buka modal visit (sudah ada: openVisitModal($kioskId))
- Kios sudah dikunjungi = tidak bisa di-tap lagi (disabled/greyed out)
- Tampilkan: nama kios, nama pemilik, status visited, ada pending delivery atau tidak

## SCHEMA (verified)

kiosks: id, name, owner_name, cluster_id, is_active, latitude, longitude, photo_path, default_qty_mika
kiosk_visits: id, trip_id, kiosk_id, visited_at, visit_action, extension_granted
trips: id, operator_id, starting_cluster_id, started_at, ended_at, qty_carried_total

## EXISTING STATE

ActiveTrip.php sudah punya:

- $trip, $kiosks (array), $startingCluster
- loadKiosks() method (cek apakah sudah load per cluster atau belum)
- openVisitModal($kioskId) — sudah bisa dipanggil dari view

Cek dulu:

- app/Livewire/Operator/ActiveTrip.php (full — fokus mount() + loadKiosks() + properties)
- resources/views/livewire/operator/active-trip.blade.php (section list kios)

## STEP EKSEKUSI

### Step 1: Audit ActiveTrip existing

Baca ActiveTrip.php + view. Identifikasi:

- Apakah $kiosks sudah di-load dari DB atau masih kosong?
- Apakah loadKiosks() sudah ada dan query apa?
- Section "Coming Soon" ada di mana di view?

### Step 2: Update loadKiosks() di ActiveTrip.php

Target: load kios aktif dari starting_cluster + attach visited status.

```php
public function loadKiosks(): void
{
    if (!$this->trip || !$this->trip->starting_cluster_id) {
        $this->kiosks = [];
        return;
    }

    $visitedKioskIds = KioskVisit::where('trip_id', $this->trip->id)
        ->pluck('kiosk_id')
        ->toArray();

    $this->kiosks = Kiosk::where('cluster_id', $this->trip->starting_cluster_id)
        ->where('is_active', true)
        ->get()
        ->map(function ($kiosk) use ($visitedKioskIds) {
            $kiosk->is_visited = in_array($kiosk->id, $visitedKioskIds);
            $kiosk->has_pending = \App\Models\Delivery::where('kiosk_id', $kiosk->id)
                ->doesntHave('settlement')
                ->exists();
            return $kiosk;
        })
        ->sortBy('is_visited') // belum dikunjungi dulu
        ->values()
        ->toArray();
}
```

Panggil loadKiosks() di mount() setelah load trip.

### Step 3: Update view active-trip.blade.php

Ganti section "Coming Soon" dengan list kios actual.

Layout per kios card:

- Nama kios (bold)
- Nama pemilik (small, slate-500)
- Badge status: "Sudah Dikunjungi" (hijau) atau "Belum" (amber)
- Badge "Ada Titipan" kalau has_pending = true
- Tap card → openVisitModal($kios['id']) kalau belum dikunjungi
- Kalau sudah dikunjungi: card greyed out, tidak bisa di-tap

Pattern card:

```html
@forelse($kiosks as $kiosk)
<div wire:key="kiosk-{{ $kiosk['id'] }}"
     @if(!$kiosk['is_visited']) wire:click="openVisitModal({{ $kiosk['id'] }})" @endif
     class="bg-white rounded-xl border p-4 flex items-center justify-between
            {{ $kiosk['is_visited'] ? 'opacity-50 cursor-default' : 'cursor-pointer active:bg-slate-50 hover:border-amber-300' }}">
    <div>
        <p class="font-bold text-slate-900">{{ $kiosk['name'] }}</p>
        <p class="text-sm text-slate-500">{{ $kiosk['owner_name'] }}</p>
        <div class="flex gap-2 mt-1">
            @if($kiosk['is_visited'])
                <span class="text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded-full">✓ Dikunjungi</span>
            @else
                <span class="text-xs bg-amber-100 text-amber-700 px-2 py-0.5 rounded-full">Belum</span>
            @endif
            @if($kiosk['has_pending'])
                <span class="text-xs bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full">Ada Titipan</span>
            @endif
        </div>
    </div>
    @if(!$kiosk['is_visited'])
        <svg class="h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
        </svg>
    @endif
</div>
@empty
<div class="text-center py-8 text-slate-400">
    <p>Belum ada kios di cluster ini.</p>
    <p class="text-sm mt-1">Tambah kios dulu via menu Kios Baru.</p>
</div>
@endforelse
```

### Step 4: Update saveVisit() — refresh kiosks setelah save

Setelah transaction sukses di saveVisit(), pastikan loadKiosks() dipanggil:
$this->loadKiosks(); // sudah ada? verify

### Step 5: php artisan test --compact (target 45+ PASS)

### Step 6: Commit

git add app/Livewire/Operator/ActiveTrip.php resources/views/livewire/operator/active-trip.blade.php
git commit -m "feat(operator): list kios per cluster di active trip — visited status + tap to visit"

git push origin main

## STOP POINTS

1. loadKiosks() sudah ada tapi query berbeda dari brief
2. $kiosks property tipe berbeda (Collection vs array)
3. "Coming Soon" section tidak ditemukan di view
4. Test turun dari 45 PASS

JANGAN auto-decide. Lapor dulu.

Output: ringkas per step + test status. No narasi panjang.

Mulai sekarang.

--- END OF BRIEF ---
