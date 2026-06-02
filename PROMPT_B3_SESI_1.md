# Day 5 Sesi 8 — B3 Trip Flow Foundation (Sesi 1)

## KONTEKS

- Day 5 Sesi 7 closed (commit 59de1fd) — ProcurementBatch Resource ready
- 7 Filament Resources complete di /admin
- Sekarang start operator workflow CUSTOM (bukan Filament) — pakai Livewire
- Sesi 1 ini = FOUNDATION Trip Flow, BUKAN complete workflow

## CRITICAL: SCHEMA AWARENESS

Berdasarkan schema audit yang baru di-confirm:

**`trips` table fields (Day 2 ERD v1 LOCKED):**

- id, trip_date (date), trip_number_of_day (smallint default 1), operator_id (FK users), started_at (timestamp nullable), ended_at (timestamp nullable), qty_carried_total (smallint default 0), notes (text nullable), timestamps
- UNIQUE constraint: (trip_date, trip_number_of_day)

**KEY FACTS:**

- Trip TIDAK punya cluster_id (multi-cluster trip support via kiosk_visits.kiosk.cluster chain)
- Trip TIDAK punya status enum (active = started_at IS NOT NULL AND ended_at IS NULL)
- Trip identifier = (trip_date, trip_number_of_day) — multi-trip per hari support

**Active trip detection logic:**

```php
$activeTrip = Trip::where('operator_id', auth()->id())
    ->whereDate('trip_date', today())
    ->whereNotNull('started_at')
    ->whereNull('ended_at')
    ->first();
```

**Next trip_number_of_day:**

```php
$nextNumber = Trip::where('operator_id', auth()->id())
    ->whereDate('trip_date', today())
    ->max('trip_number_of_day') + 1;
```

## GOAL SESI 1

Bikin foundation Trip Flow operator dengan 3 page Livewire:

1. **Update Operator Dashboard** — punya tombol "Mulai Trip" (atau "Lanjutkan Trip Aktif" kalau ada)
2. **Halaman Start Trip** — pilih cluster awal (dengan urgency indicator)
3. **Halaman Active Trip** — skeleton, isi Day 6+

## YANG TIDAK DI-BUILD SESI INI (defer Day 6+)

- List kios per cluster dengan Nearest Neighbor
- Form visit (drop_and_settle, drop_only, check_only, settle_only)
- Lanjut cluster lain workflow
- End trip + summary
- Smart suggestion learning

## NO SCHEMA CHANGES TONIGHT

Schema Day 2 udah support semua kebutuhan. **TIDAK ADA migration di sesi ini.**

## TUGAS 1: VERIFY EXISTING STATE

Sebelum mulai, verify struktur project:

1. Check apakah ada Livewire component existing di `app/Livewire/Operator/`:
    - `Dashboard.php` (kemungkinan ada dari Sesi 1)
2. Check routes di `routes/web.php`:
    - Apakah `/operator/dashboard` udah registered?
3. Check views di `resources/views/livewire/operator/`:
    - File apa aja yang udah ada

**Report ke advisor sebelum lanjut kalau ada conflict.**

## TUGAS 2: UPDATE OPERATOR DASHBOARD

File: `app/Livewire/Operator/Dashboard.php`

Jika file ada, update logic dengan active trip detection.
Jika belum ada, create dengan content berikut:

```php
<?php

namespace App\Livewire\Operator;

use App\Models\Trip;
use Livewire\Component;

class Dashboard extends Component
{
    public ?Trip $activeTrip = null;
    public int $tripsToday = 0;
    public int $kiosksVisitedToday = 0;

    public function mount()
    {
        $this->loadStats();
    }

    protected function loadStats(): void
    {
        $this->activeTrip = Trip::where('operator_id', auth()->id())
            ->whereDate('trip_date', today())
            ->whereNotNull('started_at')
            ->whereNull('ended_at')
            ->first();

        $this->tripsToday = Trip::where('operator_id', auth()->id())
            ->whereDate('trip_date', today())
            ->count();

        $this->kiosksVisitedToday = \App\Models\KioskVisit::whereHas('trip', function ($query) {
            $query->where('operator_id', auth()->id())
                ->whereDate('trip_date', today());
        })->count();
    }

    public function render()
    {
        return view('livewire.operator.dashboard');
    }
}
```

