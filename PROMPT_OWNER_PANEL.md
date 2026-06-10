# Brief: Owner Filament Panel + Super Admin Enhancement + UI Fixes

## KONTEKS

104 PASS. Refactor besar + UI fixes + super admin enhancement.
Jangan break test yang ada.

## TASK 1: Owner Filament Panel (OwnerPanelProvider)

### Buat panel baru di /owner-panel

```bash
php artisan make:filament-panel owner
```

### app/Providers/Filament/OwnerPanelProvider.php

- Path: /owner-panel
- Auth guard: web
- Login page: default Filament login
- Middleware: auth, role:owner
- Colors: amber (primary)
- Brand: "Cemilan Qontas — Owner"
- Dark mode: disabled

### Resources yang masuk ke Owner Panel:

Pindahkan (BUKAN copy) semua Resource berikut ke owner panel:

- KioskResource → app/Filament/Owner/Resources/KioskResource.php
- ClusterResource → app/Filament/Owner/Resources/ClusterResource.php
- SupplierResource → app/Filament/Owner/Resources/SupplierResource.php
- ProcurementBatchResource → app/Filament/Owner/Resources/ProcurementBatchResource.php
- OperatorResource → app/Filament/Owner/Resources/OperatorResource.php

### Multi-tenancy di Owner Panel:

Setiap query di owner panel harus di-scope ke auth()->id():

- KioskResource: whereHas('cluster', fn($q) => $q->where('owner_id', auth()->id()))
- ClusterResource: where('owner_id', auth()->id())
- SupplierResource: where('owner_id', auth()->id())
- ProcurementBatchResource: where('owner_id', auth()->id())
- OperatorResource: where('owner_id', auth()->id())

### Saat create/edit di Owner Panel:

- owner_id otomatis di-set ke auth()->id()
- Jangan tampilkan field owner_id di form

### STOP POINT 1:

Kalau ada Resource yang punya global scope BelongsToOwner yang sudah handle ini otomatis,
lapor dulu sebelum tambah manual scope.

## TASK 2: Fix Sidebar Owner Dashboard

### Update resources/views/layouts/owner.blade.php

Ganti semua link filament.admin._ di $nav dengan link filament.owner._:

```php
$nav = [
    ['label' => 'Dashboard', 'route' => 'owner.dashboard', 'icon' => '📊'],
    ['label' => 'Manajemen Kios', 'route' => 'filament.owner.resources.kiosks.index', 'icon' => '🏪'],
    ['label' => 'Manajemen Cluster', 'route' => 'filament.owner.resources.clusters.index', 'icon' => '📍'],
    ['label' => 'Manajemen Supplier', 'route' => 'filament.owner.resources.suppliers.index', 'icon' => '🏭'],
    ['label' => 'Manajemen Anggota', 'route' => 'filament.owner.resources.operators.index', 'icon' => '👥'],
    ['label' => 'Procurement', 'route' => 'filament.owner.resources.procurement-batches.index', 'icon' => '📦'],
    ['label' => 'Laporan & Analitik', 'route' => 'owner.reports.monthly', 'icon' => '📋'],
    ['label' => 'Settings', 'route' => 'owner.settings', 'icon' => '⚙️'],
];
```

Hapus link "Admin Panel" dari sidebar owner — owner tidak perlu akses admin panel.

### STOP POINT 2:

Verifikasi route names owner panel setelah OwnerPanelProvider dibuat.
Jalankan: php artisan route:list | grep filament.owner
Sesuaikan nama route di $nav dengan output tersebut.

## TASK 3: Fix Mobile Layout

### resources/views/livewire/operator/active-trip.blade.php

Tambah padding bottom agar konten tidak tertutup bottom nav:

- Root div: tambah class `pb-24` atau `pb-28`

### resources/views/livewire/operator/start-trip.blade.php

- Root div: tambah class `pb-24`

### Bottom nav active state:

Pastikan bottom nav items cukup besar untuk di-tap (min h-12, text tidak terpotong):

- Setiap item bottom nav: min-height 48px, font-size text-xs minimum
- Label bottom nav: gunakan bahasa Indonesia yang pendek

## TASK 4: Ganti Kata Teknis → Bahasa Sehari-hari

### Mapping penggantian:

- "Outstanding" → "Belum Bayar"
- "Overdue" → "Perlu Dikunjungi"
- "Procurement" → "Stok Masuk" (di sidebar owner)
- "Cluster" → "Area" (di tampilan operator/owner dashboard)
- "Settlement" → "Pembayaran" (di tampilan operator)
- "HPP Estimasi" → "Modal Dodol"
- "Untung Kotor" → "Keuntungan"
- "Qty" → "Jumlah"
- "Trip Bebas" → tetap (sudah jelas)

### File yang perlu diupdate:

- resources/views/owner/dashboard.blade.php (widget labels)
- resources/views/livewire/operator/active-trip.blade.php (form labels)
- resources/views/livewire/operator/start-trip.blade.php
- resources/views/layouts/owner.blade.php (sidebar labels)

## TASK 5: Super Admin Dashboard Enhancement

