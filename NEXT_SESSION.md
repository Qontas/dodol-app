# NEXT_SESSION.md — Dodol-App

_Sesi terakhir: 08 Juni 2026_

## TRIGGER SENTENCE

Bg, lanjut dodol-app. 93 PASS. Real-time polling dashboard + Laporan bulanan dengan harga_mika custom selesai.
GitHub: Qontas/dodol-app synced.
PRIORITAS: Super admin dashboard → Deploy.
Baca NEXT_SESSION.md untuk context lengkap.

## STATUS TERAKHIR

- feat(owner): real-time dashboard polling and monthly report with custom harga_mika
- fix(operator/bug4): drop extra cash detection + end trip summary
- 8c278f5 feat(operator): cash delivery — kios cash only + drop extra cash
- 950cd69 feat(import): KioskImporter format spreadsheet abang
- Test: 93 PASS, 313 assertions
- GitHub: https://github.com/Qontas/dodol-app

## CREDENTIALS

- Super Admin: admin@cemilanqontas.id / password → /admin
- Owner Ismi: owner@cemilanqontas.id / password → /owner/dashboard
- Operator: operator@cemilanqontas.id / password → /operator/dashboard

## FITUR BERIKUTNYA (URUTAN PRIORITAS)

### 1. Super Admin Dashboard

Widget: total owner, total operator, total kios semua owner, omset hari ini semua owner.
List owner + statistik singkat per owner.
Super admin TIDAK input kios/cluster (data operasional urusan owner masing-masing).
File: buat SuperAdminDashboardController + view

### 2. Deploy ke Railway.app

Setelah semua fitur di atas selesai.

## BUSINESS RULES LOCKED

- 1 mika = 15 biji, Rp 800/biji = Rp 12.000/mika
- Settlement qty BIJI, delivery qty_delivered MIKA
- HPP per owner (default Rp 9.500, custom via /owner/settings)
- Komisi reguler = Rp 500/mika terjual
- Komisi kios baru = Rp 1.000/mika di-drop (first_titip_date = tanggal trip)
- Kios cash only: is_cash_only = true, settlement langsung lunas
- Drop extra cash: drop > default_qty_mika → kelebihan = cash_sale delivery
- Extension max 2x → warning cut off
- harga_mika = Rp 200/mika (default, bisa custom per owner)
- Multi-tenant: owner_id di clusters/suppliers/products/procurement_batches/trips

## KNOWN ISSUES

1. Map picker assets copy manual saat deploy baru
2. MariaDB XAMPP start manual setiap sesi
3. ext-gd aktif manual di C:\xampp\php\php.ini

## TECH STACK

- Laravel 11.52.0, PHP 8.2.12 (XAMPP)
- Filament v3.3.50, Livewire 3.8, Breeze v2.4.1
- MariaDB 10.4.32, dotswan/filament-map-picker v1.8.8
- barryvdh/laravel-dompdf ^3.1, maatwebsite/excel ^3.1
- Working dir: C:\Users\Qontas\Projects\dodol-app

## DAILY DEV ROUTINE

1. XAMPP → Start MySQL (WAJIB)
2. php artisan serve
3. npm run dev
4. php artisan optimize:clear

Setelah Selesai:
php artisan test --compact
git add .
git commit -m "feat(owner): real-time dashboard polling and monthly report with custom harga_mika"
git push origin main

Report: commit hash + test status.