File view: `resources/views/livewire/operator/dashboard.blade.php`

Mobile-first layout dengan Tailwind:

```blade
<div class="min-h-screen bg-slate-900 text-white p-4 pb-24">
    <div class="max-w-md mx-auto">

        {{-- Greeting --}}
        <div class="mb-6">
            <h1 class="text-2xl font-bold">Halo, {{ auth()->user()->name }}</h1>
            <p class="text-slate-400 text-sm">{{ now()->isoFormat('dddd, D MMMM Y') }}</p>
        </div>

        {{-- Stats Cards --}}
        <div class="grid grid-cols-2 gap-3 mb-6">
            <div class="bg-slate-800 rounded-xl p-4">
                <p class="text-xs text-slate-400 uppercase tracking-wider">Trip Hari Ini</p>
                <p class="text-3xl font-bold mt-1">{{ $tripsToday }}</p>
            </div>
            <div class="bg-slate-800 rounded-xl p-4">
                <p class="text-xs text-slate-400 uppercase tracking-wider">Kios Dikunjungi</p>
                <p class="text-3xl font-bold mt-1">{{ $kiosksVisitedToday }}</p>
            </div>
        </div>

        {{-- CTA Button --}}
        @if($activeTrip)
            <a href="{{ route('operator.trip.active', $activeTrip->id) }}"
               class="block w-full py-8 bg-green-500 hover:bg-green-600 text-slate-900 text-center rounded-2xl font-bold text-2xl shadow-lg transition-all">
                <div class="flex flex-col items-center gap-2">
                    <svg class="w-12 h-12" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>
                    </svg>
                    <span>Lanjutkan Trip Aktif</span>
                    <span class="text-sm font-normal opacity-80">Trip #{{ $activeTrip->trip_number_of_day }} hari ini</span>
                </div>
            </a>
        @else
            <a href="{{ route('operator.trip.start') }}"
               class="block w-full py-8 bg-amber-500 hover:bg-amber-600 text-slate-900 text-center rounded-2xl font-bold text-2xl shadow-lg transition-all">
                <div class="flex flex-col items-center gap-2">
                    <svg class="w-12 h-12" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                    <span>Mulai Trip</span>
                    <span class="text-sm font-normal opacity-80">Tap untuk mulai ngantar</span>
                </div>
            </a>
        @endif

    </div>
</div>
```

**CATATAN:** Kalau dashboard udah ada dengan layout berbeda, ADAPT — jangan replace total. Yang penting:

- Active trip detection logic ada
- Tombol "Mulai Trip" atau "Lanjutkan Trip Aktif" ada
- Conditional rendering based on `$activeTrip`

## TUGAS 3: CREATE LIVEWIRE COMPONENT "StartTrip"

File: `app/Livewire/Operator/StartTrip.php`

