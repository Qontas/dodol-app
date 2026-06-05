# NEXT_SESSION.md — Dodol-App
*Sesi terakhir: 05 Juni 2026*

## TRIGGER SENTENCE SESI BERIKUTNYA

Bg, lanjut dodol-app. 45 PASS, 113 assertions.
Core operator flow + refactor operasional bebas selesai.
GitHub: Qontas/dodol-app (synced, HEAD: 90b84c2).
PRIORITAS: manual test full flow di browser + input data real 217 kios.
Baca NEXT_SESSION.md untuk context lengkap.

## STATUS TERAKHIR

Git log:
- 90b84c2 refactor(operator): operasional bebas — hapus FIFO block, tambah qty_carried
- 9e928c5 chore: remove all temporary prompt files
- 0c6487f chore: remove temporary prompt files
- d0993ed docs: Day 6 closed — next session context updated

Test: 45 PASS, 113 assertions.
GitHub: https://github.com/Qontas/dodol-app

## OPERATOR FLOW — COMPLETED

Semua core operator flow sudah selesai:
- saveVisit() 4 aksi (drop_and_settle, drop_only, settle_only, check_only)
- Extension granted (max 2x, warning cut off)
- End trip (wajib pilih alasan, summary: dibawa/drop/sisa/uang)
- qty_carried input di StartTrip
- FIFO block dihapus (operasional bebas)
- Map picker untuk GPS kios

## PRIORITAS SESI BERIKUTNYA

### PRIORITAS 1: Manual Test Full Flow (BLOCKING)
Test di browser sebelum input data real:
1. Login operator → /operator/dashboard
2. Pastikan ada: 1 Cluster, 1 Kios, 1 ProductVariant aktif, 1 ProcurementBatch
3. StartTrip: input qty_carried → pilih cluster → Mulai Trip
4. Tap kios → isi form visit → Simpan Kunjungan (verify kiosk_visits count bertambah)
5. Akhiri Trip → pilih alasan → Konfirmasi → verify redirect ke dashboard
6. php artisan tinker verify: KioskVisit::count(), Delivery::count(), Settlement::count()

### PRIORITAS 2: Input Data Real
Input 217 kios + cluster + supplier via Filament admin.
Pakai map picker untuk GPS tiap kios.

### PRIORITAS 3: Owner Dashboard Widget
Tambah widget statistik di /owner/dashboard:
- Omset hari ini
- Kios overdue (belum dikunjungi > target_visit_interval_days)
- Total outstanding (settlement pending)

### PRIORITAS 4: Fitur Operator Input Kios Baru
Operator (Rian) butuh input kios baru langsung di lapangan tanpa harus ke admin panel.
Yang dibutuhkan:
- Form sederhana di operator side (/operator/kiosks/create atau modal di dashboard)
- Field: nama kios, nama pemilik, telepon, cluster, lokasi (map picker), default qty mika
- Setelah save: kios langsung aktif dan bisa dikunjungi di trip berikutnya

### PRIORITAS 5: Import Kios via Excel/CSV
Untuk bulk import data kios dari spreadsheet existing.
Field mapping: nama_kios, nama_pemilik, telepon, cluster, alamat, lat, lng, default_qty_mika

## BUSINESS RULES LOCKED

- 1 mika = 15 biji
- Settlement qty dalam BIJI, delivery qty_delivered dalam MIKA
- SettlementObserver: sum(biji) === qty_delivered * 15
- Harga: Rp 800/biji = Rp 12.000/mika
- HPP variable per batch (yield 68-75 mika/batch, tergantung ukuran dodol)
- cost_snapshot = null untuk delivery tanpa batch link (Phase 1 acceptable)
- Rule B: bonus reconciliation HPP=0
- Extension max 2x per delivery → warning cut off
- End trip wajib pilih alasan (5 opsi)
- procurement_batch_id nullable (operasional bebas)

## KNOWN ISSUES

1. saveVisit belum ada feature test Livewire (manual test dulu)
2. pendingDelivery query cross-trip (sengaja — delivery lama yang belum settle tetap muncul)
3. Batch is_exhausted belum ada (FIFO skip batch stok 0 otomatis)
4. extension_granted hardcode false untuk check_only + drop_only (by design)

## TECH STACK

- Laravel 11.52.0, PHP 8.2.12 (XAMPP)
- Filament v3.3.50, Livewire 3.8, Breeze v2.4.1
- MariaDB 10.4.32, dotswan/filament-map-picker ^1.8
- Working dir: C:\Users\Qontas\Projects\dodol-app

## DAILY DEV ROUTINE

1. XAMPP Control Panel → Start MySQL (WAJIB — silent fail kalau lupa)
2. php artisan serve (Terminal A)
3. npm run dev (Terminal B)
4. php artisan optimize:clear (kalau mau edit code)

## LOGIN CREDENTIALS

- Owner: owner@cemilanqontas.id / password → /owner/dashboard → /admin
- Operator: operator@cemilanqontas.id / password → /operator/dashboard
