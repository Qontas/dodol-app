# NEXT_SESSION.md — Dodol-App
*Sesi terakhir: 1 Juli 2026*

## TRIGGER SENTENCE
Bg, lanjut dodol-app. 204 PASS. Sudah deploy Railway (produksi jalan).
Peta map-picker abu-abu di mobile SUDAH DIFIX (owner, commit 1d713c0 pushed) — peta operator
dicek AMAN, tak diubah. GitHub: Qontas/dodol-app synced. PWA installable + offline shell.
Baca NEXT_SESSION.md untuk context lengkap.

## STATUS
- 204 PASS (843 assertions)
- Fix peta owner abu-abu mobile pushed (commit 1d713c0); peta operator dicek aman (tak diubah)
- Sudah live di Railway: https://dodol-app-production.up.railway.app
- Semua fitur complete

## ✅ FIX PETA MAP-PICKER ABU-ABU DI MOBILE (1 Juli 2026) — owner form (commit 1d713c0)
- GEJALA: peta pemilih lokasi kios di form owner (KioskResource) tampil ABU-ABU di HP —
  tiles cuma nutup sebagian / kosong, makin parah saat zoom. Desktop keliatan normal.
- AKAR (terbukti, BUKAN OSM jelek & BUKAN perlu pindah Google Maps): bug
  invalidateSize/sizing. Tiles server OSM balik 200 normal; masalahnya container di-size
  SALAH oleh Leaflet karena dihitung sebelum layout mobile settle, plus paket vendor
  (dotswan/filament-map-picker) membongkar+membangun ulang peta via IntersectionObserver
  saat peta masuk/keluar layar (scroll ke section / section collapsible dibuka) TANPA
  invalidateSize yang cukup. Diperparah detectRetina (default paket = TRUE) → minta tile
  @2x di mobile retina, dan zoomSnap:2 (langkah zoom tak lazim → tile kosong saat pinch).
- FIX (tetap OSM gratis, 2 file):
  * app/Filament/Resources/KioskResource.php (Map::make): `->detectRetina(false)` EKSPLISIT
    (buang pemanggilan saja TAK cukup — default paket true; harus di-set false → 0 tile @2x),
    normalkan `->extraControl(['zoomSnap' => 1, 'zoomDelta' => 1])` (dari zoomSnap:2), buang
    `->extraTileControl([])` kosong (no-op).
  * resources/views/filament/forms/map-invalidate-size.blade.php (BARU) + View::make di
    section Lokasi: paksa Leaflet hitung ulang ukuran via `window.dispatchEvent(Event('resize'))`
    (Leaflet trackResize:true default → invalidateSize internal → tiles keisi ulang). 3 pemicu
    tahan bongkar-pasang vendor: (a) dispatch bertahap 150/400/800ms, (b) dispatch saat scroll
    (throttled rAF — scroll = pemicu peta dibuat ulang), (c) scan berkala → dispatch begitu
    lebar .leaflet-container berubah 0→positif (peta baru / section dibuka).
- VERIFIKASI (browser nyata, Chrome headless, login owner → /owner-panel/kiosks/create):
  MOBILE 360px retina dsf2 + DESKTOP 1280px = 100% coverage (grid 30×30 titik, no grey) di
  SEMUA tahap: scroll-in tanpa interaksi, zoom in/out, collapse+reopen section. Retina @2x
  diminta = 0 (dari sebelumnya aktif). Bukti sebelum-fix ter-reproduksi (screenshot peta
  abu-abu total: cuma marker+kontrol+label Leaflet). php artisan test 204 PASS.
- Driver verifikasi ad-hoc di scratchpad (di luar repo): map-verify.cjs.

## ✅ PETA OPERATOR (create-kiosk.blade.php) — DICEK & AMAN, TIDAK DIUBAH (1 Juli 2026)
- Ada peta KEDUA di app: pemilih lokasi di form operator (Livewire create-kiosk). Sudah dicek
  apakah kena bug abu-abu yang sama → TIDAK. Beda implementasi total: Leaflet hand-rolled
  (bukan paket filament-map-picker), #operator-map fixed height 300px.
- Kenapa aman (penyebab akar owner semuanya TAK ADA di sini): (1) detectRetina TIDAK aktif
  (L.tileLayer tanpa detectRetina, default Leaflet false → 0 tile @2x); (2) TIDAK ada
  IntersectionObserver bongkar-pasang peta (dibuat sekali, tak pernah di-destroy); (3) BUKAN
  di dalam section collapsible (selalu terlihat); (4) width container sudah settle di titik
  init (DOMContentLoaded maupun livewire:navigated). Jadi invalidateSize tak diperlukan.
- VERIFIKASI MOBILE 360px retina: 100% coverage di direct-load, SPA nav (wire:navigate dari
  dashboard klik "Kios Baru" → livewire:navigated), dan zoom in/out. Desktop 100% juga.
  Retina @2x = 0. Screenshot terisi penuh (Kesawan/Silalas/Podomoro City + marker kios).
- KEPUTUSAN: SENGAJA tidak diubah (menambah fix tak perlu = risiko regresi tanpa manfaat).
  ⏭️ SESI DEPAN TIDAK USAH ngecek ulang peta operator — sudah dipastikan aman.

## ✅ OPERATOR TAMBAH/GANTI FOTO KIOS DARI LAPANGAN (1 Juli 2026) — modal kunjungan
- Sebelumnya operator hanya bisa lampirkan foto saat BUAT kios baru (CreateKiosk); tak ada
  cara menambah/ganti foto kios yang sudah ada. Owner lupa isi foto / bentuk kedai berubah
  → tak terpecahkan. Sekarang operator bisa dari modal kunjungan.
- UI (active-trip.blade.php, modal kunjungan): kios BELUM ada foto → tombol dashed
  "📷 Tambah Foto Kios"; kios SUDAH ada foto → foto tampil + tombol kecil "Ganti Foto".
  Alur: pilih dari kamera/galeri → kompres browser (canvas, sisi maks 1280px, JPEG q0.7,
  pola create-kiosk) → $wire.upload → saveKioskPhoto() → foto baru langsung tampil.
- Backend (ActiveTrip.php): method saveKioskPhoto() + property kioskPhoto + trait WithFileUploads.
  * 🔒 GATE MULTI-TENANT (KRITIS): re-verifikasi server-side kios milik owner operator
    (whereKey + whereHas('cluster', owner_id == auth()->owner_id), pola scopedPendingSettlements).
    Operator TIDAK bisa ganti foto kios owner LAIN walau selectedKiosk dipaksa. Ada test gate.
  * Opsi A: timpa foto lama BEBAS (riwayat tidak disimpan) + JEJAK AUDIT di kiosk.notes
    ("Foto ditambah/diganti operator [nama] pada [tgl]", catatan lama tak dihapus).
- KOMPRES SERVER — app/Support/ImageResizer::fit() BARU: jaring pengaman server-side yang
  kini jalan di disk LOCAL *dan* CLOUD (R2/S3) via Storage::get→GD resize→Storage::put.
  MENGGANTI resizeImageIfLocal() lama yang cuma jalan di disk lokal (Storage::path) & DILEWATI
  di R2 — celah lama (foto gede dari HP tak terkompres di produksi) kini TERTUTUP. CreateKiosk
  di-refactor pakai service ini juga.
- VERIFIKASI: php artisan test 204 PASS (843 assertions; +4 test: tambah foto+jejak, ganti
  foto+jejak, 🔒 gate tolak kios owner lain, kompres server 2400px→≤1280). Browser mobile 360px
  8/8: dua state tombol benar, upload jalan, FOTO KELOAD DARI R2 PRODUKSI (pub-*.r2.dev) —
  upload+resize+load terbukti end-to-end di R2, bukan simulasi lokal.
- Driver verifikasi ad-hoc: .claude/skills/verify-browser/verify-photo.cjs (ke-ignore verify-*.cjs).

## ✅ SEDERHANAKAN ISTILAH UI JADI BAHASA AWAM (1 Juli 2026) — form Kios + panel operator
- Tujuan: label/helper lebih mudah dipahami operator/owner (bahasa awam, bukan jargon), dan
  buang kurung "()" yang makan tempat di mobile. CUMA GANTI TEKS — nol perubahan logika,
  field name (kolom DB), value, atau perilaku.
