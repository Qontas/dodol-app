# NEXT_SESSION.md — Dodol-App
*Sesi terakhir: 07 Juni 2026*

## TRIGGER SENTENCE
Bg, lanjut dodol-app. 48 PASS. List kios per cluster + GPS + foto kios selesai.
GitHub: Qontas/dodol-app synced, HEAD: cc46dd4.
PRIORITAS: Import Kios Excel/CSV → Deploy.

## STATUS TERAKHIR
- feat(owner): Laporan trip real-time di owner dashboard (progress live Rian)
- feat(operator/owner): End-trip report (HPP estimasi & Komisi Rian 20%)
- feat(admin): Stock tracking mika per batch di Filament
- feat(operator): Nearest Neighbor (sort kios by jarak GPS)
- feat(owner): Analytics chart (grafik omset 30 hari terakhir)
- cc46dd4 feat(operator): list kios per cluster — visited status + tap to visit
- dcdc04a feat(kiosk): GPS link navigasi + foto kios di admin dan operator
- abf6fd6 fix(db): cascade delete kiosk — auto hapus deliveries, visits, settlements
- 73a8fba feat(operator): input kios baru dari lapangan + leaflet map
- adbbdd9 feat(owner): dashboard widgets — omset, overdue, outstanding
- Test: 51 PASS, 145 assertions
- GitHub: https://github.com/Qontas/dodol-app

## OPERATOR & OWNER FLOW — COMPLETED
- Real-time Trip progress & visits timeline di Owner Dashboard
- Estimasi HPP Trip & Komisi 20% Rian (disimpan ke DB saat End Trip)
- Stock tracking mika sisa per batch (`qty_packs - sum(qty_delivered)`) di Filament
- Nearest Neighbor (Urutkan by Jarak via Browser Geolocation + Haversine formula)
- Analytics Chart Owner (Grafik omset harian 30 hari terakhir via Chart.js)
- saveVisit() 4 aksi (drop_and_settle, drop_only, settle_only, check_only)
- Extension granted (max 2x, warning cut off)
- End trip (wajib pilih alasan, summary: dibawa/drop/sisa/uang)
- qty_carried input di StartTrip
- List kios per cluster (badge visited/pending, tap-card, sort belum-dulu)
- Input kios baru dari lapangan (Leaflet map)
- GPS navigasi ke kios (Google Maps link)
- Foto kios di modal visit

## PRIORITAS SESI BERIKUTNYA

### PRIORITAS 1: Import Kios Excel/CSV
Bulk input 217 kios dari spreadsheet.
- Form upload CSV di Filament admin
- Field mapping: nama, pemilik, cluster, qty_mika, telepon, alamat, lat, lng

### PRIORITAS 2: Deploy
- VPS atau Railway
- Setup setelah semua fitur core selesai

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
2. Map picker public assets harus di-copy manual kalau deploy baru:
   Copy-Item vendor/dotswan/filament-map-picker/resources/dist/filament-map-picker.js public/js/dotswan/filament-map-picker/filament-map-picker-scripts.js
   Copy-Item vendor/dotswan/filament-map-picker/resources/dist/filament-map-picker.css public/css/dotswan/filament-map-picker/filament-map-picker-styles.css
3. MariaDB XAMPP harus di-start manual setiap sesi (silent fail kalau lupa)

## TECH STACK
- Laravel 11.52.0, PHP 8.2.12 (XAMPP)
- Filament v3.3.50, Livewire 3.8, Breeze v2.4.1
- MariaDB 10.4.32, dotswan/filament-map-picker v1.8.8
- Working dir: C:\Users\Qontas\Projects\dodol-app

## DAILY DEV ROUTINE
1. XAMPP → Start MySQL (WAJIB — silent fail kalau lupa)
2. php artisan serve
3. npm run dev
4. php artisan optimize:clear (kalau edit code)

## LOGIN CREDENTIALS
- Owner: owner@cemilanqontas.id / password
- Operator: operator@cemilanqontas.id / password
