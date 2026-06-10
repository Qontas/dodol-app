# Brief: Skenario Lanjutan — Turun Default, Cek Sisa, BS Redistribusi, Untung Bersih

## KONTEKS

107 PASS. Tambah 4 fitur baru tanpa break test existing.

## BUSINESS RULES (LOCKED)

### Skenario 4: Turun Default Qty

- Operator settle kios + minta turunkan default qty
- Yang dibayar = titipan lama dikurangi BS
- default_qty_mika kios otomatis turun ke nilai baru
- Toggle "Turunkan default ke X mika" muncul saat settle_only atau drop_and_settle
- Operator input: qty_default_baru (harus < default_qty_mika saat ini)

### Skenario 5 Upgrade: Check Only + Alasan + Sisa Biji

- visit_action = 'check_only' ditambah data:
    - alasan_check: 'kios_tutup' | 'pemilik_minta_tunggu' | 'tidak_ada_uang' | 'dodol_masih_banyak'
    - sisa_biji: integer (berapa biji dodol yang masih ada di kios)
- Dari sisa_biji → sistem prediksi kapan habis:
    - avg_penjualan_per_hari = rata-rata biji terjual per hari dari historis settlement kios ini
    - prediksi_habis_hari = sisa_biji / avg_penjualan_per_hari
    - Kalau belum ada historis (< 3 settlement) → tidak bisa prediksi, tampil "Data belum cukup"
- Data sisa_biji + prediksi tampil di owner dashboard (list kios overdue/check)

### Skenario 7: BS Redistribusi

- Operator kumpulkan BS dari beberapa kios → gabung jadi mika utuh → antar ke kios lain
- Di modal visit kios tujuan: ada toggle "Ada mika BS redistribusi"
- Input: qty_bs_mika (berapa mika BS yang ikut di-drop)
- Total drop ke kios tujuan = qty_drop_baru + qty_bs_mika
- Delivery dibuat 2:
    1. Delivery normal: qty = qty_drop_baru, delivery_type = 'consignment'
    2. Delivery BS redistribusi: qty = qty_bs_mika, delivery_type = 'bs_redistribution'
        - HPP = 0 (sudah dihitung loss di kios asal)
        - Harga jual tetap Rp 12.000/mika (bayar penuh)
- Settlement tetap 1 untuk total (qty_drop_baru + qty_bs_mika) × 15 biji × Rp 800
- qty_bs_mika TIDAK dihitung ke qty_carried blocking
  → Fix validasi: sisa_stok = qty_carried - total_drop_konsinyasi (exclude bs_redistribution)

### Untung Bersih Dashboard Owner

- Widget baru di dashboard: "Untung Bersih Hari Ini"
- Formula: sum(untung_bersih_owner) dari semua completed trip hari ini milik owner
- Warna: hijau kalau > 0, merah kalau 0

## SCHEMA PERUBAHAN

### Migration 1: tambah kolom ke kiosk_visits

```php
Schema::table('kiosk_visits', function (Blueprint $table) {
    $table->string('alasan_check', 50)->nullable()->after('visit_action');
    $table->unsignedSmallInteger('sisa_biji')->nullable()->after('alasan_check');
});
```

### Migration 2: tambah delivery_type 'bs_redistribution' ke enum

Cek tipe kolom delivery_type:
php artisan tinker --execute="echo DB::select(\"SHOW COLUMNS FROM deliveries LIKE 'delivery_type'\")[0]->Type;"

Kalau enum: ALTER TABLE deliveries MODIFY delivery_type ENUM(...)

### Migration 3: tambah qty_bs_redistributed ke trips

```php
Schema::table('trips', function (Blueprint $table) {
    $table->unsignedSmallInteger('qty_bs_redistributed')->default(0)->after('qty_carried_total');
});
```

## PERUBAHAN MODEL

### KioskVisit model:

Tambah ke $fillable: 'alasan_check', 'sisa_biji'
Tambah ke $casts: 'sisa_biji' => 'integer'

### Trip model:

Tambah ke $fillable: 'qty_bs_redistributed'
Tambah helper: getTotalDropReal() = qty yang benar-benar dari stok baru (exclude BS redistribusi)

### Delivery model:

Pastikan 'bs_redistribution' valid di delivery_type

## PERUBAHAN ACTIVETRIIP.PHP

### Properties baru:

```php
public string $alasanCheck = '';
public int $sisaBiji = 0;
public bool $adaBsRedistribusi = false;
public int $qtyBsMika = 0;
public int $qtyDefaultBaru = 0;
public bool $turunkanDefault = false;
```