- TUMPUKAN 1 (jargon teknis) — KioskResource.php (form owner) + kolom tabel + create-kiosk.blade.php:
  * "Threshold Warning (hari)" → "Peringatan kalau belum dikunjungi (hari)"
  * "Target Interval Kunjungan (hari)" → "Idealnya dikunjungi tiap berapa hari (hari)"
  * "Threshold Fast Mover (hari)" → "Batas kios laris (hari)"
  * "Default Qty Mika per Antar" → "Jumlah Mika Biasanya per Antar" (operator: "Default Qty
    Mika" → "Jumlah Mika Biasanya")
  * "Kios Cash Only" → "Kios Bayar Tunai Langsung" (helper buang kata "konsinyasi")
  * Kolom tabel Kios disamakan: Cash Only→Tunai, Interval→Jadwal, Fast Mover→Laris
  * Helper tiap field ikut diperjelas ke bahasa awam.
- TUMPUKAN 2 (buang kurung yg makan tempat):
  * "Lokasi Kios (klik titik di peta)" → label "Lokasi Kios" + helper kecil "Klik titik di peta"
  * "⛔ Hentikan Kedai Ini (stop titipan)" → "⛔ Hentikan Kedai Ini" (2 tombol, active-trip.blade.php)
  * Modal Akhiri Trip: "Kios Lama (Pergantian)" → "Kios Lama"; "Kios Baru (Tempat Baru)" → "Kios Baru"
- DITUNDA SENGAJA (keputusan lintas-app terpisah — TANYA user dulu kalau mau lanjut):
  kata "Trip" (→"Perjalanan"?) dan "Stop" (→"Hentikan"?) muncul konsisten di banyak layar.
  Kalau diubah harus SEKALIGUS semua layar, jangan sepotong. Belum disentuh sesi ini.
- VERIFIKASI: php artisan test 200 PASS (822 assertions, tak ada assertion teks lama yg pecah).
  Browser mobile 360px (owner + operator + modal Akhiri Trip): 20/20 checks OK, label baru
  tampil, istilah lama hilang, layout tidak pecah/kepotong (aman dari bug mobile columnSpan lalu).
- Driver verifikasi ad-hoc: .claude/skills/verify-browser/verify-labels.cjs (ke-ignore pola verify-*.cjs).

## ✅ AKAR SEBENARNYA FORM KIOS RUSAK (30 Juni 2026) — columnSpan(2) di MOBILE (BUKAN cache)
- KOREKSI: dua fix SW di bawah (v3 SWR, v4 network-first) TERNYATA BUKAN penyebab form
  Kios rusak. Bukti yang meruntuhkan teori cache: user UNINSTALL PWA + reload banyak kali
  + logout-login → MASIH rusak (cache mustahil bertahan setelah uninstall). (Fix SW v4
  tetap DIPERTAHANKAN — perbaikan sah utk cegah aset Filament basi di masa depan, tapi
  bukan akar masalah ini.)
- AKAR PASTI (terbukti, reproducible di browser BERSIH no-SW): form rusak HANYA di MOBILE
  viewport (<1024px). Tes Claude sebelumnya cuma DESKTOP → makanya keliatan normal.
  Penyebab: di KioskResource, field full-width pakai ->columnSpan(2) (BUKAN ->columnSpanFull()).
  Section ->columns(2): di mobile grid collapse ke 1 kolom (--cols-default: repeat(1,...)),
  TAPI field ber-columnSpan(2) maksa span 2 kolom → browser bikin kolom implisit ke-2 →
  seluruh section jadi 2 kolom cramped → label tumpang tindih + help text 1 huruf/baris.
  Di desktop (≥1024) section memang 2 kolom → span-2 = full width → keliatan normal.
- BUKTI DOM live (mobile 360px): field span-2 → g1 grid "0px 256px" (2 kolom), help text
  w=37 ratio=3.8 (vertikal). Setelah child diubah ke grid-column:1/-1 (idiom columnSpanFull)
  → g1 "280px" (1 kolom), help text w=280 ratio=0.07 (horizontal). FIX TERBUKTI.
- FIX (commit ini): KioskResource.php 3x ->columnSpan(2) → ->columnSpanFull() (Nama Kios
  baris 47, Alamat Lengkap 94, Foto Kios 148). columnSpanFull = grid-column:1/-1, aman di
  1 maupun 2 kolom. Resource lain dicek: tak ada columnSpan(2) lain.
- VERIFIKASI: php artisan test 200 PASS (822 assertions). DOM proof mobile 1-kolom.
- REDEPLOY WAJIB. Setelah deploy form langsung normal di HP (ini bug HTML/CSS dari form,
  bukan cache — tak perlu clear cache, cukup reload halaman ambil HTML baru).
- PELAJARAN: untuk "full width" di form Filament, SELALU pakai ->columnSpanFull(), JANGAN
  ->columnSpan(N) yang sama dgn jumlah kolom section (pecah di mobile saat grid jadi 1 kolom).

## FIX UI: CSS FILAMENT BASI DI PANEL OWNER/ADMIN (30 Juni 2026) — SERVICE WORKER
- GEJALA: form "Buat Kios" (& berpotensi SELURUH panel Filament owner/admin) tampil
  RUSAK di sebagian HP user — label tumpang tindih, help text "1 huruf per baris",
  toggle ketiban teks. PER-DEVICE (HP yang sempat nge-cache CSS lama), bukan semua user.
- DIAGNOSA (server SEHAT, masalah klien): cek produksi → semua aset Filament HTTP 200,
  text/css, ukuran penuh, https, ?v=3.3.50.0 konsisten. Hash CSS committed == produksi.
  Browser BERSIH (SW diblokir) → form render SEMPURNA (grid 2-kolom, help text horizontal).
- AKAR: public/sw.js v2 — aset Filament (/css/filament/*, /js/filament/*, /css/dotswan/*,
  /vendor/*) jatuh ke cabang cacheFirst (cache SELAMANYA tanpa revalidasi). Beda dari
  /build/* Vite yang nama filenya BER-HASH, aset Filament nama tetap + cuma ?v=<versi>;
  versi cuma naik saat paket naik, TIDAK saat filament:assets republish konten di versi
  sama → device terkunci ke CSS lama → HTML baru ketemu CSS lama → layout rusak.
- FIX (commit ini, public/sw.js): (1) tambah cabang SEBELUM cacheFirst fallthrough →
  aset Filament/plugin/Leaflet pakai STALE-WHILE-REVALIDATE (serve cache + selalu
  refetch background → auto-update, tak pernah nyangkut basi lagi). (2) bump CACHE_NAME
  dodol-v2 → dodol-v3 → activate handler buang cache lama → HP rusak langsung sembuh.
  PWA lama UTUH (HTML network-only, /build/* cache-first, Livewire/CSRF bypass).
- VERIFIKASI: php artisan test 200 PASS (822 assertions). Simulasi routing sw.js 14/14 OK.
- REDEPLOY WAJIB (sw.js dari public/).

### LANJUTAN (30 Juni 2026, sore): v3→v4 NETWORK-FIRST + temuan gap handover SW
- GEJALA LANJUTAN: setelah v3 deploy (ACTIVE, dikonfirmasi curl: CACHE_NAME dodol-v3 +
  staleWhileRevalidate ke-serve di prod), user reload 1x di HP → MASIH rusak.
- DIAGNOSA: bukan deploy gagal. Browser bersih yang dapat v3 dari awal → form NORMAL
  (SW aktif, app.css served-by-SW 200, help text 412×20 horizontal, grid aktif).
  AKAR sisa = GAP HANDOVER SW: di HP yang masih jalan SW v2, reload #1 MASIH dilayani
  v2 lama (cacheFirst → CSS basi) SEMENTARA v3 baru install+activate di background
  (CSS basi terlanjur ter-render). Baru reload #2 (v3 mengontrol + dodol-v2 terhapus)
  → fresh. Jadi "reload 1x" itu KELIRU — handover SW butuh minimal 2x reload. Ini
  berlaku utk SWR maupun network-first (tak ada SW bisa ubah perilaku v2 yg sudah
  terpasang di load pertama).
- FIX LANJUTAN: aset Filament/plugin/Leaflet SWR → NETWORK-FIRST (online SELALU fresh,
  cache cuma fallback offline) → hilangkan lag 1-load SWR utk deploy mendatang (republish
  versi-sama konten-beda). CACHE_NAME dodol-v3 → dodol-v4. Verifikasi: 200 PASS, routing
  11/11 OK. PWA lama tetap utuh.
- CARA SEMBUHKAN HP RUSAK (setelah v4 deploy), berurut dari paling ringan:
  1. Reload 2x (reload pertama swap SW, kedua baru fresh). Atau
  2. Tutup app dari recent apps (swipe) lalu buka lagi → reload. Atau
  3. DIJAMIN: Chrome Android → ⋮ → Info situs (gembok) → Setelan situs → Hapus &
     reset / "Clear & reset" (hapus cache+storage+SW situs) → buka app lagi.

## REVISI ALUR KUNJUNGAN — TAHAP 3/3 SELESAI (29 Juni 2026): TITIP BARU + BAYAR LAMA NANTI + PELUNASAN PIUTANG
- KEPUTUSAN: kios boleh terima titipan baru walau uang tagihan kurang → sisa jadi
  PIUTANG (Settlement pending rupiah, BEDA dari Titipan Tertunda Tahap 2 yang mika).
  Owner bisa lihat "Belum Bayar per-kios" (rupiah pasti) + operator terima pelunasan.
- DILAKUKAN (commit 67ccc32):
  - SKENARIO titip+bayar-lama-nanti: Tagih+Titip dengan uangDiterima < tagihan →
    Settlement tetap dibuat (status pending dari SettlementObserver) → sisa = piutang.
    Input "Janji Bayar" (teks bebas) saat bayar-kurang → ditulis ke Settlement.notes
    ('Janji bayar: …'). chooseAction('tagih_titip') kini panggil hitungTagihan() agar
    "Total Tagihan" + deteksi bayar-kurang tampil sejak layar kebuka.
  - ALUR TERIMA PEMBAYARAN PIUTANG (tutup celah merah): openVisitModal hitung
    piutangLama (refreshPiutangLama) + banner. terimaPembayaranPiutang() lunasi
    Settlement pending tertua-dulu (FIFO), Observer set status='paid'+paid_at saat penuh.
    🔒 GATE KEAMANAN: scopedPendingSettlements() SELALU scope owner operator (lewat
    delivery.kiosk.cluster.owner_id) → operator tak bisa utak-atik piutang owner lain.
    Tolak overpay (amount > outstanding) + outstanding<=0 + amount<=0. Opsi A: TIDAK
    ubah visit_date (omzet tetap tanggal asli), TIDAK tambah komisi (murni pelunasan kas).
  - WIDGET OWNER "Belum Bayar per-kios" (belumBayarPerKios): Settlement pending
    di-groupBy kiosk_id → rupiah (amount_due−amount_paid) + janji bayar per kios.
    Rupiah (utang pasti), beda dari "Titipan Tertunda" (mika). Eager load
    delivery.kiosk:id,name (NO N+1). Kosong → section tak tampil.
  - BANNER OPERATOR di modal: tampilkan titipan tertunda (mika + janji, Tahap 2) DAN
    piutang lama (rupiah + janji, Tahap 3) agar operator ingat menagih.
  - State baru ActiveTrip semua SCALAR kecil (piutangLama int, piutangJanji/janjiBayar/
    tertundaJanji string, payingPiutang bool, piutangBayar/tertundaMika int) → snapshot
    Livewire TIDAK membengkak (Fase 1 −97% tetap terjaga).
- TEST: 200 PASS. (+ test skenario bayar-kurang→pending, janji→notes, pelunasan FIFO,
  gate scope owner + tolak overpay, widget belumBayarPerKios.)
- STATUS MENU KUNJUNGAN TERKINI (final 3 menu untuk kios bertitipan):
  1. **Tagih + Titip** (wajib titipan>0; uang boleh kurang → piutang + janji bayar)
  2. **Cek Sisa** (4 alasan; "belum bisa bayar" → titipan tetap pending + janji bayar)
  3. **⛔ Hentikan Kedai** (Stop+Tagih terakhir / Stop tanpa tagih=kerugian)
  ("Tagih Saja" dihapus Tahap 1; "Tunda Bayar" dilebur ke Cek Sisa Tahap 2.)
- ⏭️ REVISI ALUR KUNJUNGAN 3 TAHAP = TUNTAS.

## REVISI ALUR KUNJUNGAN — TAHAP 2/3 (29 Juni 2026): LEBUR "TUNDA BAYAR" → CEK SISA
- KEPUTUSAN: "Tunda Bayar" terpisah dihapus, jadi ALASAN di "Cek Sisa". Realisasi B:
  titipan deferred TETAP pending (BUKAN rupiah karangan di widget Belum Bayar).
- DILAKUKAN (commit 23237cf):
  - "Tunda Bayar" dicabut dari menu (whitelist chooseAction jadi ['tagih_titip','cek']).
    max-2x (display-only) dibuang. Mekanik extension jadi LEGACY no-op (extensionGranted
    selalu false; persistVisitFromState $extension dibiarkan kompatibel).
  - "Cek Sisa" 1 pintu, 4 alasan: Kios Tutup · Minta Tunggu · Dodol Masih Ada ·
    💰 Belum Bisa Bayar (terakhir hanya muncul kalau ada titipan).
  - Alasan "belum_bisa_bayar" → check_only (titipan TETAP pending, 0 Settlement) +
    input "Janji Bayar" (teks bebas) → KioskVisit.notes. Tetap catat sisa_biji (prediksi).
    Alasan lain = cek biasa tanpa notes.
  - SURFACE Titipan Tertunda: (a) banner operator modal kunjungan berikutnya
    ("⏳ Titipan belum tertagih: X mika — janji bayar: …" via openVisitModal
    tertundaMika/tertundaJanji); (b) widget owner dashboard "⏳ Titipan Tertunda"
    (OwnerDashboardController $titipanTertunda — mika + janji, BUKAN rupiah).
  - TIDAK disentuh: persistVisitFromState inti, settle_only (Stop+Tagih), prediksi
    (sisa_biji), model 1-pending, keamanan.
- TEST: 193 PASS. Rewrite tunda→belum_bisa_bayar (check_only, pending, 0 settlement,
  sisa_biji+notes, tanpa extension); +pengunci; +owner widget test. Browser mobile 13/13.
- ⏭️ TAHAP 3 (rencana): "titip baru + bayar lama nanti" (Tagih+Titip uang kurang =
  Settlement pending) + janji bayar di alur itu + alur "terima pembayaran piutang"
  (tutup celah merah: pelunasan Settlement pending lama).

## REVISI ALUR KUNJUNGAN — TAHAP 1/3 (29 Juni 2026): HAPUS "TAGIH SAJA"
- KEPUTUSAN: "ambil bayaran tanpa titip" HANYA via Stop kedai (Hentikan Kedai +
  Tagih Terakhir). Kios aktif WAJIB selalu ada titipan jalan.
- DILAKUKAN (commit 7cf7cc5):
  - "Tagih Saja" (chosenAction 'tagih') DICABUT dari UI: whitelist chooseAction
    jadi ['tagih_titip','tunda','cek'] (pending) — 'tagih' ditolak; tombol + @case
    + in_array UI dibuang dari active-trip.blade.php. dropBaru-reset jadi ['tunda','cek'].
  - PINTU BELAKANG ditutup: "Tagih + Titip" wajib drop>0 — Simpan di-DISABLE saat
    tagih_titip & dropBaru=0 (level-UI; arahkan ke "Hentikan Kedai"). Tunda/Cek (drop=0)
    TIDAK ke-blok. Boundary backend sengaja TIDAK di-enforce (cukup UI utk operator normal).
  - resolveVisitAction()/persistVisitFromState() (settle_only) TIDAK disentuh — tetap
    dipakai Tunda & Stop+Tagih. Kurangi jatah kini hanya di tagih_titip (tetap jalan).
  - Menu kios bertitipan kini 4: Tagih+Titip · Tunda Bayar · Cek Sisa · ⛔ Hentikan Kedai.
- TEST: +3 pengunci di ActiveTripActionPickerTest (Tagih Saja absen/ditolak; tagih_titip
  drop=0 disable; Tunda/Cek tak ke-blok). 191 PASS. Browser mobile 10/10.
- ⏭️ TAHAP 2 (rencana): lebur "Tunda Bayar" ke dalam "Cek Sisa" sebagai alasan +
  catat piutang TANPA max-2x. TAHAP 3: TBD.

## FIX FOUC SIDEBAR OWNER (28 Juni 2026) — SELESAI
- GEJALA: di HP, tiap buka /owner/dashboard (dan tiap balik Kios→Dashboard), sidebar
  drawer KEDIP kebuka sepersekian detik lalu nutup (FOUC). Mobile-only.
- AKAR (layouts/owner.blade.php): kelas STATIS `<aside>` tak punya default-tertutup
  mobile. `-translate-x-full` cuma dari `:class` Alpine → sebelum Alpine init, mobile
  ter-paint translateX(0) = TERBUKA, lalu `transition-transform duration-200`
  meng-animasikan penutupan saat Alpine set closed → kedip kelihatan. (Bukan
  wire:navigate — nav link anchor polos = full load; kedip di tiap muat layout custom.)
- FIX (Opsi A, pure-CSS 2 baris): statis tambah `-translate-x-full lg:translate-x-0`
  (mobile tertutup, desktop terbuka SEBELUM Alpine) + `:class="sidebarOpen && '!translate-x-0'"`
  (!important menjamin "buka" menang atas statis saat tap hamburger). Pre-Alpine mobile
  sudah tertutup → Alpine set closed = tak berubah = NOL kedip. Transition cuma jalan
  saat operator benar-benar tap. WAJIB `npm run build` (kelas `!translate-x-0` baru;
  public/build gitignore → Docker rebuild saat deploy).
- VERIFIKASI: 188 PASS. Browser 6/6 — bukti utama JS-OFF mobile: aside.x=-256 (off-screen,
  tak flash); tap hamburger→buka(x=0), backdrop→tutup(x=-256), Kios→Dashboard tetap
  tutup, desktop tetap tampil(x=0,w=256). Guard auth-uid/pwa-token-refresh & navigasi
  tak disentuh. (Menutup PENDING FOUC sidebar layout custom owner dari PROMPT_FIX_FOUC.md.)

## OPTIMASI PERFORMA FASE 2 (28 Juni 2026) — SERVER ke OCTANE + FrankenPHP (LIVE)
- TUJUAN: ganti server produksi dari `php artisan serve` (single-thread dev server)
  ke server worker (request paralel, app tak re-bootstrap tiap request).
- HASIL: ✅ LIVE di Railway via DOCKERFILE (image resmi FrankenPHP).
- PENDEKATAN FINAL — Dockerfile (BUKAN nixpacks):
  - 2 percobaan via nixpacks (download binari FrankenPHP manual) GAGAL → 502 persisten.
    Akar yang dicurigai: (a) `config:cache` di BUILD membekukan env DB KOSONG (di
    Railway+Docker/nixpacks env masuk saat RUNTIME, bukan build) + (b) binari manual /
    dir Caddy. Dua-duanya di-rollback ke artisan serve (app tak pernah dibiarkan 502).
  - SOLUSI deterministik: Dockerfile pakai `dunglas/frankenphp:1.12-php8.4`
    (FrankenPHP 1.12.4 + PHP 8.4.22 — BUKAN 8.5; image resmi sudah benar Caddy/writable).
    Multi-stage: stage node build aset Vite (public/build gitignore) → stage frankenphp
    composer install + ekstensi (gd,pdo_mysql,intl,zip,bcmath,opcache,pcntl,mbstring).
  - KUNCI: config:cache + route/view/event:cache + migrate DI-RUNTIME (CMD), bukan build
    → env Railway (DB_*, APP_KEY) sudah ada saat runtime. config/octane.php = DEFAULT
    (FlushAuthenticationState+FlushSessionState+PrepareLivewireForNextOperation UTUH).
  - OPcache: php-ini/opcache.ini → /usr/local/etc/php/conf.d (validate_timestamps=0).
  - PHP version: target 8.3 TAK BISA (Octane butuh FrankenPHP>=1.5.0; FrankenPHP 8.3
    cuma di v1.2.5<1.5.0 → Octane auto-unduh-ulang ke 8.5). Dipilih 8.4 (image php8.4
    tetap 8.4 walau FrankenPHP 1.12). User setuju 8.4.
- ROLLBACK (1 langkah): railway.toml `builder` dari "dockerfile" → "nixpacks" lalu
  redeploy → balik ke artisan serve (nixpacks.toml SENGAJA dipertahankan, known-good).
- VERIFIKASI PRODUKSI (semua LOLOS):
  - 🔒 GATE ISOLASI 240 inspeksi, **0 bocor** (Ismi uid=2 + Aidil uid=5 konkuren,
    240 request campur lewat worker FrankenPHP). Isolasi multi-tenant UTUH di prod.
  - X-Powered-By: PHP/8.4.22 (konfirmasi FrankenPHP live, bukan artisan serve 8.2 lama).
  - 3 role routing 3/3, PWA aset 200, owner dashboard + Filament panel + map-picker
    Leaflet render, latency GET ~95ms.
- DEPLOY: commit 70f6c84 (Dockerfile/.dockerignore/railway.toml). laravel/octane ^2.17
  di composer (require). Lokal: gate isolasi juga lolos 240/0 via WSL+FrankenPHP.
- ⚠️ APP_DEBUG produksi: user cek manual di Railway Variables (belum dikonfirmasi dari sini).

## OPTIMASI PERFORMA FASE 1 (27 Juni 2026) — PAYLOAD TURUN DRASTIS + OPcache
- LATAR: operator lapangan "semua agak lambat, sinyal bagus pun kerasa" → bottleneck
  di app (payload per-klik), bukan jaringan. Diagnosa lengkap menemukan 2 hitter besar.
- FASE 1B — RINGANKAN ActiveTrip (modal operator, dipakai tiap transaksi):
  - MASALAH: koleksi besar (kiosks + kioskFlags + lastOperatorPerKiosk +
    visited/pending/correctedKioskIds) disimpan sebagai PUBLIC PROPERTY → ikut snapshot
    Livewire bolak-balik HP↔server TIAP klik. Untuk trip "Semua Kios" (957 kios Aidil)
    payload membengkak + DOM 957 kartu di-morphdom tiap update.
  - FIX (app/Livewire/Operator/ActiveTrip.php): koleksi besar DIKELUARKAN dari public
    property → dihitung di render() sebagai variabel view (kioskViewData()), TIDAK masuk
    snapshot. loadKiosks() jadi no-op (9 pemanggilan setelah transaksi dibiarkan; render
    menyegarkan otomatis). computeKiosFlags→computeKioskFlags() mengembalikan array.
    Helper baru pendingKioskIdsFor()/lastOperatorFor(). Tambah CAP 50 kartu/layar
    (const DISPLAY_LIMIT) + kotak PENCARIAN (wire:model.live search) → operator tetap
    akses kios mana pun; flag/operator-terakhir/pending dihitung HANYA utk 50 yg tampil.
    Urutan dipertahankan (belum dikunjungi di atas; sort jarak tetap jalan).
  - ANGKA (uji 400 kios, trip Semua-Kios): snapshot Livewire 43.258 B → 1.263 B (−97%);
    HTML/DOM 935 KB → 115 KB (−88%); kartu 400 → 50 (−87,5%). Query render awal tetap 9;
    query buka modal 5 → 11 (trade-off sehat: render bangun ulang daftar ≤50 baris
    terindeks tiap request, ganti meng-hidrate 400-957 model penuh + payload 43 KB).
  - SCOPING multi-tenant + guard (auth-uid, pwa-token-refresh) TIDAK disentuh.
- FASE 1A — OPcache + event cache (nixpacks.toml):
  - Tambah ekstensi php82Extensions.opcache. Set via start cmd (artisan serve = SAPI CLI
    → butuh opcache.enable_cli=1): enable=1, enable_cli=1, validate_timestamps=0,
    memory_consumption=128, max_accelerated_files=10000. Proses serve long-lived →
    opcode ter-cache lintas request.
  - Tambah `php artisan event:cache` ke fase build (config/route/view sudah ada).
  - SERVER artisan serve TIDAK diganti (itu FASE 2).
- VERIFIKASI: 188 PASS (749 lama + 1 tes baru cap/search). Smoke browser operator 8/8 OK
  + bukti DB: list 50/60, search nemu needle (di luar 50), buka modal, Titip 2 mika →
  Delivery id consignment qty=2 tersimpan, kios jadi "Dikunjungi + Ada Titipan".
- ⚠️ APP_DEBUG=false: belum terbaca dari sini (env Railway) — user cek manual di Railway
  Variables, kabarin.
- ⏳ FASE 2 (PENDING, TERPISAH): GANTI `php artisan serve` (single-threaded dev server)
  ke Laravel Octane / FrankenPHP (atau nginx+php-fpm) → request paralel + tak re-bootstrap
  framework tiap request. Ini hitter performa #1 yang belum disentuh. JANGAN lupa.
- CATATAN lain dari diagnosa (belum dikerjakan, dampak lebih kecil): dashboard owner
  poll 30s + agregasi berat (cache + kurangi poll), SESSION_DRIVER=database (+2 query/req),
  index minor (settlements.is_writeoff). Aset operator sudah ramping (Leaflet hanya di
  create-kiosk; Filament 2MB hanya di panel owner/admin, bukan operator).

## RESTYLE (27 Juni 2026) — PROFIL OWNER ke BRAND CEMILAN QONTAS (amber)
- TUJUAN: halaman /profile (dipakai owner & super admin) di-restyle selaras brand
  amber + sembunyikan section "Hapus Akun".
- FILE:
  1. resources/views/profile.blade.php: ROLE-AWARE layout via 1 @extends dinamis
     (auth()->user()->isOwner() ? 'layouts.owner' : 'layouts.brand') + 1 @section
     content bersama (2 kartu putih rounded-2xl beraksen garis gradient amber,
     heading "Profil Saya / Kelola informasi akun Cemilan Qontas kamu").
       - Owner → layouts.owner (sidebar gelap amber "Panel Owner", konsisten
         dashboard owner).
       - Super admin (atau role lain) → layouts.brand = layout brand MINIMAL baru
         (resources/views/layouts/brand.blade.php): top bar gelap "Cemilan Qontas"
         + label peran ("Super Admin"), "← Kembali ke Panel" → homePath() (super
         admin = /admin), Logout. TANPA sidebar resource owner.* → super admin TAK
         kena link 403. Menggantikan fallback x-app-layout Breeze (logo Laravel
         generik) yang lama.
     KEDUA layout memuat guard identitas (meta auth-uid + pwa-token-refresh) →
     super admin TIDAK kehilangan proteksi. Section livewire:profile.delete-user-form
     TIDAK di-render (Hapus Akun tak muncul untuk owner MAUPUN super admin).
  2. livewire/profile/update-profile-information-form.blade.php &
     update-password-form.blade.php: label slate-bold, input focus amber-500,
     tombol "Simpan" bg-amber-600. Teks di-Indonesia-kan (Informasi Profil, Nama,
     Ganti Password, dst). Komponen ini HANYA dipakai profile.blade.php (operator
     punya komponen sendiri operator.* → operator TIDAK terdampak).
  3. Komponen Volt profile.delete-user-form TIDAK dihapus (test direct deleteUser
     tetap hijau) — hanya tak lagi di-render di halaman.
- TEST: ProfileTest::test_profile_page_is_displayed diubah → assertDontSeeVolt(
  'profile.delete-user-form') + assertDontSee('Delete Account'). 2 test deleteUser
  lain tetap (uji komponen langsung). 187 PASS (749 assertions) tetap hijau.
- VERIFIKASI BROWSER (playwright, system Chrome): 11/11 cek OK —
  owner /profile pakai sidebar brand owner (Cemilan Qontas / Panel Owner) + kartu
  amber, Hapus Akun absen, edit nama persist, ganti password TERBUKTI (re-login
  pakai password baru sampai owner dashboard, lalu di-revert ke 'password'). Guard
  lintas-tenant tetap jalan: dari Profil klik Dashboard → tetap 1 akun (auth-uid
  2→2, /owner/dashboard Ismi, bukan owner lain). Operator /operator/profile TIDAK
  berubah (layout & komponen sendiri).
- SUPER ADMIN (27 Juni 2026): /profile super admin diverifikasi 12/12 cek OK —
  layout brand minimal (Cemilan Qontas / Super Admin), TANPA sidebar owner, NOL
  link ke /owner atau filament/owner (tak ada jebakan 403), "Kembali ke Panel"
  → /admin balik 200 (bukan 403), guard (meta auth-uid=1 + pwa-token-refresh)
  utuh di /profile, Hapus Akun absen. Owner regression: tetap sidebar brand owner.
  187 PASS tetap hijau (factory role default 'owner' → render layouts.owner).

## FIX KEAMANAN (26 Juni 2026) — TUTUP KEBOCORAN SNAPSHOT wire:navigate LINTAS-TENANT
- GEJALA: login owner A (Ismi), buka Profil, klik "Dashboard" → kadang muncul
  dashboard owner LAIN (Aidil). Potensi kebocoran data antar-tenant.
- INVESTIGASI (browser + DevTools, dibuktikan, BUKAN teori):
  - SERVER AMAN. Scoping OwnerDashboardController (where owner_id = auth()->id())
    terbukti benar di runtime: tiap request balikin data sesi-live. Tidak ada page
    cache server. Cek via Network: body /owner/dashboard selalu milik user login.
  - bfcache MATI (no-store), Service Worker network-only utk HTML (cache cuma shell
    + /build/*). Dua vektor ini TERTUTUP. /profile ternyata JUGA dapat no-store
    (Livewire auto-set utk halaman berkomponen) → bukan lubangnya.
  - AKAR: snapshot wire:navigate Livewire disimpan di memori SPA / history.state,
    KEBAL terhadap Cache-Control: no-store MAUPUN service worker. Halaman tenant lain
    yang sempat ter-render bisa muncul lagi via klik wire:navigate / back-forward
    TANPA request ke server (server tak sempat memfilter). Ter-reproduksi: snapshot
    Aidil tampil di bawah cookie Ismi, netDocs=[] (nol jaringan).
  - Pemicu nyata: identitas berganti TANPA lewat login/logout app (yg sudah full-reload
    flush) — mis. device dipakai 2 akun, satu tab masih hidup pegang snapshot lama
    saat cookie jar berganti. (remember-me merotasi sesi+token diam-diam = vektor 419,
    TAPI identitasnya tetap sama, bukan penyebab pindah-tenant.)
- SOLUSI (guard identitas SINKRON, deterministik tanpa kedip):
  1. StampAuthUidCookie (middleware web, bootstrap/app.php): cap cookie NON-httpOnly
     `auth_uid` = id sesi-live tiap respons. Dikecualikan dari enkripsi cookie
     (encryptCookies except) agar terbaca JS. Bukan data sensitif — uid sudah ada di
     <meta auth-uid> HTML.
  2. <meta name="auth-uid"> di layout app/owner/operator = uid perender snapshot.
  3. pwa-token-refresh.blade.php — 2 lapis: (a) SINKRON di livewire:navigated+popstate:
     cookie auth_uid vs meta auth-uid, beda → reload SEGERA (sebelum snapshot basi
     terlihat); (b) ASINKRON di pageshow/visibilitychange: fetch /csrf-token (token-sync
     419) + kalau identitas beda → hard-nav ke homePath user benar. /csrf-token kini
     balikin `home`. Akun normal: uid selalu sama → tak pernah reload (no loop).
  4. /profile dapat 'no-store' eksplisit (sabuk pengaman, redundan dgn Livewire).
- TIDAK DISENTUH (sudah terbukti benar): scoping controller, service worker, no-store
  dashboard, alur login/logout.
- VERIFIKASI: 187 PASS (assert /csrf-token balikin home). Reproduksi browser 3x
  deterministik → kelempar ke dashboard ISMI (bukan Aidil). UX 1-akun mulus, 3x resume
  tanpa reload-loop. Smoke test 3 role OK.

## FIX TERAKHIR (25 Juni 2026) — CATAT SISA DODOL saat TUNDA BAYAR
- TUJUAN: pas Tunda Bayar, operator bisa catat sisa dodol total (biji) untuk
  pendataan/prediksi — TAPI tunggakan WAJIB tetap nyangkut (jangan dianggap lunas).
- CARA: numpang field existing, TANPA kolom/migrasi baru.
  1. UI (active-trip.blade.php blok Tunda): input "Sisa Dodol Total (Biji)" bind
     wire:model="sisaBiji" + keterangan "(pendataan saja — tunggakan tetap nyangkut,
     belum dianggap lunas)".
  2. persistVisitFromState (ActiveTrip.php ~:1299): sisa_biji kini ditulis saat
     check_only ATAU $extension (tunda). TIDAK menyentuh $createSettlement/$extension
     (:1145-1146) → Tunda tetap createSettlement=false → titipan tetap pending,
     hitungan max 2x (extension_granted=true) tetap jalan.
  3. Kiosk::latestCheckVisit: filter visit_action='check_only' DILEPAS, cukup
     whereNotNull('sisa_biji') (sisa_biji cuma ditulis visit catat-sisa). Jadi sisa
     dari Cek Sisa ATAU Tunda sama-sama jadi sumber prediksi_habis owner dashboard.
- TEST: test_tunda_bayar_catat_sisa_tanpa_settle_titipan — (a) KioskVisit punya
  sisa_biji, (b) 0 Settlement + titipan doesntHave('settlement')=true, (c)
  extensionCount naik, (d) latestCheckVisit/prediksi_habis baca sisa Tunda. Test
  Cek Sisa & prediksi existing tetap hijau.

## FIX TERAKHIR (25 Juni 2026) — PERJELAS LABEL "KURANGI JATAH" + audit anti tagih-dobel
- LABEL UI (active-trip.blade.php): "Kurangi jatah titipan kios ini" kini diberi
  penjelasan kurung "(jatah = jumlah mika yang biasa dititip ke kios ini ke depannya,
  bukan tagihan sekarang)" agar operator tak salah kira "jatah" = tagihan. Opsi ini
  tetap di accordion Opsi Khusus, tampil saat Tagih Saja & Tagih+Titip (1 blok kode,
  BUKAN duplikasi). Fungsi: ubah kiosk.default_qty_mika, berlaku titipan berikutnya
  (logic willLowerDefault di ActiveTrip.php).
- AUDIT (tanpa ubah kode, dikonfirmasi BENAR): titipan dihitung per-putaran, anti
  tagih-dobel. Bukti: settle bikin Settlement(delivery_id=titipan lama) →
  openVisitModal cari pendingDelivery pakai doesntHave('settlement')->latest('id')
  →first(), jadi titipan lunas permanen keluar dari kandidat tagih. Titip baru =
  Delivery baru terpisah; tagihan dihitung HANYA dari pendingDelivery->qty_delivered
  (1 titipan, LIFO, praktis selalu tunggal). Tidak ada akumulasi/tagih ulang.

## FIX TERAKHIR (25 Juni 2026) — RAPIKAN STOP KEDAI (stop titipan)
- MASALAH lama: tombol "Stop Titipan" muncul di 2 tempat (form Cek Saja & Opsi
  Khusus), hanya bisa kalau titipan = 0, dan TIDAK mencatat transaksi terakhir.
  Membingungkan + berisiko "silent loss".
- SOLUSI: 1 pintu, 2 jalur jelas di layar pilih aksi (tombol "⛔ Hentikan Kedai Ini"):
  (a) STOP + TAGIH TERAKHIR — hanya kalau masih ada titipan. Catat laku/sisa/uang
      via persistVisitFromState() (settle_only) LALU set kios non-aktif DALAM SATU
      DB::transaction (atomik: settle commit dulu, baru nonaktif; settle gagal =
      rollback total, kios tetap aktif).
  (b) STOP TANPA TAGIH — boleh walau ada titipan. Sisa titipan dicatat sebagai
      Settlement KERUGIAN (qty_returned_expired = seluruh sisa, amount_paid=0,
      qty_sold=0, status 'paid', is_writeoff=true). Omset 0, laku 0, titipan
      TERTUTUP → tidak menggantung. Reuse persistVisitFromState (BUKAN jalur baru).
- KONFIRMASI tegas 2-langkah (requestStopConfirm → executeStop); executeStop
  diabaikan tanpa konfirmasi. Stop dihapus dari Cek Saja & Opsi Khusus; partial
  stop-titipan.blade.php dihapus.
- PEMBUKUAN: seluruh omset/HPP/untung/komisi/outstanding digerakkan Settlement.
  Kedua jalur stop MENUTUP titipan via Settlement → tidak ada piutang menggantung.
- REAKTIVASI (owner dashboard) AMAN tanpa kode tambahan: titipan sudah tertutup di
  kedua jalur → kios diaktifkan kembali tak memunculkan pending hantu; Settlement
  loss historis TIDAK perlu dianulir (dodol memang sudah hilang).
- POIN 5 SELESAI: kolom settlements.is_writeoff (migrasi 2026_06_25_000001) +
  widget "Kerugian titipan bulan ini" di section Kios Berhenti owner dashboard
  (mika hilang x HPP per mika owner). Membedakan kerugian write-off dari tagih
  biasa yang kebetulan semua diretur basi.
- ISTILAH UI dijelaskan dalam kurung: "Cut Off" → "Hentikan Kedai (stop titipan)";
  "Dodol Sisa/Basi" → "(dodol rusak/basi yang diretur)"; jalur (a)/(b) bahasa awam.
- TEST: ActiveTripStopKiosTest (7) + DashboardTest kerugian (1) — atomicity &
  silent-loss-tercegah terverifikasi.

## FIX TERAKHIR (17 Juni 2026) — BUG MULTI-TENANT Cache SW (KRITIS)
- BUG: setelah logout & login akun lain, dashboard akun SEBELUMNYA yang muncul;
  refresh → 403. Penyebab: service worker v1 nge-cache halaman HTML ter-auth
  per-URL TANPA bedakan user. /owner/dashboard di-cache untuk akun A → akun B
  (tenant lain, URL sama) dapat halaman A. Diperparah: wire:navigate ambil halaman
  via fetch() (mode 'cors', BUKAN 'navigate') → lolos ke cabang cacheFirst di sw.js
  v1 → halaman ter-auth tetap tersimpan. Session server sendiri SUDAH benar
  (logout invalidate + regenerateToken).
- FIX A (sw.js, akar masalah): CACHE_NAME 'dodol-v1' → 'dodol-v2' (activate purge
  SEMUA cache lama → bersihkan halaman ter-auth yg terlanjur tersimpan di HP user).
  Navigasi HTML kini NETWORK-ONLY (networkOnlyPage, tak ada cache.put halaman).
  Deteksi halaman pakai mode 'navigate' DAN header Accept: text/html (menutup celah
  wire:navigate). Offline → offline.html generik, bukan dashboard lama. App shell +
  /build/* + ikon TETAP cache-on-fetch (PWA installable & offline shell utuh).
- FIX B (klien): partials/pwa-cache-clear.blade.php di guest layout (login). Saat
  login dimuat: caches.keys() → hapus semua cache KECUALI dodol-v2 (guard
  'caches' in window). Device yg sudah kena bug langsung bersih saat buka login.
  SW TIDAK di-unregister.
- FIX C (jaring pengaman): middleware NoStoreAuthPages (alias 'no-store' di
  bootstrap/app.php) → Cache-Control: no-store, no-cache, private, must-revalidate
  + Pragma: no-cache. Di-attach ke grup /dashboard, owner.*, operator.*. Filament
  TIDAK disentuh.
- FIX D (delay operator): SW kini BYPASS total request /livewire/* + non-GET; HTML
  network-only (sebelumnya tiap navigasi tulis HTML ke Cache Storage = overhead).
  N+1: DICEK, TIDAK ADA (Operator\Dashboard = 3 query agregat; Operator\ActiveTrip
  sudah bulk whereIn/groupBy/keyBy, foreach cuma olah koleksi termuat). TIDAK
  di-refactor.
- 177 PASS tetap hijau. Verifikasi route:list: NoStoreAuthPages terpasang di
  owner/dashboard.

### ⚠️ CATATAN PENTING / PENDING (per 17 Juni 2026)
- User WAJIB TUTUP-BUKA APP 2x di HP agar SW update v1→v2 (1x download SW baru +
  activate, refresh berikutnya pakai v2). Skrip login (B) jadi jaring pengaman.
- PENDING — Region Railway US WEST: latency dari Medan bikin tiap klik operator
  (POST Livewire round-trip) terasa beberapa detik. Itu latency JARINGAN, bukan
  kode (sudah dipastikan SW & query bersih). Opsi: pindah region Railway ke Asia
  Tenggara (Singapore) — perlu keputusan + kemungkinan re-deploy + migrasi DB.
- ~~PENDING — Menu operator "cek kedai / kedai tutup" tidak muncul~~ → SELESAI
  (17 Juni 2026). Hasil investigasi: 3 opsi (Tagih+Titip/Tagih Saja/Tunda) untuk
  kios bertitipan memang BY-DESIGN, bukan bug. DITAMBAH fitur baru di bawah.

## FIX TERAKHIR (17 Juni 2026) — 419 "Page Expired" TUNTAS (operator lapangan)
- GEJALA: operator close PWA → buka lagi → GET dashboard OK → klik LOGOUT (POST)
  → 419, walau sesi baru beberapa menit (BUKAN idle 8 jam).
- AKAR: token @csrf beku di DOM saat render. remember-me (~5thn) merotasi sesi+token
  diam-diam saat PWA dibuka ulang (cookie sesi mobile sering drop saat app close →
  remember-me re-auth → sesi+token BARU). GET aman (re-auth mulus), POST logout kirim
  token LAMA ≠ token baru → 419. DIPERPARAH no-store kemarin (mematikan bfcache →
  pwa-bfcache-guard reload-on-persisted TAK PERNAH menyala). Bukan soal SESSION_LIFETIME.
  (SESSION_EXPIRE_ON_CLOSE tidak di-set di Railway → default false; diagnosis tetap
  valid, akar = remember-me rotasi + token statis.)
- FIX #2 (logout kebal CSRF): bootstrap/app.php validateCsrfTokens(except: ['logout']).
  Logout tetap butuh auth+POST, hanya CSRF di-skip → klik logout token APAPUN → mulus,
  tidak 419. (Kegagalan CSRF logout itu jinak: paling buruk = ter-logout.)
- FIX #1 (AKAR: token selalu sinkron): endpoint GET /csrf-token (auth, no-store)
  balikin {token, uid}. Partial partials/pwa-token-refresh.blade.php: pada pageshow
  (SEMUA) + visibilitychange→visible → fetch token segar → update <meta csrf-token>
  + semua input[name=_token] (termasuk logout). Sesi habis → ke login. Di-include di
  layout operator/owner/app.
- FIX #3 (handler 419 ramah, form NON-logout): bootstrap/app.php withExceptions tangkap
  TokenMismatchException → user auth: redirect homePath() + flash "Sesi diperbarui,
  silakan ulangi"; tak auth: ke login. Request Livewire (X-Livewire) dilewatkan (status
  419 → frontend Livewire handle sendiri). ⚠️ SENGAJA TIDAK auto re-submit (cegah
  transaksi settlement/visit dobel = kerusakan keuangan) — operator ulangi di form segar.
- FIX #4 (rapikan): pwa-bfcache-guard DIHAPUS (reload-on-persisted impoten karena
  no-store). Diganti pwa-token-refresh yang juga deteksi identitas: kalau uid sesi
  terkini ≠ uid render halaman → location.reload() (multi-tenant), BUKAN reload buta.
- MULTI-TENANT kemarin TIDAK rusak (dikonfirmasi): full-reload login/logout (no
  navigate:true) utuh, SW dodol-v2 + no-store utuh, role middleware 403 cross-role utuh
  (RoleMiddlewareTest hijau). Deteksi-identitas pengganti guard malah LEBIH kuat (aktif,
  tak bergantung e.persisted).
- TEST BARU: CsrfTokenRefreshTest (endpoint butuh auth, balikin token+uid+no-store,
  logout route mulus). 182 PASS (704 assertions).

## FIX TERAKHIR (17 Juni 2026) — AKAR bug multi-tenant: wire:navigate (bukan SW)
- Bug multi-tenant MASIH terjadi setelah fix SW kemarin: login owner → logout →
  login operator → dashboard owner lama muncul → refresh → 403 "Role tidak sesuai".
- DIAGNOSIS: AKAR sebenarnya BUKAN service worker (sw.js v2 sudah benar) & BUKAN
  sesi server (logout invalidate+regenerate sudah benar). Penyebab: `navigate: true`
  pada redirect login & logout → transisi auth jadi navigasi SPA Livewire tanpa full
  reload. wire:navigate menyimpan SNAPSHOT halaman di sisi JS (in-memory +
  history.state) yang KEBAL terhadap Cache-Control: no-store & TAK tersentuh service
  worker. Snapshot /owner/dashboard bertahan & ditampilkan ke sesi operator.
  403 = bukti server BENAR (operator), yang salah render cache klien di URL owner.
- BUG TAMBAHAN kemarin: skrip pwa-cache-clear dipasang di layouts/guest.blade.php,
  TAPI login pakai #[Layout('layouts.blank')] (blank = {{ $slot }}) → skrip TIDAK
  pernah jalan di login. Diperbaiki.
- FIX 1 (akar): HAPUS `navigate: true` di SEMUA batas auth → full document load yang
  mem-flush snapshot wire:navigate. File: login, register, verify-email (masuk+logout),
  confirm-password, reset-password→login, navigation (logout Breeze), delete-user.
  Logout operator/owner sudah pakai POST form (full reload) → tidak diubah.
  navigate:true INTRA-panel operator (StartTrip/CreateKiosk) DIBIARKAN (bukan lintas akun).
- FIX 2: pwa-cache-clear kini di-include langsung di <head> login.blade.php (layout
  yang benar-benar dipakai login).
- FIX 3 (defensif bfcache mobile): partial pwa-bfcache-guard — listener pageshow,
  reload HANYA bila e.persisted (dari bfcache) DAN pathname /owner|/operator|
  /dashboard|/admin. Di-include di layout operator/owner/app (TER-AUTH saja), TIDAK
  di login/landing → tak ada reload-loop.
- ALUR BARU: login/logout = full reload → snapshot wire:navigate ter-flush → mustahil
  lagi menampilkan dashboard akun sebelumnya. 179 PASS tetap hijau (test auth pakai
  assertRedirect target, tak terpengaruh hilangnya navigate:true).
- STATUS: RESOLVED pending konfirmasi user di HP (tutup-buka app agar SW v2 + kode
  baru ter-deploy). Kalau masih muncul, cek bfcache browser spesifik / Railway deploy.

## FIX TERAKHIR (17 Juni 2026) — Opsi "Cek Sisa" untuk kios BERTITIPAN
- BARU: operator bisa pilih "👀 Cek Sisa (tanpa tagih)" pada kios yang masih punya
  titipan aktif → catat sisa dodol (biji) + alasan kunjungan (Kios Tutup / Minta
  Tunggu / Tidak Ada Uang / Dodol Masih Ada) TANPA menyelesaikan/mengubah titipan.
  Skenario: kios tutup / pemilik minta tunggu sampai dodol habis → titipan tetap
  jadi tunggakan, sisa biji dipakai prediksi habis di dashboard owner.
- Sebelumnya "Cek Sisa" cuma ada untuk kios TANPA titipan (cabang @else).
- JEBAKAN yang diatasi: resolveVisitAction() auto-detect → kios bertitipan + drop=0
  SELALU resolve ke settle_only (akan menutup titipan tak sengaja). Ditambah guard
  PALING ATAS: if (chosenAction === 'cek') return 'check_only'. Titipan TIDAK
  ter-settle (no Settlement, no Delivery, settled_delivery_id null → tetap pending).
- EDGE-CASE koreksi (poin penting): correctVisit() kini set chosenAction=null saat
  netralisasi flag — supaya guard 'cek' tak pernah keliru memaksa check_only saat
  mengoreksi visit tagih/settle (yang akan merusak titipan). Koreksi visit
  check_only sendiri sudah diblokir openCorrectionModal ("tidak punya angka").
- File: ActiveTrip.php (guard resolveVisitAction + whitelist chooseAction + reset
  chosenAction di correctVisit), active-trip.blade.php (tombol ke-4 cabang bertitipan).
- TEST BARU: ActiveTripCekSisaBertitipanTest — buktikan kios bertitipan + Cek Sisa
  → TIDAK buat Settlement/Delivery, titipan TETAP pending, check_only ber-sisa_biji
  terisi, prediksi habis terbaca. ActiveTripActionPickerTest diperbarui ('cek' kini
  valid utk kios bertitipan). 179 PASS (693 assertions).

## FIX TERAKHIR (17 Juni 2026) — PWA Auto-login & Redirect Role
- Buka PWA → langsung dashboard sesuai role (tak mampir landing), login persist.
- routes/web.php "/": sekarang AUTH-AWARE — auth()->check() → redirect route('dashboard')
  (role-aware); belum login → tetap tampilkan landing (marketing TIDAK dihapus).
- manifest start_url "/" → "/dashboard" (scope tetap "/"). Belum login di /dashboard
  → middleware auth otomatis lempar ke /login (aman).
- Satu sumber kebenaran redirect role: User::homePath() (super_admin→/admin,
  owner→owner.dashboard, operator→operator.dashboard). Dipakai route /dashboard
  DAN LoginResponse (Filament) — sebelumnya LoginResponse hardcode owner.dashboard
  (bug minor) kini role-aware konsisten.
- LoginForm $remember default true (app internal operator → tak logout tiap tutup
  app; remember cookie Laravel ~5 thn). Checkbox "Ingat Saya" tetap ada untuk uncheck
  di perangkat publik. SESSION_LIFETIME tetap 480 (8 jam), expire_on_close false.
- 177 PASS (tak ada test yang assert "/" landing untuk user login).
- ⚠️ PWA yang SUDAH ter-install di HP perlu re-install / clear data agar start_url
  baru (/dashboard) terbaca — manifest di-cache OS. Install baru langsung dapat.

## FIX TERAKHIR (17 Juni 2026) — PWA Setup
- PWA penuh (installable + offline shell) SELESAI. Operator bisa install ke home
  screen HP, buka fullscreen tanpa address bar, app shell tetap kebuka saat sinyal jelek.
- Ikon amber tipografi "Q" (gradient #F59E0B→#D97706) di-generate via
  scripts/generate-pwa-icons.php (PHP GD). File di public/: icon-192/512.png,
  icon-maskable-192/512.png (safe-area padding biar tak kepotong squircle Android),
  apple-touch-icon.png (180px iOS), favicon.png + favicon.ico (ganti favicon 0-byte).
- public/manifest.webmanifest: name/short_name Cemilan Qontas, display standalone,
  orientation portrait, theme_color #F59E0B, icons any + maskable. start_url & scope "/".
- public/sw.js (vanilla, tanpa dependency): NETWORK-FIRST untuk navigasi HTML/data
  (data kios/settlement selalu fresh saat online; fallback cache → offline.html cuma
  saat benar-benar offline). Aset Vite ber-hash (/build/*) cache-on-fetch (TIDAK
  hardcode nama file hash → tak perlu update sw.js tiap deploy). POST/non-GET
  (Livewire update) TIDAK pernah di-cache. Versioning CACHE_NAME='dodol-v1' +
  cleanup cache lama saat activate. public/offline.html = fallback page.
- Head tags (manifest, theme-color, apple-touch-icon, meta iOS) + registrasi SW
  via partial resources/views/partials/pwa-head.blade.php, di-include di layout
  operator + guest (login) + app + owner. Panel Filament (admin & owner-panel)
  dapat head PWA via render hook PanelsRenderHook::HEAD_END di AppServiceProvider
  (reuse partial yang sama). Registrasi SW guarded ('serviceWorker' in navigator).
- Verifikasi: 177 PASS, manifest valid JSON, semua ikon non-0-byte, tak ada file
  PWA ke-exclude gitignore (*.sql tidak kena public/*.png).
- CARA INSTALL DI HP: buka https://dodol-app-production.up.railway.app di Chrome
  Android → menu ⋮ → "Install app" / "Add to Home screen". iOS Safari → Share →
  "Add to Home Screen". (Wajib HTTPS — Railway sudah HTTPS.)

## FIX TERAKHIR (16 Juni 2026)
- Anti-flash (FOUC) Filament admin panel saat load pertama / cold cache di
  PRODUKSI. Penyebab: timing first-paint — layout Filament sempat berantakan
  sebelum CSS eksternal ter-apply (normal setelah refresh / aset ter-cache).
  Solusi (Opsi 3): renderHook PanelsRenderHook::HEAD_START di AdminPanelProvider
  inject inline CSS+JS. Body disembunyikan (opacity:0) lalu fade-in saat event
  `load` (semua aset selesai). FALLBACK aman: (1) class fi-flash-guard hanya
  ditambah JS — JS mati = body tetap tampil; (2) reveal dipicu DUA jalur:
  event `load` + setTimeout 2 detik darurat; (3) tidak pakai Alpine, jadi
  kegagalan Alpine tak menyembunyikan halaman. TIDAK pakai ->spa().
  Owner panel (ada Leaflet) sengaja TIDAK diubah — render hook scoped ke admin.
- MASIH PENDING (lihat PROMPT_FIX_FOUC.md): FOUC sidebar di layout CUSTOM
  super-admin/owner dashboard (beda dari panel Filament ini).

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
- Lokasi kios opsional + ambil GPS otomatis (form Kios Baru: auto-GPS hybrid — GPS jalan sendiri sekali saat form kebuka kalau koordinat masih kosong; tetap bisa koreksi titik via klik peta / tombol GPS manual; auto-trigger senyap kalau gagal. Butuh HTTPS — aman di Railway)
- Halaman profil operator selaras layout operator + form bertema amber (tanpa ubah /profile owner & super admin)
- Jual Cash Cepat (walk-in): operator catat penjualan cash ke pembeli non-kios via kios sentinel tersembunyi per owner; omset masuk komisi, sentinel di-exclude dari listing & laporan per-kios (artisan walkin:ensure-sentinel untuk provisi owner lama)
- Koreksi angka visit (SELESAI — backend + UI): operator bisa koreksi angka (drop mika, uang diterima, retur) pada kunjungan TERAKHIR ke kios selama trip aktif lewat tombol "Koreksi" di kartu kios yang sudah dikunjungi (form ke-isi angka lama, badge "Dikoreksi"). Prinsip reversal: record finansial lama dihapus, baris kiosk_visits lama disimpan + ditandai corrected_at (audit trail), angka baru ditulis ulang via persistVisitFromState (1 sumber kebenaran dgn saveVisit). Linkage deterministik deliveries.kiosk_visit_id. Larangan: trip ended, bukan visit terakhir, visit yang ubah default_qty_mika, kios walk-in, check_only tanpa angka. Semua agregat kiosk_visits pakai scope ->active().
- Foto kios R2-ready (SELESAI): disk foto configurable via env MEDIA_DISK (default 'public' lokal; set 's3' saat R2/S3 siap → foto persist melewati redeploy Railway). Kompres otomatis di browser sebelum upload — operator: canvas vanilla (sisi maks 1280px, JPEG 0.7, fallback file asli kalau gagal); owner panel Filament: Filepond imageResize 1280. Accessor Kiosk::photo_url (baca disk media), dipakai di view. resize server-side jadi jaring pengaman & hanya jalan di disk lokal. Kredensial R2 BELUM diisi (env kosong, app tetap jalan di lokal).

## DEPLOY RAILWAY (PRIORITAS UTAMA)
> Tunggu konfirmasi user dulu sebelum mulai deploy.
> CONFIG SIAP: nixpacks.toml + railway.toml sudah di repo. Repo siap di-connect ke Railway.
> Build: composer install --no-dev + npm ci + npm run build → cache config/route/view.
> Start: migrate --force + storage:link + serve di $PORT. Aset Filament & Leaflet sudah
> ter-commit (tidak di-build saat deploy). Seeding MANUAL sekali setelah deploy pertama.
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