```php
<?php

namespace App\Livewire\Operator;

use App\Models\Cluster;
use App\Models\Trip;
use Livewire\Component;

class StartTrip extends Component
{
    public ?int $selectedClusterId = null;

    public function mount()
    {
        $existingTrip = Trip::where('operator_id', auth()->id())
            ->whereDate('trip_date', today())
            ->whereNotNull('started_at')
            ->whereNull('ended_at')
            ->first();

        if ($existingTrip) {
            $this->redirect(route('operator.trip.active', $existingTrip->id), navigate: true);
        }
    }

    public function getClustersProperty()
    {
        return Cluster::query()
            ->where('is_active', true)
            ->withCount(['kiosks' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('name')
            ->get()
            ->map(function ($cluster) {
                $cluster->urgency_data = $this->calculateUrgency($cluster->id);
                return $cluster;
            });
    }

    protected function calculateUrgency(int $clusterId): array
    {
        $kiosks = \App\Models\Kiosk::query()
            ->where('cluster_id', $clusterId)
            ->where('is_active', true)
            ->get();

        if ($kiosks->isEmpty()) {
            return [
                'level' => 'empty',
                'message' => 'Belum ada kios',
                'overdue_count' => 0,
                'warning_count' => 0,
                'never_count' => 0,
            ];
        }

        $overdue = 0;
        $warning = 0;
        $fresh = 0;
        $never = 0;

        foreach ($kiosks as $kiosk) {
            $lastVisit = \App\Models\KioskVisit::where('kiosk_id', $kiosk->id)
                ->latest('visited_at')
                ->value('visited_at');

            if (! $lastVisit) {
                $never++;
                continue;
            }

            $days = now()->diffInDays($lastVisit);
            $target = $kiosk->target_visit_interval_days ?? 14;
            $warningThreshold = $kiosk->warning_visit_interval_days ?? 10;

            if ($days > $target) {
                $overdue++;
            } elseif ($days > $warningThreshold) {
                $warning++;
            } else {
                $fresh++;
            }
        }

        $level = match (true) {
            $overdue > 0 => 'high',
            $warning > 0 => 'medium',
            $never > 0 && $fresh === 0 => 'unknown',
            default => 'low',
        };

        $messageParts = [];
        if ($overdue > 0) $messageParts[] = "{$overdue} overdue";
        if ($warning > 0) $messageParts[] = "{$warning} warning";
        if ($never > 0) $messageParts[] = "{$never} belum visit";
        if (empty($messageParts) && $fresh > 0) $messageParts[] = "Semua dalam interval normal";

        return [
            'level' => $level,
            'message' => implode(', ', $messageParts),
            'overdue_count' => $overdue,
            'warning_count' => $warning,
            'never_count' => $never,
        ];
    }

    public function startTrip()
    {
        $this->validate([
            'selectedClusterId' => 'required|exists:clusters,id',
        ], [
            'selectedClusterId.required' => 'Pilih cluster dulu',
            'selectedClusterId.exists' => 'Cluster tidak valid',
        ]);

        $nextNumber = Trip::where('operator_id', auth()->id())
            ->whereDate('trip_date', today())
            ->max('trip_number_of_day');

        $nextNumber = ($nextNumber ?? 0) + 1;

        $trip = Trip::create([
            'operator_id' => auth()->id(),
            'trip_date' => today(),
            'trip_number_of_day' => $nextNumber,
            'started_at' => now(),
            'qty_carried_total' => 0,
            'notes' => "Cluster awal: cluster_id={$this->selectedClusterId}",
        ]);

        session()->put("trip_{$trip->id}_starting_cluster", $this->selectedClusterId);

        return $this->redirect(route('operator.trip.active', $trip->id), navigate: true);
    }

    public function render()
    {
        return view('livewire.operator.start-trip', [
            'clusters' => $this->clusters,
        ]);
    }
}
```

File view: `resources/views/livewire/operator/start-trip.blade.php`

