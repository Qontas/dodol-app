# NEXT_SESSION.md — Dodol-App
*Sesi terakhir: 12 Juni 2026*

## TRIGGER SENTENCE (untuk sesi baru)
Bg, lanjut dodol-app. 136 PASS. UX overhaul Fable 5 selesai.
GitHub: Qontas/dodol-app synced, HEAD: 9f42b5c.
PRIORITAS: Fix 4 issue pending + Deploy Railway.
Baca NEXT_SESSION.md untuk context lengkap.

## STATUS TERAKHIR
- 136 PASS, 458 assertions
- HEAD: 9f42b5c ux(landing): sinkronkan istilah
- b7e4ef1 ux(terms): sinkronisasi istilah seluruh aplikasi
- 6ed3bb1 ux(trip): tombol Akhiri Trip fix
- 401a7e1 ux(visit-modal): alur 2 langkah
- 0f31a38 ux(owner): prediksi dodol habis + overdue fix
- GitHub: https://github.com/Qontas/dodol-app

## CREDENTIALS
- Super Admin: admin@cemilanqontas.id / password → /admin
- Owner Ismi: owner@cemilanqontas.id / password → /owner/dashboard
- Operator: operator@cemilanqontas.id / password → /operator/dashboard

## ISSUE 1 (PRIORITAS TINGGI): Tombol Kios Baru Hilang Saat Trip Aktif
Saat trip aktif, bottom nav disembunyikan (fix tabrakan tombol Akhiri Trip).
Akibatnya operator kehilangan akses menu Kios Baru saat sedang ngantar.
Fix: tambah tombol "+ Kios Baru" di header area "DAFTAR KUNJUNGAN" di
active-trip.blade.php, sejajar tombol "Urutkan Jarak". Touch target min 44px.
Link ke route create kiosk. Setelah daftar kios baru, operator harus bisa
kembali ke trip aktif (cek halaman create-kiosk punya back link yang benar).
File: resources/views/livewire/operator/active-trip.blade.php

## ISSUE 2 (PRIORITAS TINGGI): Owner Panel Terpisah Membingungkan
Saat ini ada DUA sisi owner yang terpisah:
- /owner/dashboard — dashboard utama owner (Livewire, custom)
- /owner-panel — Filament panel terpisah (berisi: Area, Supplier, Kios, Operator,
  Pengadaan, Dashboard kosong)

Masalah: owner harus berpindah "dunia" untuk akses manajemen data.
Menu di /owner/dashboard (Manajemen Kios, Area, Supplier, Anggota) saat diklik
malah redirect ke /owner-panel — terasa seperti masuk ke aplikasi lain.
Dashboard di /owner-panel kosong tidak berguna.

Yang diinginkan: SATU pengalaman owner yang mulus.
Solusi yang disarankan:
- Pertahankan /owner/dashboard sebagai halaman utama owner
- Dari /owner/dashboard, menu navigasi (Kios, Area, Supplier, Operator/Anggota,
  Pengadaan) langsung link ke /owner-panel/[resource] yang sudah ada
  (Filament resources sudah scoped per owner_id — tidak perlu rebuild)
- ATAU: embed link Filament resources langsung di sidebar/nav owner custom
- Hapus atau redirect /owner-panel/dashboard yang kosong → redirect ke /owner/dashboard
- Pastikan dari /owner-panel user bisa kembali ke /owner/dashboard dengan mudah
- Jangan rebuild Filament resources dari nol — terlalu riskan, cukup unifikasi navigasi

## ISSUE 3 (PRIORITAS TINGGI): HPP + Komisi Custom Per Owner
Sekarang: HPP dan harga_mika sudah bisa custom per owner via settings.
Yang kurang: komisi_per_mika (Rp 500) dan komisi_kios_baru_per_mika (Rp 1.000)
masih hardcode di Trip model.
Fix:
- Tambah kolom ke users table: komisi_per_mika (default 500),
  komisi_kios_baru_per_mika (default 1000)
- Update Trip model: ganti konstanta → ambil dari owner
- Update /owner/settings: tambah field untuk custom komisi
- Default semua nilai = punya owner Ismi
- Owner lain bisa custom sendiri
- Test: 136+ PASS

## ISSUE 4: Deploy Railway
Steps:
1. Login railway.app → New Project → Deploy from GitHub → Qontas/dodol-app
2. Add MySQL plugin
3. Set environment variables:
   APP_KEY, DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD,
   APP_URL=https://your-app.up.railway.app, APP_ENV=production, APP_DEBUG=false
4. php artisan migrate --force
5. php artisan db:seed --force
6. Copy map picker assets:
   Copy-Item vendor/dotswan/filament-map-picker/resources/... (known issue)
7. Verify semua fitur 3 role

## BUSINESS RULES LOCKED (JANGAN DISENTUH)
- 1 mika = 15 biji, Rp 800/biji = Rp 12.000/mika
- Settlement qty BIJI, delivery qty MIKA
- HPP per owner (default Rp 9.500)
- harga_mika per owner (default Rp 200)
- Komisi reguler per owner (default Rp 500/mika)
- Komisi kios baru per owner (default Rp 1.000/mika)
- Semua skenario visit: tagih+titip, tagih saja, titip saja, tunda bayar (max 2x),
  cek saja, BS redistribusi, turunkan default — JANGAN ubah logika
- Multi-tenant: owner_id scoping semua tabel bisnis

## TECH STACK
- Laravel 11.52.0, PHP 8.2.12 (XAMPP Windows)
- Filament v3.3.50, Livewire 3.8
- MariaDB 10.4.32
- Working dir: C:\Users\Qontas\Projects\dodol-app

## DAILY DEV ROUTINE
1. XAMPP → Start MySQL (WAJIB)
2. php artisan serve
3. npm run dev
4. php artisan optimize:clear

## KNOWN ISSUES LINGKUNGAN
1. Map picker assets copy manual saat deploy baru
2. MariaDB XAMPP start manual
3. ext-gd aktif manual di C:\xampp\php\php.ini
