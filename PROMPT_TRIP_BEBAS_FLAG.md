# Brief: Trip Bebas + Smart Kios Flag System

## KONTEKS

97 PASS. Tambah 2 fitur:

1. Trip Bebas — Rian bisa ngantar lintas cluster dalam 1 trip
2. Smart Kios Flag System — badge status per kios di list trip

## BUSINESS RULES (LOCKED)

### Trip Bebas

- StartTrip: tambah toggle "Pilih Cluster" vs "Trip Bebas (Semua Kios)"
- Kalau Trip Bebas: starting_cluster_id = null, load semua kios aktif owner
- Kalau Trip Bebas + nearest neighbor aktif: sort by jarak dari posisi Rian
- Trip Bebas tidak mengubah default_qty_mika atau cluster kios
- Header ActiveTrip: kalau starting_cluster_id null → tampil "Semua Kios"

### Smart Kios Flag System

6 flag per kios (bisa kombinasi):

1. URGENT (merah) — lewat target_visit_interval_days ATAU last_visit > 10 hari
2. HAMPIR EXPIRED (kuning) — lewat warning_visit_interval_days
3. FAST MOVER (hijau) — rata-rata hari habis < fast_mover_threshold_days kios
4. SLOW MOVER (biru) — rata-rata hari habis > 2x fast_mover_threshold_days
5. KIOS BARU (ungu) — first_titip_date dalam 30 hari terakhir
6. NORMAL — tidak ada flag khusus (tidak tampil badge)

### Smart Analysis untuk Fast/Slow Mover

- Hitung dari historis: rata-rata jarak antara delivery.created_at dan settlement.visit_date per kios
- Minimum 3 settlement data untuk dihitung (kalau < 3 = skip, tidak flag fast/slow)
- Kalau fast_mover_threshold_days = null → skip flag fast/slow untuk kios itu
- Formula: avg_days = avg(DATEDIFF(settlement.visit_date, delivery.created_at)) per kios

## SCHEMA PERUBAHAN

### Migration: tambah fast_mover_threshold_days ke kiosks

```php
Schema::table('kiosks', function (Blueprint $table) {
    $table->unsignedTinyInteger('fast_mover_threshold_days')
        ->nullable()
        ->after('warning_visit_interval_days')
        ->comment('Threshold hari untuk flag fast mover. Null = tidak dimonitor.');
});
```

## PERUBAHAN STARTTRIP.PHP

### Property baru:

public bool $tripBebas = false;

### Update view start-trip.blade.php:

Tambah toggle sebelum dropdown cluster:

```html
<div
    class="flex items-center gap-3 p-4 bg-slate-50 rounded-xl border border-slate-200"
>
    <input
        type="checkbox"
        wire:model.live="tripBebas"
        id="tripBebas"
        class="rounded border-slate-300 text-amber-600 focus:ring-amber-500"
    />
    <label
        for="tripBebas"
        class="text-sm font-medium text-slate-700 cursor-pointer"
    >
        Trip Bebas (Semua Kios, Lintas Cluster)
    </label>
</div>
```

Kalau tripBebas = true → sembunyikan dropdown cluster (tidak wajib pilih cluster)
Kalau tripBebas = false → cluster wajib dipilih (existing behavior)

### Update validasi startTrip():

Kalau tripBebas = true: skip validasi selectedClusterId
Kalau tripBebas = false: validasi selectedClusterId required

### Update Trip::create():

'starting_cluster_id' => $this->tripBebas ? null : $this->selectedClusterId,

## PERUBAHAN ACTIVETRIIP.PHP

### Update loadKiosks():

Existing: kalau starting_cluster_id null → load semua kios (sudah ada)
Tambah: attach flag per kios (via method computeKiosFlags)

### Method baru computeKiosFlags(Collection $kiosks): void

Hitung flag untuk semua kios sekaligus (hindari N+1):

