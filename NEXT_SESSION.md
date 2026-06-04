# NEXT_SESSION.md — Dodol-App
*Day 6 closed: 04 Juni 2026*

## TRIGGER SENTENCE SESI BERIKUTNYA

Paste ini ke chat Claude baru:

Bg, lanjut dodol-app. Day 6 closed dengan 8 commits bersih, 45 PASS.
Core operator flow selesai (saveVisit, end trip, extension granted, map picker).
GitHub: Qontas/dodol-app (synced).
PRIORITAS: refactor trip operasional bebas — hapus FIFO block, tambah qty_carried di StartTrip.
Baca NEXT_SESSION.md untuk context lengkap.

## PRIORITAS SESI BERIKUTNYA — REFACTOR OPERASIONAL BEBAS (BLOCKING)

Owner request: operator tidak di-block stok batch. Batch = catatan owner saja.

Yang harus diubah:
1. trips: tambah qty_carried (mika dibawa) — input di StartTrip sebelum berangkat
2. StartTrip.php: tambah input qty_carried sebelum tombol "Mulai Trip Sekarang"
3. saveVisit: hapus resolveFifoBatch() + hapus guard stok
4. delivery.procurement_batch_id = nullable (operator tidak perlu link ke batch)
5. DeliveryObserver: relax constraint new_procurement (batch nullable OK)
6. End trip summary: tambah "Dibawa X mika, Drop Y mika, Sisa Z mika"
7. Test update: factory + observer sesuaikan nullable batch

Target: Rian input berapa dibawa, berapa di-drop, sistem catat sisa otomatis.
Estimasi: 1-2 sesi kerja.

## STATUS TERAKHIR

Git log terbaru:
- b9757b1 feat(admin): map picker for kiosk GPS input
- c6351c0 feat(operator): extension granted — tunda settle + warning cut off
- 0c30cda feat(operator): end trip flow — summary + reason + redirect
- 744bfba feat(operator): wire saveVisit() — 4 visit actions + FIFO batch
- f889447 refactor(accounting): delivery_origins pivot Rule B
- 47bd56f feat(operator): persist starting_cluster_id to trips table
- 6ead078 feat(admin): redirect to list page after create
- 63e4c0c feat(auth): unified login

Test: 45 PASS, 114 assertions.
GitHub: https://github.com/Qontas/dodol-app

## BUSINESS RULES LOCKED

- 1 mika = 15 biji
- Settlement qty dalam BIJI, delivery qty_delivered dalam MIKA
- SettlementObserver: sum(biji) === qty_delivered x 15
- Harga: Rp 800/biji = Rp 12.000/mika
- Rule B: bonus reconciliation HPP=0
- Extension max 2x per delivery → warning cut off
- End trip wajib pilih alasan (5 opsi)
- qty_carried input saat StartTrip
- procurement_batch_id nullable (operasional bebas)

## TECH STACK

- Laravel 11.52.0, PHP 8.2.12 (XAMPP)
- Filament v3.3.50, Livewire 3.8, Breeze v2.4.1
- MariaDB 10.4.32, dotswan/filament-map-picker ^1.8
- Working dir: C:\Users\Qontas\Projects\dodol-app

## KNOWN ISSUES

1. FIFO block masih aktif → RESOLVE via refactor operasional (prioritas utama)
2. pendingDelivery query pakai latest('id') tanpa filter trip_id → fix saat refactor
3. saveVisit belum ada feature test Livewire

## DAILY DEV ROUTINE

1. XAMPP Control Panel → Start MySQL (WAJIB — silent fail kalau lupa)
2. php artisan serve (Terminal A)
3. npm run dev (Terminal B)
4. php artisan optimize:clear (kalau mau edit code)

## LOGIN CREDENTIALS

- Owner: owner@cemilanqontas.id / password → /owner/dashboard → /admin
- Operator: operator@cemilanqontas.id / password → /operator/dashboard