# NEXT_SESSION.md — Dodol-App

_Sesi terakhir: 11 Juni 2026_

## TRIGGER SENTENCE

Bg, lanjut dodol-app. 130 PASS. Audit Fable 5 selesai.
GitHub: Qontas/dodol-app synced, HEAD: 36e6dd2.
PRIORITAS: Fix 5 issue + HPP custom per owner + Deploy Railway.
Baca NEXT_SESSION.md untuk context lengkap.

## STATUS TERAKHIR

- 130 PASS, 428 assertions
- 36e6dd2 chore: untrack PROMPT temporary
- 0f31a38 ux(owner): prediksi dodol habis + bahasa sehari-hari + fix overdue
- b24d727 ux(operator): banner offline + touch target
- 5ad7688 perf(dashboard): live trip progress sekali di render
- 1793763 perf(operator): hapus N+1 urgency cluster
- 5da6c82 fix(resilience): guard idempotensi saveVisit
- GitHub: https://github.com/Qontas/dodol-app

## CREDENTIALS

- Super Admin: admin@cemilanqontas.id / password → /admin
- Owner Ismi: owner@cemilanqontas.id / password → /owner/dashboard + /owner-panel
- Operator: operator@cemilanqontas.id / password → /operator/dashboard

## ISSUE YANG HARUS DI-FIX (PRIORITAS TINGGI)

### ISSUE 1: HPP + Komisi + Harga Modal Mika Custom Per Owner

Sekarang: HPP dan harga_mika sudah bisa custom per owner via /owner/settings.
Yang kurang: komisi_per_mika (Rp 500) dan komisi_kios_baru (Rp 1.000) masih hardcode di Trip model.
Yang harus dilakukan:

- Tambah kolom ke users table: komisi_per_mika (default 500), komisi_kios_baru_per_mika (default 1000)
- Update Trip model: ganti konstanta 500 dan 1000 → ambil dari owner
- Update /owner/settings view: tambah field untuk custom komisi
- Update UserSeeder: set default untuk owner Ismi
- Default semua nilai = punya owner Ismi (HPP 9500, harga_mika 200, komisi 500, komisi_kios_baru 1000)
- Owner lain bisa custom sendiri
- Test: 130+ PASS

### ISSUE 2: Tombol "Akhiri Trip" Tenggelam/Tidak Terlihat

Di mobile dan PC, tombol Akhiri Trip tertutup/tidak nampak.
File: resources/views/livewire/operator/active-trip.blade.php
Kemungkinan: tombol sticky bottom tertutup bottom nav atau z-index conflict.
Fix: pastikan tombol Akhiri Trip selalu visible di atas bottom nav (z-index lebih tinggi, padding bottom cukup).

### ISSUE 3: Tunda Bayar (Perpanjangan) Masih Bisa Input Drop Baru

Ketika operator centang "Tunda bayar & ambil BS (perpanjangan)", seharusnya:

- Tidak perlu input drop titipan baru (karena tujuannya cuma perpanjang, tidak ada titipan baru)
- Input "Drop Titipan Baru (Mika)" harus disembunyikan atau di-disabled
  File: resources/views/livewire/operator/active-trip.blade.php
  Fix: kalau extensionGranted = true, sembunyikan section "Drop Titipan Baru"

### ISSUE 4: Turunkan Default Masih Bisa Input Drop Baru

Ketika operator centang "Turunkan default qty kios ini":

- Ini terjadi saat settle — artinya operator sedang bayar titipan lama
- Input drop titipan baru seharusnya tetap bisa (owner mungkin mau turunkan default tapi tetap titip baru)
- TAPI kalau turunkan default dicentang + input drop baru > default baru → perlu warning
  Clarifikasi: issue ini mungkin bukan bug, tapi UX yang membingungkan.
  Fix: tambah helper text "Default baru berlaku untuk pengantaran BERIKUTNYA, bukan drop sekarang"
  Sehingga operator paham bahwa drop sekarang dan default baru adalah 2 hal terpisah.

### ISSUE 5: Kata-kata Teknis Masih Ada

Ganti semua kata teknis yang tersisa di halaman operator:

- "Settle" → "Tagih" atau "Ambil Bayaran"
- "Extension" → "Tunda Bayar"
- "Drop" → "Titip" atau "Antar Dodol"
- "BS" → "Sisa BS" atau "Dodol Sisa" (tapi tetap catat sebagai BS di sistem)
- "Settlement" → "Tagihan"
- "Delivery" → tidak tampil ke operator
  Scan semua file operator blade dan Livewire component untuk kata-kata ini.

## SETELAH FIX — DEPLOY RAILWAY

### Steps Railway:

1. Daftar/login railway.app
2. New project → Deploy from GitHub → Qontas/dodol-app
3. Add MySQL plugin
4. Set environment variables:
    - APP_KEY (php artisan key:generate --show)
    - DB\_\* dari Railway MySQL
    - APP_URL = https://your-app.up.railway.app
    - APP_ENV=production
    - APP_DEBUG=false
5. php artisan migrate --force
6. php artisan db:seed --force
7. Copy map picker assets (known issue)
8. Verify semua fitur

## BUSINESS RULES LOCKED

- 1 mika = 15 biji, Rp 800/biji = Rp 12.000/mika
- Settlement qty BIJI, delivery qty_delivered MIKA
- HPP per owner (default Rp 9.500, custom via /owner/settings)
- harga_mika per owner (default Rp 200, custom)
- Komisi reguler per owner (default Rp 500/mika, custom)
- Komisi kios baru per owner (default Rp 1.000/mika, custom)
- Kios cash only: is_cash_only = true
- Drop extra cash: toggle cash/konsinyasi
- Extension max 2x → warning cut off
- Multi-tenant: owner_id scoping semua tabel bisnis

## KNOWN ISSUES

1. Map picker assets copy manual saat deploy baru
2. MariaDB XAMPP start manual setiap sesi
3. ext-gd aktif manual di C:\xampp\php\php.ini

## TECH STACK

- Laravel 11.52.0, PHP 8.2.12 (XAMPP)
- Filament v3.3.50, Livewire 3.8, Breeze v2.4.1
- MariaDB 10.4.32
- barryvdh/laravel-dompdf ^3.1, maatwebsite/excel ^3.1
- Working dir: C:\Users\Qontas\Projects\dodol-app

## DAILY DEV ROUTINE

1. XAMPP → Start MySQL (WAJIB)
2. php artisan serve
3. npm run dev
4. php artisan optimize:clear