```blade
<div class="min-h-screen bg-slate-900 text-white p-4 pb-24">
    <div class="max-w-md mx-auto">

        {{-- Header --}}
        <div class="mb-6">
            <a href="{{ route('operator.dashboard') }}" class="text-slate-400 text-sm flex items-center gap-1 hover:text-white">
                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M9.707 14.707a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 1.414L7.414 9H15a1 1 0 110 2H7.414l2.293 2.293a1 1 0 010 1.414z" clip-rule="evenodd"/>
                </svg>
                Kembali ke Dashboard
            </a>
            <h1 class="text-2xl font-bold mt-2">Mulai Trip</h1>
            <p class="text-slate-400 text-sm">Pilih cluster awal yang akan dikunjungi</p>
        </div>

        {{-- Cluster List --}}
        <div class="space-y-3">
            @forelse ($clusters as $cluster)
                <button
                    type="button"
                    wire:click="$set('selectedClusterId', {{ $cluster->id }})"
                    class="w-full text-left p-4 rounded-xl border-2 transition-all
                        {{ $selectedClusterId === $cluster->id
                            ? 'border-amber-500 bg-amber-500/10'
                            : 'border-slate-700 bg-slate-800 hover:border-slate-600' }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                @if($cluster->urgency_data['level'] === 'high')
                                    <span class="inline-block w-3 h-3 rounded-full bg-red-500 flex-shrink-0"></span>
                                @elseif($cluster->urgency_data['level'] === 'medium')
                                    <span class="inline-block w-3 h-3 rounded-full bg-yellow-500 flex-shrink-0"></span>
                                @elseif($cluster->urgency_data['level'] === 'low')
                                    <span class="inline-block w-3 h-3 rounded-full bg-green-500 flex-shrink-0"></span>
                                @elseif($cluster->urgency_data['level'] === 'empty')
                                    <span class="inline-block w-3 h-3 rounded-full bg-slate-600 flex-shrink-0"></span>
                                @else
                                    <span class="inline-block w-3 h-3 rounded-full bg-blue-500 flex-shrink-0"></span>
                                @endif
                                <h3 class="font-bold text-lg truncate">{{ $cluster->name }}</h3>
                            </div>

                            <p class="text-sm text-slate-400 mt-1">
                                {{ $cluster->kiosks_count }} kios aktif
                            </p>

                            <p class="text-xs text-slate-500 mt-2">
                                {{ $cluster->urgency_data['message'] }}
                            </p>
                        </div>

                        @if($selectedClusterId === $cluster->id)
                            <svg class="w-6 h-6 text-amber-500 flex-shrink-0 mt-1" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                            </svg>
                        @endif
                    </div>
                </button>
            @empty
                <div class="text-center p-8 bg-slate-800 rounded-xl">
                    <svg class="mx-auto h-12 w-12 text-slate-600 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"/>
                    </svg>
                    <p class="text-slate-400 font-medium">Belum ada cluster aktif</p>
                    <p class="text-xs text-slate-500 mt-2">Hubungi owner untuk setup cluster dulu</p>
                </div>
            @endforelse
        </div>

        {{-- Error Message --}}
        @error('selectedClusterId')
            <p class="mt-3 text-sm text-red-400 text-center">{{ $message }}</p>
        @enderror

        {{-- CTA Button --}}
        @if(count($clusters) > 0)
            <div class="mt-6">
                <button
                    type="button"
                    wire:click="startTrip"
                    wire:loading.attr="disabled"
                    @disabled(!$selectedClusterId)
                    class="w-full py-4 rounded-xl font-bold text-lg transition-all
                        {{ $selectedClusterId
                            ? 'bg-amber-500 hover:bg-amber-600 text-slate-900'
                            : 'bg-slate-700 text-slate-500 cursor-not-allowed' }}">
                    <span wire:loading.remove wire:target="startTrip">
                        @if($selectedClusterId)
                            Mulai Trip Sekarang →
                        @else
                            Pilih cluster dulu
                        @endif
                    </span>
                    <span wire:loading wire:target="startTrip">
                        Memulai trip...
                    </span>
                </button>
            </div>
        @endif

    </div>
</div>
```

## TUGAS 4: CREATE LIVEWIRE COMPONENT "ActiveTrip" (Skeleton)

File: `app/Livewire/Operator/ActiveTrip.php`

```php
<?php

namespace App\Livewire\Operator;

use App\Models\Trip;
use Livewire\Component;

class ActiveTrip extends Component
{
    public Trip $trip;
    public ?int $startingClusterId = null;

    public function mount(int $tripId)
    {
        $this->trip = Trip::where('id', $tripId)
            ->where('operator_id', auth()->id())
            ->whereNotNull('started_at')
            ->whereNull('ended_at')
            ->firstOrFail();

        $this->startingClusterId = session()->get("trip_{$tripId}_starting_cluster");
    }

    public function render()
    {
        return view('livewire.operator.active-trip', [
            'startingCluster' => $this->startingClusterId
                ? \App\Models\Cluster::find($this->startingClusterId)
                : null,
        ]);
    }
}
```

