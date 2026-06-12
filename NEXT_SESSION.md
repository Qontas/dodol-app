# NEXT_SESSION.md — Dodol-App
*Sesi terakhir: 12 Juni 2026*

## TRIGGER SENTENCE (untuk sesi baru)
Bg, lanjut dodol-app. 147 PASS. Semua fitur + migrasi data Aidil selesai.
GitHub: Qontas/dodol-app synced, HEAD: 96236b5.
SISA SATU-SATUNYA: Deploy Railway.
Baca NEXT_SESSION.md untuk context lengkap.

## STATUS TERAKHIR
- 147 PASS, 517 assertions
- HEAD: 96236b5 feat(import): seeding saldo awal titipan
- GitHub: https://github.com/Qontas/dodol-app
- Working tree bersih, semua file PROMPT temporary sudah dibersihkan

## CREDENTIALS
- Super Admin: admin@cemilanqontas.id / password → /admin
- Owner Ismi (id=2): owner@cemilanqontas.id / password → /owner/dashboard
- Owner Aidil (id=5): aidil@cemilanqontas.id / password → /owner/dashboard
- Operator: operator@cemilanqontas.id / password → /operator/dashboard

## YANG SUDAH SELESAI SESI INI
1. Issue 1-3 lama (tombol kios baru, unifikasi owner panel, komisi custom) — verified done
2. feat(kiosk): foto kios di form operator + modal visit (resize GD 800x600)
3. fix(owner-panel): sidebar z-index di atas peta Leaflet (render hook Filament)
4. fix(admin): /admin dibersihkan → hanya Dashboard + Manajemen User.
   Resource operasional pindah akses ke /owner-panel. Widget pantau owner di dashboard.
5. feat(import): `php artisan kios:import {file} {--owner=} {--dry-run}`
   → 956 kios owner Aidil masuk (cluster "Tempat Titipan", id=2)
6. feat(import): `php artisan kios:saldo-awal {file} {--owner=} {--dry-run}`
   → 955 delivery konsinyasi PENDING (saldo awal titipan lapangan), trip migrasi #10.
   first_titip_date di-set dari spreadsheet → komisi kios baru TIDAK terpicu.
   Tunggakan owner Aidil naik ~Rp 30,8 jt (itu BENAR, tunggakan riil lapangan).

## DATA OWNER AIDIL (id=5) — HASIL MIGRASI
- 956 kios di cluster "Tempat Titipan"
- 955 delivery pending (1 kios "Karya 1 (masjid)" sudah punya delivery sebelumnya)
- 0 settlement (sengaja — operator yang menagih nanti)
- Operator migrasi (nonaktif): operator.migrasi.owner5@cemilanqontas.id — JANGAN dipakai login
- Trip #10 = trip migrasi (ended, tanpa komisi) — jangan diutak-atik

## SATU-SATUNYA SISA: DEPLOY RAILWAY
1. railway.app → New Project → Deploy from GitHub → Qontas/dodol-app
2. Add MySQL plugin
3. Env: APP_KEY, DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD,
   APP_URL=https://<app>.up.railway.app, APP_ENV=production, APP_DEBUG=false
4. php artisan migrate --force ; php artisan db:seed --force
5. Map picker Filament: aset dotswan sudah ke-publish (public/js|css/dotswan/...).
   Kalau hilang saat deploy: `php artisan filament:assets`
6. Verify 3 role login + owner Aidil lihat 956 kios + tunggakan

## BUSINESS RULES LOCKED (JANGAN DISENTUH)
- 1 mika = 15 biji, Rp 800/biji = Rp 12.000/mika
- Settlement qty BIJI, delivery qty MIKA
- HPP/harga_mika/komisi reguler/komisi kios baru: per owner (default 9500/200/500/1000)
- Kios scope owner LEWAT cluster.owner_id (kiosks TIDAK punya kolom owner_id)
- Varian aktif tunggal (resolveActiveVariant ambil first active variant global)
- Komisi kios baru terpicu kalau first_titip_date == trip_date
- Multi-tenant: owner_id scoping semua tabel bisnis

## TECH STACK
- Laravel 11.52, PHP 8.2.12 (XAMPP), Filament v3.3.50, Livewire 3.8, MariaDB 10.4.32
- Working dir: C:\Users\Qontas\Projects\dodol-app

## DAILY DEV ROUTINE
1. XAMPP → Start MySQL (WAJIB) · 2. php artisan serve · 3. npm run dev · 4. php artisan optimize:clear

## KNOWN ISSUES LINGKUNGAN
1. Map picker aset copy manual saat deploy baru (php artisan filament:assets)
2. MariaDB XAMPP start manual
3. ext-gd aktif manual di C:\xampp\php\php.ini
4. Import UI Filament (ImportAction) "file field required" di Windows — pakai
   command artisan kios:import sbg gantinya (sudah jadi jalur utama)
