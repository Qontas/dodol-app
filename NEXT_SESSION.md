# NEXT_SESSION.md — Dodol-App
*Sesi terakhir: 08 Juni 2026*

## TRIGGER SENTENCE (paste ini ke Antigravity/Claude baru)
Bg, lanjut dodol-app. 83 PASS. Laporan export PDF+Excel selesai.
GitHub: Qontas/dodol-app synced, HEAD: ec3efab.
PRIORITAS: Fix KioskImporter (format spreadsheet abang) → Smart Suggestion → Deploy Railway.
Baca NEXT_SESSION.md untuk context lengkap.

## STATUS TERAKHIR
- ec3efab feat(owner): laporan export PDF+Excel trip report + laporan bulanan
- 87334c6 feat(owner): batch stok tracking dashboard + Filament
- 723efd5 feat(owner): setting HPP per owner dynamic trip report
- 3cda0e0 fix(trip-report): kios baru berdasarkan first_titip_date
- 67d80f0 fix(operator): set owner_id saat create trip di StartTrip
- Test: 83 PASS, 245 assertions
- GitHub: https://github.com/Qontas/dodol-app

## CREDENTIALS
- Super Admin: admin@cemilanqontas.id / password → /admin (lihat semua)
- Owner Ismi: owner@cemilanqontas.id / password → /owner/dashboard
- Operator: operator@cemilanqontas.id / password → /operator/dashboard

## FITUR COMPLETED (PHASE 1 — SEMUA SELESAI)
✅ Auth + master data (7 Filament resources)
✅ Multi-tenant: super_admin > owner > operator
✅ saveVisit 4 aksi + extension granted + end trip
✅ List kios per cluster + GPS navigasi + foto kios
✅ Owner dashboard: omset, overdue, outstanding, stok, chart, trip report real-time
✅ Batch stok tracking (sisa mika per batch, badge 3-state)
✅ HPP per owner (dynamic, default Rp 9.500, bisa custom)
✅ Laporan export: trip report PDF+Excel + laporan bulanan PDF+Excel
✅ Analisis kios bulanan: kios di-settle, frekuensi kunjungan, kios baru
✅ Operator: input kios baru + nearest neighbor sort
✅ Import kios CSV (KioskImporter — format standar)
✅ Cascade delete kios
✅ Multi-tenant browser verified (isolasi data owner terbukti)

## PRIORITAS SESI BERIKUTNYA

### PRIORITAS 1: Fix KioskImporter (format spreadsheet abang Aidil)
KioskImporter existing expect kolom: nama, pemilik, cluster, qty_mika, telepon, alamat, lat, lng
Data abang punya format berbeda:
- Kolom G = campur: koordinat DMS ("3° 36' 15.5196" N 98° 39' 54.072" E"), Google Maps link, nama tempat
- Tidak ada kolom pemilik → kosongkan (nullable di DB)
- Cluster = angka (1, 2, blank) → map ke nama cluster atau buat cluster baru
- Kolom H = foto (nama file / Google Drive link) → skip untuk import

Yang perlu dilakukan:
1. Update KioskImporter untuk handle kolom G (parse DMS → decimal, extract Google Maps link koordinat, skip kalau teks biasa)
2. Cluster mapping: kalau value angka → cari cluster dengan nama mengandung angka itu, kalau tidak ada → buat cluster baru dengan nama "Cluster {angka}"
3. Nama pemilik nullable (kalau kosong → simpan null)
4. first_titip_date: kalau ada di spreadsheet → parse, kalau tidak ada → null (bukan hari ini)
5. Buat template CSV yang sesuai format abang + dokumentasi cara pakainya

### PRIORITAS 2: Smart Suggestion Harian/Bulanan
Smart suggestion untuk operator:
- Harian: suggest urutan kios berdasarkan last_visit (kios yang paling lama tidak dikunjungi → prioritas)
- Bulanan: suggest kios yang perlu perhatian (frekuensi rendah, stok mungkin habis)
Butuh minimal 1 bulan data real untuk akurat.
REKOMENDASI: implementasi setelah data real terkumpul 2-4 minggu.

### PRIORITAS 3: Deploy ke Railway.app
FREEZE KODE setelah KioskImporter fix.
Steps:
1. Daftar Railway.app (railway.app)
2. New project → deploy from GitHub (Qontas/dodol-app)
3. Add MySQL plugin di Railway
4. Set environment variables (.env production)
5. php artisan migrate --force di Railway
6. php artisan db:seed --force di Railway
7. Setup domain (Railway subdomain gratis atau custom domain)
8. Verify semua fitur production
9. Copy map picker assets ke public/ (known issue)

## BUSINESS RULES LOCKED
- 1 mika = 15 biji, Rp 800/biji = Rp 12.000/mika
- Settlement qty BIJI, delivery qty_delivered MIKA
- HPP per owner (default Rp 9.500/mika, bisa custom via /owner/settings)
- Untung = harga jual - HPP per owner
- Komisi reguler = 20% dari untung per mika
- Komisi kios baru = Rp 1.000/mika di-drop (first_titip_date = tanggal trip)
- Extension max 2x → warning cut off
- End trip wajib pilih alasan (5 opsi)
- procurement_batch_id nullable (operasional bebas)
- Multi-tenant: owner_id di clusters/suppliers/products/procurement_batches/trips

## KNOWN ISSUES
1. Map picker assets harus di-copy manual kalau deploy baru:
   Copy-Item vendor/dotswan/filament-map-picker/resources/dist/filament-map-picker.js public/js/dotswan/filament-map-picker/filament-map-picker-scripts.js
   Copy-Item vendor/dotswan/filament-map-picker/resources/dist/filament-map-picker.css public/css/dotswan/filament-map-picker/filament-map-picker-styles.css
2. MariaDB XAMPP harus di-start manual setiap sesi
3. ext-gd diaktifkan manual di C:\xampp\php\php.ini (diperlukan maatwebsite/excel)
4. Smart suggestion belum ada (butuh data real dulu)

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
