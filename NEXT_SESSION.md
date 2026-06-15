# NEXT_SESSION.md — Dodol-App
*Sesi terakhir: 14 Juni 2026*

## TRIGGER SENTENCE
Bg, lanjut dodol-app. 155 PASS. Semua fitur selesai, siap deploy Railway.
GitHub: Qontas/dodol-app synced, HEAD: a356b6b.
PRIORITAS: Deploy Railway.
Baca NEXT_SESSION.md untuk context lengkap.

## STATUS
- 155 PASS, 548 assertions
- HEAD: a356b6b
- Semua fitur complete, tidak ada bug pending

## CREDENTIALS
- Super Admin: admin@cemilanqontas.id / password → /admin
- Owner Ismi: owner@cemilanqontas.id / password → /owner/dashboard
- Owner Aidil: aidil@cemilanqontas.id / password → /owner/dashboard
- Operator: operator@cemilanqontas.id / password → /operator/dashboard

## DATA
- 956 kios Aidil sudah import + saldo awal delivery
- Kios Ismi: data test (belum production)

## FITUR SELESAI
- 7 skenario visit operator (tagih, titip, cek, tunda, BS redistribusi, turun default, stop titipan)
- Foto kios (upload operator + owner, preview modal visit)
- Navigasi Google Maps dari modal visit
- Stop titipan + reaktivasi (operator & owner)
- Import massal kios via artisan kios:import
- Seeding saldo awal via artisan kios:saldo-awal
- Super admin dashboard (pantau owner, bersih dari menu operasional)
- Performance + resilience (N+1 fix, double submit guard, offline banner)
- UX operator (alur 2 langkah modal visit, istilah bahasa Indonesia)
- Prediksi dodol habis di dashboard owner
- Widget untung bersih hari ini
- Fix tombol simpan kios (kios baru dari lapangan)
- Session 8 jam (operator tidak ke-logout di tengah trip)
- Leaflet lokal (map picker tanpa CDN eksternal)
- Lokasi kios opsional + ambil GPS otomatis
- Halaman profil operator selaras layout operator + form bertema amber (tanpa ubah /profile owner & super admin)
- Jual Cash Cepat (walk-in): operator catat penjualan cash ke pembeli non-kios via kios sentinel tersembunyi per owner; omset masuk komisi, sentinel di-exclude dari listing & laporan per-kios (artisan walkin:ensure-sentinel untuk provisi owner lama)
- Koreksi angka visit (SELESAI — backend + UI): operator bisa koreksi angka (drop mika, uang diterima, retur) pada kunjungan TERAKHIR ke kios selama trip aktif lewat tombol "Koreksi" di kartu kios yang sudah dikunjungi (form ke-isi angka lama, badge "Dikoreksi"). Prinsip reversal: record finansial lama dihapus, baris kiosk_visits lama disimpan + ditandai corrected_at (audit trail), angka baru ditulis ulang via persistVisitFromState (1 sumber kebenaran dgn saveVisit). Linkage deterministik deliveries.kiosk_visit_id. Larangan: trip ended, bukan visit terakhir, visit yang ubah default_qty_mika, kios walk-in, check_only tanpa angka. Semua agregat kiosk_visits pakai scope ->active().
- Foto kios R2-ready (SELESAI): disk foto configurable via env MEDIA_DISK (default 'public' lokal; set 's3' saat R2/S3 siap → foto persist melewati redeploy Railway). Kompres otomatis di browser sebelum upload — operator: canvas vanilla (sisi maks 1280px, JPEG 0.7, fallback file asli kalau gagal); owner panel Filament: Filepond imageResize 1280. Accessor Kiosk::photo_url (baca disk media), dipakai di view. resize server-side jadi jaring pengaman & hanya jalan di disk lokal. Kredensial R2 BELUM diisi (env kosong, app tetap jalan di lokal).

## DEPLOY RAILWAY (PRIORITAS UTAMA)
> Tunggu konfirmasi user dulu sebelum mulai deploy.
Steps:
1. Daftar/login railway.app → New Project → Deploy from GitHub → Qontas/dodol-app
2. Add MySQL plugin
3. Set environment variables:
   APP_KEY=(php artisan key:generate --show)
   DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD (dari Railway MySQL)
   APP_URL=https://your-app.up.railway.app
   APP_ENV=production
   APP_DEBUG=false
   SESSION_DRIVER=database
   QUEUE_CONNECTION=database
4. php artisan migrate --force
5. php artisan db:seed --force (untuk user awal)
6. php artisan storage:link
7. npm run build
8. php artisan filament:assets
9. Verify semua fitur 3 role
10. (Foto kios persist) Set object storage Cloudflare R2/S3 — kalau dilewati, foto
    HILANG tiap redeploy (filesystem Railway ephemeral). Langkah:
    - Buat bucket R2 + API token (Access Key/Secret), aktifkan public URL bucket
    - Set env: AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, AWS_BUCKET,
      AWS_DEFAULT_REGION=auto, AWS_ENDPOINT=https://<account_id>.r2.cloudflarestorage.com,
      AWS_URL=<public bucket URL>, AWS_USE_PATH_STYLE_ENDPOINT=true
    - Set MEDIA_DISK=s3 (mengaktifkan disk media ke R2; default tetap 'public' lokal)
    - Kode sudah R2-ready (config app.media_disk + Kiosk::photo_url); tak ada perubahan kode

## BUSINESS RULES LOCKED
- 1 mika = 15 biji, Rp 800/biji = Rp 12.000/mika
- Settlement qty BIJI, delivery qty MIKA
- HPP/harga_mika/komisi per owner (default 9500/200/500/1000)
- Kios scope owner LEWAT cluster.owner_id
- Komisi kios baru: first_titip_date == trip_date
- Multi-tenant: owner_id scoping semua tabel bisnis

## TECH STACK
- Laravel 11.52, PHP 8.2.12, Filament v3.3.50, Livewire 3.8, MariaDB 10.4.32
- Working dir: C:\Users\Qontas\Projects\dodol-app

## KNOWN ISSUES LINGKUNGAN
1. Map picker aset copy manual saat deploy (php artisan filament:assets)
2. MariaDB XAMPP start manual
3. ext-gd aktif manual di C:\xampp\php\php.ini
4. Import UI Filament gagal di Windows — pakai artisan kios:import
