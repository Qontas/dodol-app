# Brief: Wire saveVisit() — ActiveTrip Livewire

## KONTEKS

Day 6 dodol-app. saveVisit() di ActiveTrip.php saat ini NO-OP (cuma tutup modal, tidak simpan ke DB).
Tugas: wire saveVisit() agar nulis ke 3 tabel dalam 1 DB transaction.
45 tests PASS sebelum ini — jangan sampai turun.

## BUSINESS RULES (LOCKED)

- Produk: 1 jenis dodol, 1 varian aktif (auto-resolve, operator tidak pilih)
- Procurement source: FIFO — ambil batch tertua yang masih punya stok (qty_delivered sum < batch qty)
- Satuan DROP: per MIKA (operator input mika, simpan ke deliveries.qty_delivered dalam mika)
- Satuan BS/RETURN + SOLD: per BIJI (returnFresh, returnExpired, terjual = biji)
- Konversi: 1 mika = 15 biji (gunakan konstanta BIJI_PER_MIKA = 15, HARGA_PER_BIJI = 800)
- tagihan (amount_due) = terjual × 800 (per biji)
- amount_paid = uangDiterima (yang operator input)
- Settlement status: 'paid' kalau amount_paid >= amount_due, else 'pending'
- visit_date = today()

## EXISTING STATE (verified audit)

Schema kiosk_visits: id,trip_id,kiosk_id,visited_at,visit_action,new_delivery_id,settled_delivery_id,extension_granted,notes,created_at,updated_at
Schema deliveries: id,kiosk_id,trip_id,product_variant_id,procurement_batch_id,source_type,delivery_type,qty_delivered,unit_price,cost_snapshot,notes,created_at,updated_at
Schema settlements: id,delivery_id,visit_date,qty_sold,qty_returned_fresh,qty_returned_expired,amount_due,amount_paid,paid_at,status,notes,created_at,updated_at

Props existing di ActiveTrip (JANGAN rename, pakai apa adanya):

- selectedKiosk (Kiosk model)
- pendingDelivery (Delivery|null — delivery yang belum ada settlement)
- returnFresh (int, biji)
- returnExpired (int, biji)
- dropBaru (int, mika)
- uangDiterima (int, rupiah)
- terjual (int, biji) — computed dari qty_delivered\*15 - returnFresh - returnExpired
- tagihan (int, rupiah) — computed dari terjual \* 800

Magic numbers saat ini hardcode di hitungTagihan():

- 15 biji/mika
- 800/biji
  Pindahkan ke konstanta di atas class atau config — JANGAN hardcode di saveVisit().

## 4 VISIT ACTION (semua di-wire, bukan cuma 2)

### drop_and_settle (PALING UMUM)

Kondisi: selectedKiosk punya pendingDelivery + operator input dropBaru > 0
Flow:

1. Settle pendingDelivery → buat row settlements (qty_sold, qty_returned_fresh, qty_returned_expired, amount_due, amount_paid, paid_at, status, visit_date)
2. Buat Delivery baru (new_procurement, consignment) → resolve FIFO batch
3. Buat KioskVisit (visit_action=drop_and_settle, new_delivery_id=delivery baru, settled_delivery_id=pendingDelivery.id, visited_at=now())

### drop_only (kios baru / belum ada pending)

Kondisi: pendingDelivery NULL + dropBaru > 0
Flow:

1. Buat Delivery baru saja (new_procurement, consignment)
2. Buat KioskVisit (visit_action=drop_only, new_delivery_id=delivery baru, settled_delivery_id=NULL)
   (TIDAK buat settlement)

### settle_only (ambil BS, tidak drop baru)

Kondisi: pendingDelivery NOT NULL + dropBaru = 0
Flow:

1. Settle pendingDelivery → buat settlements
2. Buat KioskVisit (visit_action=settle_only, settled_delivery_id=pendingDelivery.id, new_delivery_id=NULL)

### check_only (kunjungan tanpa transaksi)

Kondisi: dropBaru = 0 + pendingDelivery NULL (atau operator skip semua input)
Flow:

1. Buat KioskVisit saja (visit_action=check_only, new_delivery_id=NULL, settled_delivery_id=NULL)
   (TIDAK buat delivery maupun settlement)

## FIFO BATCH RESOLVER

Buat private method resolveFifoBatch(int $qty_mika): ProcurementBatch

Logic:

- Ambil batch aktif (is_active=true atau available) ordered by created_at ASC
- Untuk tiap batch, hitung stok terpakai: sum deliveries.qty_delivered WHERE procurement_batch_id = batch.id
- Ambil batch pertama yang masih punya sisa stok >= $qty_mika
- Kalau tidak ada batch cukup → throw \RuntimeException("Stok tidak cukup untuk drop $qty_mika mika")
- Cek kolom yang ada di procurement_batches schema sebelum query