### Reset di openVisitModal():

Reset semua property baru ke default.

### Update saveVisit() — tambah skenario baru:

#### Skenario 4 (turun default):

Saat isSettleAction = true && turunkanDefault = true:

```php
if ($this->turunkanDefault && $this->qtyDefaultBaru > 0
    && $this->qtyDefaultBaru < $this->selectedKiosk->default_qty_mika) {
    $this->selectedKiosk->update(['default_qty_mika' => $this->qtyDefaultBaru]);
}
```

#### Skenario 5 (check_only + alasan + sisa):

Saat visit_action = 'check_only':

```php
KioskVisit::create([
    ...
    'visit_action' => 'check_only',
    'alasan_check' => $this->alasanCheck ?: null,
    'sisa_biji' => $this->sisaBiji > 0 ? $this->sisaBiji : null,
]);
```

#### Skenario 7 (BS redistribusi):

Saat adaBsRedistribusi = true && qtyBsMika > 0:

- Buat delivery bs_redistribution terpisah
- Update trip->qty_bs_redistributed += qtyBsMika
- Settlement total = (drop + qtyBsMika) × 15 × 800

#### Fix qty blocking:

Ubah validasi stok dari:

```php
// SEBELUM
if ($drop > $sisaStok) → error
```

Menjadi:

```php
// SESUDAH — BS redistribusi tidak dihitung ke stok
$dropEfektif = $drop; // qty_bs_mika tidak ikut dihitung
if ($dropEfektif > $sisaStok) → error
```

## PERUBAHAN VIEW active-trip.blade.php

### Section check_only — tambah alasan + sisa biji:

```html
@if($visitAction === 'check_only')
<div class="mt-4 space-y-4">
    {{-- Alasan --}}
    <div>
        <label class="block text-sm font-bold text-slate-700 mb-2"
            >Alasan Kunjungan</label
        >
        <div class="grid grid-cols-2 gap-2">
            @foreach([ 'kios_tutup' => '🔒 Kios Tutup', 'pemilik_minta_tunggu'
            => '⏳ Minta Tunggu', 'tidak_ada_uang' => '💸 Tidak Ada Uang',
            'dodol_masih_banyak' => '📦 Dodol Masih Ada', ] as $val => $label)
            <label
                class="flex items-center gap-2 p-3 rounded-xl border cursor-pointer
                {{ $alasanCheck === $val ? 'border-amber-400 bg-amber-50' : 'border-slate-200' }}"
            >
                <input
                    type="radio"
                    wire:model.live="alasanCheck"
                    value="{{ $val }}"
                    class="sr-only"
                />
                <span class="text-sm font-medium">{{ $label }}</span>
            </label>
            @endforeach
        </div>
    </div>

    {{-- Sisa Biji --}}
    <div>
        <label class="block text-sm font-bold text-slate-700 mb-2">
            Sisa Dodol di Kios (Biji)
        </label>
        <input
            type="number"
            wire:model="sisaBiji"
            class="w-full rounded-xl border-slate-300 text-center text-xl font-bold py-3"
            min="0"
            placeholder="0"
        />
        <p class="text-xs text-slate-400 mt-1">
            Isi 0 kalau tidak tahu atau kios tutup
        </p>
    </div>
</div>
@endif
```

### Section turun default — muncul saat settle:

```html
@if(in_array($visitAction, ['settle_only','drop_and_settle']) &&
$selectedKiosk->default_qty_mika > 1)
<div class="mt-3">
    <label
        class="flex items-center gap-3 cursor-pointer p-3 rounded-xl border border-slate-200"
    >
        <input
            type="checkbox"
            wire:model.live="turunkanDefault"
            class="rounded text-amber-600"
        />
        <span class="text-sm font-medium text-slate-700"
            >Turunkan default qty kios ini</span
        >
    </label>
    @if($turunkanDefault)
    <div class="mt-2">
        <label class="text-xs text-slate-500">Default baru (mika)</label>
        <input
            type="number"
            wire:model="qtyDefaultBaru"
            min="1"
            max="{{ $selectedKiosk->default_qty_mika - 1 }}"
            class="w-full rounded-xl border-slate-300 text-center font-bold py-2 mt-1"
        />
        <p class="text-xs text-amber-600 mt-1">
            Pengantaran berikutnya: {{ $qtyDefaultBaru ?: '?' }} mika
        </p>
    </div>
    @endif
</div>
@endif
```

### Section BS redistribusi — muncul saat drop:

```html
@if($isDrop && !$isCashOnly)
<div class="mt-3">
    <label
        class="flex items-center gap-3 cursor-pointer p-3 rounded-xl border border-slate-200"
    >
        <input
            type="checkbox"
            wire:model.live="adaBsRedistribusi"
            class="rounded text-amber-600"
        />
        <span class="text-sm font-medium text-slate-700"
            >Ada mika BS redistribusi ikut di-drop</span
        >
    </label>
    @if($adaBsRedistribusi)
    <div class="mt-2">
        <label class="text-xs text-slate-500"
            >Jumlah mika BS yang ikut (mika)</label
        >
        <input
            type="number"
            wire:model="qtyBsMika"
            min="1"
            class="w-full rounded-xl border-slate-300 text-center font-bold py-2 mt-1"
        />
        <p class="text-xs text-slate-400 mt-1">
            Total drop ke kios ini: {{ (int)$dropBaru + (int)$qtyBsMika }} mika
            ({{ (int)$dropBaru }} baru + {{ (int)$qtyBsMika }} BS)
        </p>
    </div>
    @endif
</div>
@endif
```

## PERUBAHAN OWNER DASHBOARD

### Widget Untung Bersih:

Di OwnerDashboardController, tambah:

```php
$untungBersihHariIni = Trip::where('owner_id', $ownerId)
    ->whereDate('trip_date', today())
    ->whereNotNull('ended_at')
    ->get()
    ->sum('untung_bersih_owner');
```

Di dashboard.blade.php, tambah widget ke-5:

```html
<div class="bg-white rounded-lg border border-slate-200 p-5">
    <div class="flex items-center gap-2 text-slate-500">
        <span class="text-xs uppercase tracking-wide font-medium"
            >Untung Bersih Hari Ini</span
        >
    </div>
    <div
        class="mt-3 text-2xl font-bold {{ $untungBersihHariIni > 0 ? 'text-green-600' : 'text-slate-400' }}"
    >
        Rp {{ number_format($untungBersihHariIni, 0, ',', '.') }}
    </div>
</div>
```

## PREDIKSI HABIS (untuk owner dashboard)

### Method di Kiosk model:

```php
public function getPrediksiHabisAttribute(): ?string
{
    if (!$this->latestCheckVisit || !$this->latestCheckVisit->sisa_biji) {
        return null;
    }

    // Hitung avg penjualan per hari dari historis
    $avgPerHari = Settlement::whereHas('delivery', fn($q) => $q->where('kiosk_id', $this->id))
        ->join('deliveries', 'settlements.delivery_id', '=', 'deliveries.id')
        ->where('settlements.qty_sold', '>', 0)
        ->selectRaw('AVG(settlements.qty_sold / GREATEST(DATEDIFF(settlements.visit_date, deliveries.created_at), 1)) as avg_per_hari')
        ->value('avg_per_hari');

    if (!$avgPerHari || $avgPerHari <= 0) return 'Data belum cukup';

    $hariLagi = ceil($this->latestCheckVisit->sisa_biji / $avgPerHari);
    return "{$hariLagi} hari lagi";
}

public function latestCheckVisit(): HasOne
{
    return $this->hasOne(KioskVisit::class)
        ->where('visit_action', 'check_only')
        ->whereNotNull('sisa_biji')
        ->latestOfMany('visited_at');
}
```

## STEP EKSEKUSI

1. Cek tipe kolom delivery_type (enum atau string)
2. Migration 1: tambah alasan_check + sisa_biji ke kiosk_visits
3. Migration 2: tambah bs_redistribution ke delivery_type enum (kalau enum)
4. Migration 3: tambah qty_bs_redistributed ke trips
5. Update model KioskVisit, Trip, Kiosk (fillable + casts + helpers)
6. Update ActiveTrip.php (properties + saveVisit semua skenario baru)
7. Update active-trip.blade.php (3 section baru)
8. Update OwnerDashboardController + dashboard.blade.php (widget untung bersih)
9. php artisan test --compact (target 107+ PASS)
10. Commit:
    git add .
    git commit -m "feat(operator): skenario 4/5/7 + untung bersih dashboard + prediksi habis dodol"
    git push origin main

## STOP POINTS

1. delivery_type bukan enum → adjust migration approach
2. SettlementObserver reject settlement untuk bs_redistribution
3. qty_carried validation logic sulit di-isolate → lapor sebelum ubah
4. Test turun dari 107 PASS

JANGAN auto-decide. Lapor dulu.

Output: ringkas per step + test status. No narasi panjang.

Mulai sekarang.

--- END OF BRIEF ---
