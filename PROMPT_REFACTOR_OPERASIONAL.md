# Brief: Refactor Operasional Bebas — Hapus FIFO Block + qty_carried

## KONTEKS

Day 7 dodol-app. 45 PASS, 114 assertions.
Owner request: operator tidak di-block stok batch. Batch = catatan owner saja.
Rian input manual berapa mika dibawa sebelum berangkat (qty_carried).

## BUSINESS RULES (LOCKED)

- procurement_batch_id di deliveries = NULLABLE (operator tidak wajib link ke batch)
- DeliveryObserver: new_procurement boleh tanpa batch (relax constraint)
- qty_carried = input manual Rian di StartTrip (berapa mika dibawa hari ini)
- End trip summary: Dibawa X mika, Drop Y mika, Sisa Z mika (Z = X - Y)
- Semua business rules lain tetap (1 mika=15 biji, settlement biji, Rule B, extension, dll)

## PERUBAHAN YANG DIPERLUKAN (7 item)

### 1. Migration: tambah qty_carried ke trips

Cek dulu apakah qty_carried sudah ada:
php artisan tinker --execute="echo implode(',', \Schema::getColumnListing('trips'));"

Kalau belum ada: buat migration add_qty_carried_to_trips_table

- qty_carried smallint nullable (null = belum diisi saat trip lama)

Kalau sudah ada: skip migration.

### 2. Trip model

Tambah qty_carried ke $fillable.

### 3. StartTrip.php

Tambah property: public int $qtyCarried = 0;

Tambah input SEBELUM tombol "Mulai Trip Sekarang":

- Label: "Berapa mika yang kamu bawa hari ini?"
- Input number, wire:model.live="qtyCarried", min=0
- Validasi: wajib > 0 sebelum bisa klik "Mulai Trip Sekarang"
- Kalau qtyCarried = 0: tombol disabled + pesan "Isi jumlah mika dulu"

Update startTrip() method:

- Validate qtyCarried > 0
- Simpan ke trips: 'qty_carried' => $this->qtyCarried

### 4. saveVisit() — Hapus FIFO block

Di ActiveTrip.php method saveVisit():

HAPUS:

- Method resolveFifoBatch() (seluruh method)
- Panggilan $batch = $isDrop ? $this->resolveFifoBatch($drop) : null;
- try/catch RuntimeException untuk stok tidak cukup
- 'procurement_batch_id' => $batch->id di Delivery::create()

GANTI di Delivery::create():

- 'procurement_batch_id' => null

Semua field lain di Delivery::create() TETAP:

- kiosk_id, trip_id, product_variant_id, source_type, delivery_type
- qty_delivered, unit_price, cost_snapshot

resolveActiveVariant() TETAP ADA (masih dipakai untuk unit_price + cost_snapshot).

### 5. DeliveryObserver — Relax constraint

Di app/Observers/DeliveryObserver.php:

Method requireProcurementBatch() saat ini throw kalau procurement_batch_id null.
UBAH: untuk new_procurement, procurement_batch_id boleh null (tidak throw).

Hapus atau comment out requireProcurementBatch() call dari validateFields().

TETAP: forbidProcurementBatch() untuk fresh_return_redeploy (batch tetap dilarang).

### 6. End trip summary — tambah qty sisa

Di ActiveTrip.php method openEndTripModal():

Tambah ke tripSummary:

- 'qty_carried' => $this->trip->qty_carried ?? 0
- 'total_mika_sisa' => ($this->trip->qty_carried ?? 0) - (int) Delivery::where('trip_id', $this->trip->id)->sum('qty_delivered')

Di view modal end trip, tambah 2 baris ke ringkasan:

- "Dibawa: X mika"
- "Sisa: X mika" (warna merah kalau negatif, hijau kalau >= 0)

### 7. Test update

Cek test yang pakai procurement_batch_id + requireProcurementBatch:

php artisan test --compact 2>&1 | Select-Object -Last 5

Kemungkinan test yang perlu diupdate:

- DeliveryObserverTest: test_new_procurement_without_batch_throws → UBAH jadi PASSES (batch nullable OK)
- DeliveryFactory: procurement_batch_id tetap ada di default (owner masih bisa link batch), tapi observer tidak reject kalau null

Target: 45+ PASS setelah refactor.

## PENDINGDELIVERY QUERY FIX (KNOWN ISSUE)

Di openVisitModal(), pendingDelivery query:
Delivery::where('kiosk_id', $kioskId)->doesntHave('settlement')->latest('id')->first()

Tambah filter trip_id untuk avoid cross-trip confusion:
TIDAK — ini sengaja cross-trip (delivery dari trip sebelumnya yang belum di-settle tetap muncul).
Biarin query existing, jangan ubah.

## STEP EKSEKUSI

1. Cek schema trips (qty_carried ada/belum)
2. Migration kalau perlu + php artisan migrate
3. Update Trip model ($fillable)
4. Update StartTrip.php (property + input + validation + startTrip method)
5. Update ActiveTrip.php saveVisit() (hapus FIFO, procurement_batch_id = null)
6. Update DeliveryObserver (relax new_procurement constraint)
7. Update tripSummary + view modal end trip
8. php artisan test --compact (target 45+ PASS)
9. Commit:
   git add app/ database/migrations/ resources/views/livewire/operator/
   git commit -m "refactor(operator): operasional bebas — hapus FIFO block, tambah qty_carried"

## STOP POINTS — TANYA ADVISOR KALAU

1. qty_carried sudah ada di schema dengan tipe berbeda
2. Test turun dari 45 PASS setelah relax observer
3. resolveActiveVariant() error karena ProductVariant belum ada di DB
4. Ada test lain yang depend on requireProcurementBatch throwing

JANGAN auto-decide business logic. Lapor dulu.

Output: ringkas per step + test status. No narasi panjang.

Mulai sekarang.

--- END OF BRIEF ---
