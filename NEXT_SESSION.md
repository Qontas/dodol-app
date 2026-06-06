# NEXT_SESSION.md — Dodol-App
*Sesi terakhir: 06 Juni 2026*

## TRIGGER SENTENCE
Bg, lanjut dodol-app. 45 PASS. Owner dashboard widget + operator kios baru selesai.
GitHub: Qontas/dodol-app synced. PRIORITAS: manual test full flow + input 217 kios real.

## STATUS TERAKHIR
- feat(owner): dashboard widgets — omset, overdue, outstanding
- feat(operator): input kios baru dari lapangan + leaflet map
- refactor(operator): operasional bebas — hapus FIFO block, tambah qty_carried
- Test: 45 PASS, 113 assertions
- GitHub: https://github.com/Qontas/dodol-app

## PRIORITAS SESI BERIKUTNYA
1. Manual test full flow browser (BLOCKING sebelum input data real)
2. Input 217 kios real via /admin/kiosks (Filament) atau /operator/kiosks/create
3. Import kios via Excel/CSV (fitur bulk input)
4. Owner analytics lanjutan (chart omset mingguan/bulanan)

## BUSINESS RULES LOCKED
- 1 mika = 15 biji, Rp 800/biji = Rp 12.000/mika
- Settlement qty BIJI, delivery qty_delivered MIKA
- HPP variable per batch (yield 68-75 mika/batch)
- cost_snapshot null untuk delivery tanpa batch link
- Rule B: bonus reconciliation HPP=0
- Extension max 2x → warning cut off
- End trip wajib pilih alasan (5 opsi)
- procurement_batch_id nullable (operasional bebas)
- Overdue = lewat target_visit_interval_days per kios (default 10 hari)
- Outstanding = sum(amount_due - amount_paid) settlements pending
- Omset = amount_paid settlements visit_date hari ini

## KNOWN ISSUES
1. saveVisit belum ada feature test Livewire
2. Map picker public assets harus di-copy manual kalau deploy baru
3. MariaDB XAMPP harus di-start manual setiap sesi

## TECH STACK
- Laravel 11.52.0, PHP 8.2.12 (XAMPP)
- Filament v3.3.50, Livewire 3.8, Breeze v2.4.1
- MariaDB 10.4.32, dotswan/filament-map-picker v1.8.8
- Working dir: C:\Users\Qontas\Projects\dodol-app

## DAILY DEV ROUTINE
1. XAMPP → Start MySQL (WAJIB)
2. php artisan serve
3. npm run dev
4. php artisan optimize:clear (kalau edit code)

## LOGIN CREDENTIALS
- Owner: owner@cemilanqontas.id / password
- Operator: operator@cemilanqontas.id / password