File view: `resources/views/livewire/operator/active-trip.blade.php`

```blade
<div class="min-h-screen bg-slate-900 text-white p-4 pb-24">
    <div class="max-w-md mx-auto">

        {{-- Header --}}
        <div class="mb-6">
            <h1 class="text-2xl font-bold">Trip Aktif</h1>
            @if($startingCluster)
                <p class="text-slate-400 text-sm">
                    Cluster awal: <span class="text-amber-500 font-medium">{{ $startingCluster->name }}</span>
                </p>
            @endif
            <p class="text-xs text-slate-500 mt-1">
                Trip #{{ $trip->trip_number_of_day }} hari ini · Mulai {{ $trip->started_at->isoFormat('D MMM Y, HH:mm') }}
            </p>
        </div>

        {{-- Skeleton State --}}
        <div class="bg-slate-800 rounded-xl p-6 text-center">
            <svg class="mx-auto h-16 w-16 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17a2 2 0 11-4 0 2 2 0 014 0zM19 17a2 2 0 11-4 0 2 2 0 014 0z"/>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a1 1 0 001 1h1M5 17a2 2 0 104 0m-4 0a2 2 0 114 0m6 0a2 2 0 104 0m-4 0a2 2 0 114 0"/>
            </svg>
            <h3 class="mt-4 text-lg font-bold">Trip baru dimulai!</h3>
            <p class="mt-2 text-sm text-slate-400">
                Trip ID: <span class="font-mono">#{{ $trip->id }}</span>
            </p>
        </div>

        {{-- Coming Soon List --}}
        <div class="mt-6 space-y-2">
            <h4 class="text-sm font-semibold text-slate-400 uppercase tracking-wider mb-3">Coming Soon (Day 6+)</h4>

            <div class="bg-slate-800/50 rounded-lg p-3 flex items-center gap-3 opacity-60">
                <span class="text-2xl">📍</span>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium">List Kios di Cluster</p>
                    <p class="text-xs text-slate-500">Nearest Neighbor + drag-drop reorder</p>
                </div>
            </div>

            <div class="bg-slate-800/50 rounded-lg p-3 flex items-center gap-3 opacity-60">
                <span class="text-2xl">📦</span>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium">Form Visit per Kios</p>
                    <p class="text-xs text-slate-500">drop_and_settle / drop_only / check_only / settle_only</p>
                </div>
            </div>

            <div class="bg-slate-800/50 rounded-lg p-3 flex items-center gap-3 opacity-60">
                <span class="text-2xl">🔄</span>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium">Lanjut Cluster Lain</p>
                    <p class="text-xs text-slate-500">Sequential multi-cluster trip</p>
                </div>
            </div>

            <div class="bg-slate-800/50 rounded-lg p-3 flex items-center gap-3 opacity-60">
                <span class="text-2xl">✅</span>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium">End Trip + Summary</p>
                    <p class="text-xs text-slate-500">stock_habis / target_done / sakit / urgent</p>
                </div>
            </div>
        </div>

        {{-- Temporary End Trip Button (for testing only) --}}
        <div class="mt-8 p-4 bg-yellow-500/10 border border-yellow-500/30 rounded-lg">
            <p class="text-xs text-yellow-400 mb-2">⚠️ Temporary (untuk testing Sesi 1)</p>
            <a href="{{ route('operator.dashboard') }}" class="text-sm text-yellow-300 underline">
                Kembali ke Dashboard tanpa end trip
            </a>
            <p class="text-xs text-slate-500 mt-1">Trip tetap aktif di DB, bisa dilanjutkan</p>
        </div>

    </div>
</div>
```

## TUGAS 5: REGISTER ROUTES