Cek schema procurement_batches dulu:
php artisan tinker --execute="echo implode(',', \Schema::getColumnListing('procurement_batches'));"

Sesuaikan query dengan kolom actual.

## RULE B ENFORCEMENT (Gap dari kemarin)

Untuk delivery source_type=fresh_return_redeploy:

- Setelah attach delivery_origins, panggil $delivery->validateOrigins() EKSPLISIT
- Jangan andalkan Eloquent updated event (sudah diketahui tidak fire pada pivot-only attach)

Untuk saveVisit: semua delivery yang dibuat = new_procurement (bukan fresh_return_redeploy)
→ Rule B tidak apply di saveVisit
→ TAPI DeliveryObserver akan reject kalau kita tidak isi procurement_batch_id untuk new_procurement
→ Pastikan procurement_batch_id terisi dari FIFO resolver sebelum Delivery::create()

## VALIDASI SEBELUM SAVE (guard di saveVisit())

1. selectedKiosk tidak null
2. Kalau dropBaru > 0: ada batch cukup stok (FIFO check dulu, jangan buat delivery dulu)
3. Kalau pendingDelivery ada + settle: returnFresh + returnExpired <= pendingDelivery.qty_delivered \* 15
4. terjual >= 0 (tidak negatif)
5. uangDiterima >= 0

Kalau validasi gagal: $this->addError() dengan pesan bahasa Indonesia, JANGAN throw exception ke user.

## DB TRANSACTION

Semua operasi dalam 1 transaction:
DB::transaction(function() {
// 1. settle jika ada
// 2. buat delivery baru jika ada
// 3. buat kiosk_visit
// 4. refresh kiosks list
// 5. reset form + tutup modal
});

Kalau transaction gagal (exception): catch, $this->addError('general', 'Gagal menyimpan. Coba lagi.')
JANGAN rollback manual — DB::transaction() auto-rollback.

## PRODUCT VARIANT RESOLVER

1 jenis dodol, 1 varian aktif. Buat private method resolveActiveVariant(): ProductVariant

- ProductVariant::where('is_active', true)->firstOrFail()
- Kalau tidak ada: throw \RuntimeException("Tidak ada varian produk aktif")
- unit_price diambil dari ProductVariant (kolom harga jual — cek schema product_variants dulu)
- cost_snapshot diambil dari ProcurementBatch yang dipilih FIFO

Cek schema product_variants:
php artisan tinker --execute="echo implode(',', \Schema::getColumnListing('product_variants'));"

## VIEW (active-trip.blade.php)

Tambahkan di form modal visit:

- Input dropBaru (number, label "Drop Baru (mika)") — hanya show kalau visit bukan check_only
- Input returnFresh (number, label "Retur Bagus (biji)")
- Input returnExpired (number, label "Retur Rusak/Exp (biji)")
- Input uangDiterima (number, label "Uang Diterima (Rp)")
- Display readonly: Terjual = {terjual} biji, Tagihan = Rp {tagihan}
- Display readonly: Visit action yang akan dilakukan (auto-detect dari kondisi)
- Tombol "Simpan Kunjungan" → wire:click="saveVisit"
- Error display: @error('general') + @error per field

## STEP EKSEKUSI

1. Cek schema procurement_batches + product_variants (tinker, 2 command)
2. Update app/Livewire/Operator/ActiveTrip.php:
    - Tambah konstanta BIJI_PER_MIKA + HARGA_PER_BIJI
    - Buat resolveActiveVariant()
    - Buat resolveFifoBatch()
    - Wire saveVisit() dengan 4 action + validation + DB transaction
3. Update resources/views/livewire/operator/active-trip.blade.php (form modal)
4. php artisan test → target 45+ PASS
5. Commit:
   git add app/Livewire/Operator/ActiveTrip.php resources/views/livewire/operator/active-trip.blade.php
   git commit -m "feat(operator): wire saveVisit() — 4 visit actions + FIFO batch + DB transaction"

## STOP POINTS — TANYA ADVISOR KALAU

1. Schema procurement_batches tidak punya kolom stok/qty yang diharapkan
2. Schema product_variants tidak punya kolom harga jual
3. DeliveryObserver reject delivery new_procurement (procurement_batch_id issue)
4. Test turun dari 45 PASS
5. Kolom kiosk_visits.extension_granted tidak clear kegunaannya — tanya advisor

JANGAN auto-decide untuk hal yang menyentuh business logic. Lapor dulu.

Output akhir: ringkas per step + test status. No narasi panjang.

Mulai sekarang.

--- END OF BRIEF ---