```php
private function computeKiosFlags(Collection $kiosks): void
{
    $kioskIds = $kiosks->pluck('id')->all();
    $today = now();
    $thirtyDaysAgo = $today->copy()->subDays(30);

    // Last visit per kios
    $lastVisits = KioskVisit::whereIn('kiosk_id', $kioskIds)
        ->groupBy('kiosk_id')
        ->selectRaw('kiosk_id, MAX(visited_at) as last_visit')
        ->pluck('last_visit', 'kiosk_id');

    // Avg days to settle per kios (min 3 settlements)
    $avgDays = Settlement::whereHas('delivery', fn($q) => $q->whereIn('kiosk_id', $kioskIds))
        ->join('deliveries', 'settlements.delivery_id', '=', 'deliveries.id')
        ->groupBy('deliveries.kiosk_id')
        ->havingRaw('COUNT(*) >= 3')
        ->selectRaw('deliveries.kiosk_id, AVG(DATEDIFF(settlements.visit_date, deliveries.created_at)) as avg_days')
        ->pluck('avg_days', 'kiosk_id');

    // Store ke public array properties
    $this->kioskFlags = [];

    foreach ($kiosks as $kiosk) {
        $flags = [];
        $lastVisit = $lastVisits[$kiosk->id] ?? null;
        $daysSinceVisit = $lastVisit ? $today->diffInDays($lastVisit) : 999;

        // URGENT
        $urgentThreshold = $kiosk->target_visit_interval_days ?: 10;
        if ($daysSinceVisit > $urgentThreshold) {
            $flags[] = 'urgent';
        }

        // HAMPIR EXPIRED
        $warningThreshold = $kiosk->warning_visit_interval_days ?? null;
        if ($warningThreshold && $daysSinceVisit > $warningThreshold && !in_array('urgent', $flags)) {
            $flags[] = 'warning';
        }

        // KIOS BARU
        if ($kiosk->first_titip_date && $kiosk->first_titip_date >= $thirtyDaysAgo->toDateString()) {
            $flags[] = 'new';
        }

        // FAST/SLOW MOVER (butuh threshold + minimal 3 data)
        $threshold = $kiosk->fast_mover_threshold_days ?? null;
        $avg = $avgDays[$kiosk->id] ?? null;
        if ($threshold && $avg !== null) {
            if ($avg < $threshold) {
                $flags[] = 'fast_mover';
            } elseif ($avg > ($threshold * 2)) {
                $flags[] = 'slow_mover';
            }
        }

        $this->kioskFlags[$kiosk->id] = $flags;
    }
}
```

### Property baru:

public array $kioskFlags = [];

### Update loadKiosks(): panggil computeKiosFlags setelah load kiosks

## PERUBAHAN VIEW active-trip.blade.php

### Di card tiap kios, setelah badge visited/belum + "Ada Titipan":

```html
@php $flags = $kioskFlags[$kiosk->id] ?? []; @endphp @if(in_array('urgent',
$flags))
<span class="text-xs bg-red-100 text-red-700 px-2 py-0.5 rounded-full font-bold"
    >🔴 URGENT</span
>
@elseif(in_array('warning', $flags))
<span class="text-xs bg-amber-100 text-amber-700 px-2 py-0.5 rounded-full"
    >⚠️ Hampir Expired</span
>
@endif @if(in_array('fast_mover', $flags))
<span class="text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded-full"
    >⚡ Fast Mover</span
>
@endif @if(in_array('slow_mover', $flags))
<span class="text-xs bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full"
    >🐢 Slow Mover</span
>
@endif @if(in_array('new', $flags))
<span class="text-xs bg-purple-100 text-purple-700 px-2 py-0.5 rounded-full"
    >⭐ Kios Baru</span
>
@endif
```

## PERUBAHAN FILAMENT KioskResource

### Tambah field fast_mover_threshold_days di form:

```php
Forms\Components\TextInput::make('fast_mover_threshold_days')
    ->label('Threshold Fast Mover (hari)')
    ->numeric()
    ->nullable()
    ->helperText('Rata-rata habis < X hari = Fast Mover. Kosongkan = tidak dimonitor.')
    ->placeholder('Contoh: 5'),
```

### Tambah kolom di table (toggleable hidden):

```php
Tables\Columns\TextColumn::make('fast_mover_threshold_days')
    ->label('Fast Mover')
    ->suffix(' hari')
    ->placeholder('—')
    ->toggleable(isToggledHiddenByDefault: true),
```

## STEP EKSEKUSI

1. Migration: tambah fast_mover_threshold_days ke kiosks
2. php artisan migrate
3. Update Kiosk model ($fillable + cast)
4. Update KioskResource (form + table column)
5. Update StartTrip.php (property tripBebas + validasi + Trip::create)
6. Update start-trip.blade.php (toggle Trip Bebas + conditional cluster)
7. Update ActiveTrip.php (property kioskFlags + computeKiosFlags + update loadKiosks)
8. Update active-trip.blade.php (badge flags per kios)
9. php artisan test --compact (target 97+ PASS)
10. Commit:
    git add .
    git commit -m "feat(operator): trip bebas lintas cluster + smart kios flag system"
    git push origin main

## STOP POINTS — TANYA ADVISOR KALAU

1. warning_visit_interval_days tidak ada di schema kiosks
2. KioskVisit tidak punya index yang cukup untuk query MAX(visited_at) per kios
3. Test turun dari 97 PASS
4. computeKiosFlags N+1 issue

JANGAN auto-decide. Lapor dulu.

Output: ringkas per step + test status. No narasi panjang.

Mulai sekarang.

--- END OF BRIEF ---