### app/Filament/Widgets/SuperAdminStatsOverview.php

Tambah stats:

- Total Komisi Global Hari Ini (sudah ada, pastikan benar)
- Rata-rata Omset per Owner Hari Ini

### app/Filament/Widgets/OwnerPerformanceTable.php

Tambah kolom:

- Komisi per Operator (sum commission per operator milik owner hari ini)
- Total Trip Bulan Ini (bukan hanya hari ini)
- Omset Bulan Ini

### Buat widget baru: app/Filament/Widgets/OwnerOmsetChart.php

Filament Chart Widget (line chart):

- Data: omset 30 hari terakhir per owner (top 3 owner)
- X axis: tanggal (30 hari)
- Y axis: omset (Rp)
- Hanya tampil di admin panel
- canView(): hanya super_admin

### Buat widget baru: app/Filament/Widgets/OperatorKomisiTable.php

Filament Table Widget:

- List semua operator aktif + komisi bulan ini
- Kolom: Nama Operator, Owner, Trip Bulan Ini, Komisi Bulan Ini
- Sort by komisi DESC
- Hanya tampil di admin panel
- canView(): hanya super_admin

### Register semua widget baru di AdminPanelProvider:

```php
->widgets([
    SuperAdminStatsOverview::class,
    OwnerOmsetChart::class,
    OwnerPerformanceTable::class,
    OperatorKomisiTable::class,
])
```

## TASK 6: Skenario Konsinyasi Tambah Default

### Business Rule (LOCKED):

- Rian drop 5 mika di kios default 4 mika
- Pemilik minta KONSINYASI SEMUA (bukan cash)
- Sistem buat 1 delivery konsinyasi 5 mika (tidak di-split)
- default_qty_mika kios otomatis update ke 5

### Update active-trip.blade.php:

Saat dropBaru > default_qty_mika (dan bukan cash_only), tampilkan pilihan:

```html
@if(!$isCashOnly && (int)$dropBaru > (int)$selectedKiosk->default_qty_mika)
<div class="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-4">
    <p class="text-sm font-bold text-amber-800 mb-3">
        Drop melebihi default ({{ $selectedKiosk->default_qty_mika }} mika).
        Kelebihan {{ (int)$dropBaru - (int)$selectedKiosk->default_qty_mika }}
        mika:
    </p>
    <div class="space-y-2">
        <label class="flex items-center gap-3 cursor-pointer">
            <input
                type="radio"
                wire:model.live="extraDropMode"
                value="cash"
                class="text-amber-600"
            />
            <span class="text-sm text-slate-700"> 💵 Bayar cash sekarang </span>
        </label>
        <label class="flex items-center gap-3 cursor-pointer">
            <input
                type="radio"
                wire:model.live="extraDropMode"
                value="konsinyasi"
                class="text-amber-600"
            />
            <span class="text-sm text-slate-700">
                📦 Tambah konsinyasi + naikkan default ke {{ $dropBaru }} mika
            </span>
        </label>
    </div>
</div>
@endif
```

### Update ActiveTrip.php:

Tambah property:

```php
public string $extraDropMode = 'cash'; // default: cash (backward compat)
```

Update $hasCashExtra logic:

```php
$hasCashExtra = $isDrop && $extraQty > 0 && $defaultQty > 0
    && $this->extraDropMode === 'cash';
$isKonsinyasiFull = $isDrop && $extraQty > 0 && $defaultQty > 0
    && $this->extraDropMode === 'konsinyasi';
```

Kalau $isKonsinyasiFull = true:

- Buat 1 delivery konsinyasi dengan qty = $drop (full, tidak di-split)
- Update kiosk->default_qty_mika = $drop
- Flash: "Kunjungan disimpan. Default kios diperbarui ke {$drop} mika."

Reset extraDropMode ke 'cash' setelah saveVisit().

### STOP POINT 3:

Kalau update default_qty_mika di dalam transaction gagal (misal ada observer),
lapor dulu sebelum lanjut.

## STEP EKSEKUSI (URUTAN WAJIB)

1. TASK 6 dulu (paling simpel, tidak ada dependency)
2. TASK 3 + TASK 4 (UI fixes, tidak ada dependency)
3. TASK 5 (super admin widgets)
4. TASK 1 + TASK 2 (OwnerPanelProvider — paling kompleks, terakhir)

Setelah semua task:
php artisan test --compact (target 104+ PASS)
git add .
git commit -m "feat(major): owner filament panel + super admin charts + UI fixes + konsinyasi upgrade"
git push origin main

## STOP POINTS GLOBAL

1. BelongsToOwner global scope sudah handle owner scoping → lapor sebelum tambah manual
2. Route names owner panel berbeda dari yang diasumsikan → cek dengan route:list
3. Test turun dari 104 PASS → stop dan lapor
4. OwnerPanelProvider conflict dengan AdminPanelProvider → lapor dulu

JANGAN auto-decide business logic atau arsitektur.
Lapor setiap STOP POINT sebelum lanjut.

Output: ringkas per task + test status. No narasi panjang.

Mulai dari TASK 6 sekarang.

--- END OF BRIEF ---