File: `routes/web.php`

Tambahkan dalam group operator (kalau group udah ada dari Sesi 1, append routes baru. Kalau belum ada, create group):

```php
Route::middleware(['auth', \App\Http\Middleware\EnsureUserHasRole::class.':operator'])
    ->prefix('operator')
    ->name('operator.')
    ->group(function () {
        Route::get('/dashboard', \App\Livewire\Operator\Dashboard::class)->name('dashboard');
        Route::get('/trip/start', \App\Livewire\Operator\StartTrip::class)->name('trip.start');
        Route::get('/trip/{tripId}', \App\Livewire\Operator\ActiveTrip::class)->name('trip.active');
    });
```

**CATATAN:** Kalau middleware syntax beda dari existing routes, ADAPT match existing pattern.

## TUGAS 6: TEST

```bash
php artisan test
```

Expected: 42 PASS (no regression).

Optional: tambah test untuk Trip creation kalau lo mau. Skip kalau tidak.

## TUGAS 7: VERIFY ROUTES

```bash
php artisan route:list | grep operator
```

Expected output minimal:
GET operator/dashboard operator.dashboard
GET operator/trip/start operator.trip.start
GET operator/trip/{tripId} operator.trip.active

## TUGAS 8: COMMIT

```bash
git add app/Livewire/Operator/ resources/views/livewire/operator/ routes/web.php
git commit -m "feat(operator): trip flow foundation (Sesi 1)

- Operator Dashboard: active trip detection + Mulai Trip / Lanjutkan CTA
- StartTrip Livewire: cluster picker dengan urgency indicator
  * Urgency calc per cluster via kiosk_visits query (index idx_kiosk_last_visit ready)
  * Levels: high (overdue exist), medium (warning exist), low (all fresh), empty (no kiosks), unknown (only never-visited)
  * Color-coded indicator: red/yellow/green/gray/blue
- ActiveTrip Livewire (skeleton): trip display + coming soon roadmap
- Trip creation logic match schema Day 2:
  * trip_number_of_day auto-counter via max() + 1
  * Starting cluster di session (no schema deviation)
  * Active detection: started_at NOT NULL AND ended_at NULL
- Routes: /operator/trip/start + /operator/trip/{tripId}
- Mobile-first dengan Tailwind, max-w-md mx-auto

Foundation untuk Day 6+ Sesi 2-5:
- Sesi 2: List kios + Nearest Neighbor + drag-drop
- Sesi 3: Form visit (4 visit_action)
- Sesi 4: Sequential multi-cluster workflow
- Sesi 5: End trip + summary"
```

## REPORT KE ADVISOR

Setelah commit:

1. File list yang dibuat/dimodifikasi
2. Output `php artisan test` (42 PASS)
3. Output `php artisan route:list | grep operator`
4. Commit hash + git log -5
5. Catatan kalau ada adaptasi dari brief (struktur existing, dll)

## STOP POINTS — TANYA ADVISOR KALAU:

1. Operator dashboard udah ada dengan struktur berbeda total — gimana adapt?
2. Middleware role syntax beda dari yang di brief
3. Routes namespace conflict
4. Schema field yang gua brief actually berbeda
5. EnsureUserHasRole middleware path beda

**JANGAN auto-decide untuk hal yang fundamental conflict. Tanya dulu.**

## CATATAN PENTING

- **NO SCHEMA CHANGES.** Tidak ada migration di sesi ini.
- Schema Day 2 udah support semua via creative use of existing fields + session state untuk transient data.
- Trip starting_cluster disimpan di session (bukan column trip) — temporary solution untuk foundation. Day 6+ akan refactor via first kiosk_visit's kiosk.cluster_id (lebih persistent).
- Mobile-first design (max-w-md mx-auto + pb-24 untuk bottom nav clearance).
- Active trip detection bisa di-extract jadi scope di Trip model di Day 6+ kalau redundant.

Mulai sekarang.

--- END OF BRIEF ---
