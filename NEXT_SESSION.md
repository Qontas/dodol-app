# NEXT_SESSION.md — Dodol-App
*Sesi terakhir: 12 Juli 2026*

## TIGA PERBAIKAN (12 Juli 2026, sesi terbaru) — SELESAI & PUSHED
**320 PASS** (dari baseline 313; +7 test baru, 0 regresi). Tiga commit atomik terpisah.

1. **BUG LOGIN GAGAL → HALAMAN PUTIH (FIXED, akar dikonfirmasi browser).** Login gagal
   (password salah ATAU email tak terdaftar — termasuk email yang sudah diganti, mis.
   operator@ → madan@ lalu login pakai email lama) me-render **BLANK PAGE PUTIH** tanpa
   pesan. AKAR (direproduksi Playwright, bukan tebakan): halaman login adalah komponen
   Livewire/Volt full-page yang view-nya me-render **seluruh dokumen `<!DOCTYPE html>`**
   di bawah `layouts.blank` (cuma `{{ $slot }}`) → root komponen = `<html>`. Saat login
   gagal, morph error-validasi Livewire mengosongkan `<body>` (JS error persis: *"Could
   not find Livewire component in DOM tree"*, `body innerHTML len: 0`). KENA **SEMUA**
   login gagal (bukan cuma email-diganti — itu cuma cara owner menemukannya). FIX: layout
   baru `layouts/auth.blade.php` memegang doctype/head/body; view login jadi **satu root
   `<div>`** → error tampil inline, tak pernah blank. Plus pesan Indonesia jelas
   (`LoginForm`): kredensial salah → "Email atau password salah." (dulu `trans('auth.failed')`
   = key mentah "auth.failed" karena tak ada lang file); akun nonaktif kredensial-benar →
   logout + "Akun Anda dinonaktifkan. Hubungi admin." (dulu dibiarkan masuk). Bukti: 3/3
   role login+redirect OK (driver.cjs), 2 kasus gagal → pesan tampil (repro-login-blank.cjs).
2. **UBAH JATAH PERMANEN → HANYA AKSI 1.** Checkbox ubah-jatah DIHAPUS dari AKSI 2 (Titip
   Cash) & AKSI 3 (Lewati) — UI + logika + state (`jatahBaru`, `applyJatahCek`,
   `updatedUbahJatah` dibuang total; `changed_default=false` di Aksi 2/3). Alasan owner:
   Titip Cash BEBAS naruh berapa saja (tak terikat jatah) & Lewati tak menaruh apa pun →
   ubah-jatah tak relevan. AKSI 1 TETAP: ubah-jatah satu-angka + blokir 2-langkah jalan.
3. **BS DI AKSI 2 (TITIP CASH).** Field "Barang Sisa / BS (Biji)" ditambah di Titip Cash
   (satuan BIJI, seperti Aksi 1). RUMUS: `cash = (biji_ditaruh − biji_BS) × 800`. BS =
   **kerugian owner** lewat mekanisme yang SAMA dgn Stop Tanpa Tagih (settlement
   `is_writeoff=true` + `qty_returned_expired` → satu laporan kerugian owner, dihitung
   `× HPP/mika`). qty_delivered cash_sale = mika DITARUH **penuh** → komisi (basis DROP,
   `getTotalDropReal`) TIDAK terpengaruh BS. Titipan lama TIDAK di-settle; jatah TETAP.
   CONTOH owner jadi test: jatah 4, naruh 3 mika (45 biji), BS 3 biji → cash Rp 33.600,
   BS 3 biji kerugian (dashboard: 3 biji = 0,2 mika × 9.500 = Rp 1.900), jatah tetap 4,
   titipan lama utuh, komisi = 3 mika. `TitipCashBsTest` (5 test) + guard BS > biji ditaruh.

## TRIGGER SENTENCE
Bg, lanjut dodol-app. Live di Railway. **PENYEDERHANAAN FORM SERAH TERIMA JADI 3-AKSI SELESAI &
PUSHED (12 Juli 2026)** — form kunjungan operator dirombak jadi 3 aksi tegas: **AKSI 1 "Tagih +
Titip Ulang"** (siklus normal: BS per biji, tagih yang laku, titip ulang sejumlah jatah), **AKSI 2
"Titip Cash"** (naruh ekstra dibayar tunai, TIDAK nagih titipan lama, jatah tetap — dulu checkbox
"Tambah cash sekali" yang nempel, kini aksi mandiri), **AKSI 3 "Lewati / Belum Habis"** (0 transaksi,
catat sisa+catatan). **BLOKIR 2-LANGKAH** (ganti blokir lama titip>jatah + rencana peringatan-lembut):
titip konsinyasi HARUS = jatah kios; titip != jatah (kurang ATAU lebih) tanpa centang "Ubah jatah" →
DITOLAK dua lapis (UI tombol disabled "Betulkan titip = jatah dulu" + kotak merah "Titip harus sama
dengan jatah (X)", DAN server persistVisitFromState() tetap tolak walau tombol diakali). Verifikasi
2-langkah: mau titip beda? centang "Ubah jatah permanen" dulu (aksi sadar). **UBAH JATAH SATU-ANGKA**:
angka yang ditaruh = jatah baru (buang field `jatahBaru` terpisah + auto-peek `updatedUbahJatah/
updatedJatahBaru` yang jadi biang bingung; AKSI 3 pakai 1 field mandiri karena tak ada yang ditaruh).
Pengecualian blokir: kios BARU (jatah null) → titip pertama netapkan baseline tanpa blokir; KOREKSI
angka exempt (correctVisit tak kena blokir). **FLAG KIOS LAMA vs BARU tanpa kolom baru**: "punya
titipan aktif" murni diturunkan dari data = ada Delivery konsinyasi PENDING (persis `$pendingDelivery`)
— TIDAK ada kolom flag. Kios LAMA (migrasi, sudah ada titipan berjalan) dibuat lewat helper baru
`App\Support\OpeningBalance::create()` (1 delivery konsinyasi pending, idempoten) yang di-trigger
toggle "Kios lama" di Filament create-kiosk (`CreateKiosk::afterCreate`) → kios langsung bisa
"Tagih + Titip Ulang" di kunjungan pertama; sejalan dgn command `kios:saldo-awal` untuk migrasi massal.
Operator create-kiosk = selalu kios baru (tak berubah). **MODAL BS = 633/biji** (HPP asli 9.500÷15,
BUKAN 800 = harga jual) — TERNYATA SUDAH BENAR di `OwnerDashboardController` (kerugian write-off pakai
`hpp_per_mika`), tak ada perubahan. BS SUDAH per biji (tak ada migrasi). **311 test PASS** (dari
baseline 296; +15 test 3-aksi baru/rewrite; 0 regresi) + headed browser confirm (3-aksi picker +
blokir tampil, read-only tanpa save). TIDAK DISENTUH: Hentikan Kedai, walk-in, cash-only, mesin
omset/komisi inti (getTotalDropReal, SUM amount_paid). Lihat section "SERAH TERIMA 3-AKSI (12 Juli)". KOMISI RIAN ganti ke BASIS DROP (Opsi Y) SELESAI & PUSHED
(11 Juli 2026) — Rp 1.000 × mika yang Rian BAWA & DILETAKKAN (bukan laku), exclude BS daur-ulang &
stop-tanpa-drop, bonus kios-baru dilebur ke tarif flat. Ada migration (default tarif 500→1.000 utk
owner baru; owner lama set sendiri di /owner/settings). Lihat section "KOMISI RIAN → BASIS DROP
(OPSI Y)". Sebelumnya (juga 11 Juli): FITUR UBAH-JATAH / CASH-SEKALI / BLOKIR di form Serah Terima
operator SELESAI & PUSHED (HEAD 67220ad) — lihat section "REWORK SERAH TERIMA: UBAH JATAH DUA-ARAH
+ CASH SEKALI + BLOKIR". Sebelumnya: Fitur URUTAN KEDAI PER-AREA (sort_order) — Track 1
SELESAI (9 Juli 2026): kolom `sort_order` per-cluster di kiosks, owner atur lewat drag
(`->reorderable()`) ATAU ketik angka langsung di list (`TextInputColumn`), operator (`ActiveTrip`)
lihat kios terkelompok per-area dengan urutan yang SAMA — dibuktikan headed browser, owner→operator
identik persis. FIX UX LANJUTAN (9 Juli 2026, sesi berikutnya): angka `sort_order` dulu MEMBOLEHKAN
duplikat (isi "3" di 2 kios → dua-duanya jadi 3) — SUDAH DIFIX pakai auto-reflow
(`Kiosk::reorderWithinCluster()`, pola insert-within-list ala Notion/Trello, 9 test baru) — isi
angka yang sudah dipakai kios lain di area yang sama sekarang otomatis menggeser (gapless, tak
pernah kembar), bukan ditolak/dibiarkan. Drag TETAP dipertahankan + SEKARANG TERKUNCI otomatis:
tombol reorder SEMBUNYI TOTAL kecuali TEPAT 1 Area difilter (`->reorderable('sort_order', fn
($livewire) => count($livewire->tableFilters['cluster_id']['values'] ?? []) === 1)`) — menutup
jebakan "drag lintas-area" yang ditemukan pas verifikasi Track 1, sekarang mustahil salah jalan
secara fisik (bukan cuma diperingatkan). CATATAN: 956 kios Aidil masih SEMUA di 1 cluster (rencana
pisah-area belum terjadi) — itulah kenapa opsi "drag-only" DITOLAK saat investigasi, angka
anti-duplikat jadi mekanisme utama yang scalable sekarang; desain per-cluster sudah siap kalau
area dipecah nanti tanpa perlu ubah kode lagi. Lihat section "FIX UX URUTAN KEDAI: ANTI-DUPLIKAT
(AUTO-REFLOW) + DRAG TERKUNCI-FILTER" untuk detail lengkap, dan "FITUR URUTAN KEDAI PER-AREA
(sort_order) — TRACK 1" untuk fondasi awalnya. ⚠️ CATATAN OPERASIONAL Track 1 (masih relevan):
Filament native reorder-mode MEMATIKAN grouping/defaultSort custom sepenuhnya saat aktif
(`CanSortRecords.php:77-78`) — makanya drag sekarang dikunci filter-1-area di atas, bukan cuma
helper text. Track 2 (smart routing: prioritas kios laris lintas-area + rekomendasi kios/area
terdekat berikutnya pas stok masih ada) DICATAT SEBAGAI BACKLOG TERPISAH, BELUM DIBANGUN — lihat
section "TRACK 2 — SMART ROUTING (BACKLOG, BELUM DIBANGUN)" untuk status fondasi data. Bug minor
UI dropdown Area (Choices.js
arrow-key leak teks "arrowdown"/"arrowright"/dst ke search box abis Esc) FIXED TUNTAS (8 Juli
2026) — fix pertama (6696a87) cuma allowlist ArrowDown/Up, BOCOR ke ArrowRight (whack-a-mole);
fix final generik pakai kriteria `e.key.length <= 1` yg nutup SEMUA named key sekaligus (panah 4
arah + Home/End/PageUp/PageDown/dst), bukan tambal per-tombol. Root cause di Choices.js sendiri
(bug upstream, bukan kode kita), fix via render hook global tanpa sentuh vendor. Lihat section
"FIX BUG UI: ARROW-KEY BOCOR JADI TEKS DI DROPDOWN SEARCHABLE (CHOICES.JS)" + subsection
"LANJUTAN". Audit isolasi multi-tenant MENYELURUH selesai
(3 skenario dibuktikan pakai test): super_admin bikin owner baru → 0 data nyangkut; owner A tak
bisa baca/tulis data owner B (404/ditolak semua); operator owner A tak bisa sentuh kios/trip
owner B (4 lubang lama masih tertutup). TEMUAN residual ProductVariant tanpa OwnerScope global
SUDAH DIFIX (6 Juli 2026): scope global terdaftar via ProductVariant::booted(), pola identik
Kiosk, cabang terpisah di OwnerScope::apply() — Kiosk & 5 model Level-1 lain TAK REGRESI (semua
test masih hijau), super_admin tetap bypass. Fitur input lokasi Google Maps (Tier 1+2) SELESAI &
PUSHED (HEAD 47ed5ac), Tier 2 masih mock-tested only — TODO user tes link pendek asli. Peta OWNER
kini pakai Leaflet HAND-ROLLED (App\Filament\Forms\Components\LeafletMapPicker), dotswan/
filament-map-picker DIHAPUS TOTAL (3x sumber bug integrasi) — lihat section "MIGRASI PETA OWNER
DOTSWAN → LEAFLET". Service Worker (public/sw.js) NAIK ke v5 (7 Juli 2026): default cache jadi
aman-by-default (network-first), fetch validasi eksplisit `cache:'no-cache'`, `registration.update()`
ditambah — lihat section "FIX CACHE-BUSTING SW v5". Audit session/trip persistence (7 Juli 2026)
— VERDICT AMAN, dibuktikan test: trip operator DB-based & auto-resume, TIDAK hilang walau
logout/sesi habis di lapangan — lihat section "AUDIT SESSION & TRIP PERSISTENCE". Foto kios list
owner: FIX DEFINITIF (7 Juli 2026) — foto sekarang di-proxy same-origin lewat
`KioskPhotoController` (`/kiosks/{id}/photo`), browser TAK PERNAH LAGI konek langsung ke r2.dev
(akar net::ERR_CERT_COMMON_NAME_INVALID transien di level koneksi browser ke domain CDN
cert-wildcard bersama, dikonfirmasi bukan app/server/jaringan tapi tak actionable dari app —
solusinya hilangkan ketergantungan ke domain itu sama sekali). Bonus: isolasi tenant utk foto
(OwnerScope pada route model binding) + cache-first SW (offline utk operator). VALIDASI MENDALAM
pasca-deploy (Ronde 4) menemukan 1 REGRESI NYATA — widget upload form create/edit kios pakai
jalur URL R2 langsung sendiri (Filament bawaan, kelewat saat migrasi) → SUDAH DIFIX & diverifikasi
produksi (kios nyata). Operator DIKONFIRMASI tetap jalan, 0 regresi lain ditemukan. Lihat section
"FIX FOTO KIOS KADANG KOSONG" (terutama RONDE 4). Advisor tulis brief kerja langsung di chat
(code block), BUKAN file PROMPT.md lagi. Baca NEXT_SESSION.md untuk context lengkap.

## KOMISI RIAN → BASIS DROP (OPSI Y) (11 Juli 2026) — SELESAI & PUSHED
Owner ganti aturan komisi (keputusan final). Dari basis LAKU (mika terjual × Rp 500 + bonus kios-
baru terpisah × Rp 1.000) → **basis DROP (Opsi Y): Rp 1.000 × mika yang Rian BAWA & DILETAKKAN**
(dihitung saat drop, bukan saat laku), **exclude BS daur-ulang & stop-tanpa-drop**, **bonus kios-
baru DILEBUR** ke tarif flat (kedai baru = tarif sama). Data asal-usul sudah ada (`delivery_type=
'bs_redistribution'` menandai BS; stop+tagih selalu drop=0 → otomatis 0 komisi), jadi tak perlu
tracking baru.

Implementasi (Trip.php): tambah accessor `mika_komisi` = `getTotalDropReal()` (drop exclude BS);
`komisi_rian` = `mika_komisi × komisi_per_mika` (tarif default 1.000); `komisi_reguler` jadi alias
`komisi_rian`, `komisi_kios_baru` = 0 (legacy alias, biar konsumen lama tak pecah). **SENGAJA TIDAK
menyentuh `mika_terjual`/`omset_val`/`untung_kotor`** (tetap basis LAKU, sudah benar) — komisi
metrik TERPISAH. `untung_bersih_owner` berubah HANYA karena = untung_kotor − komisi (by design).
LiveTripProgress (hitung komisi sendiri utk trip berjalan) diubah ke drop-basis juga. User::
getKomisiPerMikaValue fallback 500→1.000. Display komisi (dashboard, PDF, Excel, controller) dilebur
jadi satu "Komisi Rian" + "Mika Komisi (Drop)". confirmEndTrip note diperbarui.

MIGRATION: `2026_07_11_000001_change_komisi_per_mika_default_to_1000` — HANYA ubah DEFAULT kolom
(owner/tenant BARU dapat 1.000). Baris owner LAMA TIDAK diubah (gaji operator = keputusan owner
eksplisit) → **owner lama yang masih Rp 500 WAJIB set 1.000 di /owner/settings**. Auto-migrate
Railway jalan saat deploy. `komisi_kios_baru_per_mika` (setting) kini DORMANT/tak dipakai — bisa
dihapus di cleanup lanjutan.

Test: KomisiDropBasisTest (7: drop-basis-bukan-laku, BS-exclude, stop-tanpa-drop=0, kedai-baru-sama,
walk-in, ubah-jatah-naik semua-drop, regresi omset/untung). Update HppPerOwnerTest, LiveTripProgress
Test ke basis drop. ⚠️ Ambiguitas "ekstra jatah": ubah-jatah 2→4 drop 4 penuh (bukan cuma 2 ekstra)
→ semua 4 komisi (basis drop konsisten: tiap mika DILETAKKAN dihitung). SELISIH komisi lama-vs-baru
tergantung trip (tarif 2×) — owner review beberapa trip nyata di dashboard pasca-deploy.

## REWORK SERAH TERIMA: UBAH JATAH DUA-ARAH + CASH SEKALI + BLOKIR (11 Juli 2026) — SELESAI, HEAD 67220ad
Investigasi + rework alur "kurangi/ubah jatah titipan" di form Serah Terima operator
(`app/Livewire/Operator/ActiveTrip.php` + `resources/views/livewire/operator/active-trip.blade.php`)
sesuai ground-truth owner. **296 PASS, 0 regresi. TANPA migration (kolom `default_qty_mika` &
`changed_default` sudah ada) → deploy biasa, tak perlu auto-migrate khusus.**

DUA jalur nambah/ubah mika yang BEDA arti bisnis, kini DIPISAH BERSIH & mutually-exclusive:
- **JALUR A — Ubah jatah (permanen, DUA ARAH naik/turun)**: props `ubahJatah` + `jatahBaru`.
  Ubah `default_qty_mika` kios seterusnya. Centang → titipan hari ini auto-ikut jatah baru
  (`dropBaru = jatahBaru`; operator boleh override jumlah titip mis. stok kurang, TAPI default
  tetap jadi jatah baru). Berlaku SAAT ITU JUGA & seterusnya. Menandai `changed_default=true`.
- **JALUR B — Tambah cash sekali (jatah TETAP)**: props `pakaiCashExtra` + `cashExtra`. Mika
  ekstra dibayar tunai seketika; `default_qty_mika` TIDAK berubah. Ganti mekanisme AUTO-SPLIT
  lama (`extraDropMode`: drop>default → auto cash) yang DIBUANG karena bikin A & B ketuker.
- **BLOKIR**: titip konsinyasi > jatah TANPA centang "Ubah jatah" → tidak tersimpan + pesan
  actionable (ubah jatah / tambah cash / betulkan ketikan). Guard cuma nyala saat
  `default_qty_mika > 0` (kios baru first-titip tetap lolos).
- **Fix input number default-0**: `onfocus="this.select()"` di SEMUA field number (ketik 5 jadi 5,
  bukan "05") + label "Ubah jatah permanen" + helper "tagihan hari ini tetap dari titipan LAMA".

GUARD DIJAGA (mesin settlement TIDAK disentuh): tagihan hari ini SELALU dari titipan LAMA
(`pendingDelivery->qty_delivered`) — jatah baru/cash tak mempengaruhinya. `changed_default` hanya
saat "Ubah jatah" dicentang eksplisit; kunjungan normal tak mengubahnya. Test baru
`UbahJatahCashTest` (naik/turun/cash/override/normal/blokir/mutual-exclusive) + update
CashDeliveryTest/CorrectVisitTest/CorrectVisitUiTest/ActiveTripAdvancedScenarioTest ke prop baru.
⚠️ JEBAKAN BLADE: `@if`/`@endif` yang nempel huruf (`mika@if` / `mika@endif`) TAK dikenali directive
→ error kompilasi yang `view:cache` LOLOS tapi runtime Livewire tolak; wajib spasi sebelum directive.
🔎 TODO USER: verifikasi di PRODUKSI (form serah-terima operator) — turun/naik jatah + cash +
titip>jatah tanpa centang (harus keblokir + pesan) + input angka (ketik 5 jadi 5).

## STATUS
- 303 PASS (1157 assertions) — +7 dari 296 (KomisiDropBasisTest 7 skenario). Komisi Rian ganti ke
  basis DROP (Opsi Y) SELESAI & PUSHED (11 Juli 2026). ADA migration (default tarif 500→1.000).
  Omset/untung_kotor TIDAK berubah (komisi metrik terpisah).
- 296 PASS (1134 assertions) — +6 dari 290 (test baru UbahJatahCashTest, sebagian menggantikan
  jalur lama). Rework Serah Terima ubah-jatah/cash/blokir (11 Juli 2026) SELESAI & PUSHED (HEAD
  67220ad), tanpa migration.
- 290 PASS (1101 assertions) — naik dari 249 (20 test baru dari audit isolasi + fix ProductVariant,
  1 test dari migrasi peta owner ke Leaflet, 2 test dari audit session/trip persistence, 7 test
  dari fix foto kios/proxy same-origin, 9 test baru dari fix UX anti-duplikat sort_order). SW v5 &
  proxy foto tak nambah test PHP tambahan di luar itu (bagian JS/HTTP diverifikasi headed browser
  produksi — lihat section masing-masing di bawah). Fitur sort_order Track 1 (9 Juli 2026) sendiri
  tak nambah test PHP baru — murni perubahan query/kolom, diverifikasi headed browser (owner+
  operator, data uji dibuat lalu dihapus lagi). Fix UX anti-duplikat (9 Juli 2026, sesi
  berikutnya) 9 test baru + headed browser, lihat section masing-masing.
- INFRA: Railway produksi kini plan **Hobby** (di-upgrade dari free trial) — resource lebih lega,
  app tetap ringan (lihat audit di bawah).
- ✅ AUDIT PENUTUPAN SESI (10 Juli 2026, read-only, 0 perubahan kode): performa DIKONFIRMASI tetap
  ringan dengan angka, git bersih & tersinkron, tidak ada utang tersembunyi baru. Detail:
  * Suite: 290 PASS (1101 assertions), 46.88s, 0 skip, test terlambat cuma 0.67s (tak ada anomali).
  * List kios owner: fitur sesi ini TAK menambah query per baris — `photo_url` cuma `route()`
    (0 query), `sort_order`/`defaultSort` grouping = subquery correlated DALAM 1 query utama (bukan
    N+1), relasi `cluster` auto-eager-load Filament (1 query, konstan). Byte foto dimuat browser
    lewat request /kiosks/{id}/photo TERPISAH (async, paralel) — bukan bagian render list.
  * Proxy foto: `$disk->response()` = STREAM (readStream/fpassthru, bukan load full memori);
    `Cache-Control: public, max-age=31536000, immutable` + URL ber-`?v=` → reload ke-2 disajikan
    100% dari cache browser (0ms, transferSize 0, tanpa hit server sama sekali). Load pertama
    558–1270ms/foto (round-trip Railway↔R2, wajar). Catatan teknis: Laravel `response()` tak
    auto-negosiasi `If-None-Match` (304), tapi TAK relevan — `immutable` sudah cegah request
    ulang sepenuhnya, jadi tak pernah ada re-stream.
  * `reorderWithinCluster` (edit angka `sort_order`): per-edit = 1 SELECT COUNT + 1 UPDATE range +
    1 UPDATE self, semua di-scope `cluster_id` (pakai index `(cluster_id, sort_order)`), BUKAN
    full-table-scan. Cluster besar (956 kios Aidil): 1 UPDATE range menyentuh maksimal ~(selisih
    posisi) baris dalam SATU statement ber-index — pindah jauh worst-case ~955 baris/1 query,
    pindah tipikal beberapa baris. Bukan N+1.
  * Asset: SW 9.8K, build total 153K (app.css 108K + app.js 42K), Leaflet vendored lokal
    (public/vendor/leaflet) — tak ada bloat baru signifikan.
  * Git: working tree clean, HEAD = origin/main (957b8b2), 0 commit belum ter-push, 0 file
    sensitif ke-track (.env/secret/scratchpad ter-ignore; `.claude/skills/verify-browser/driver.cjs`
    memang sengaja di-track = skill bersama, bukan secret).

## ✅ FITUR URUTAN KEDAI PER-AREA (sort_order) — TRACK 1 SELESAI (9 Juli 2026)
- LATAR: list kios owner (`KioskResource`) & daftar kunjungan operator (`ActiveTrip`) dulu urut
  ABJAD. Operator lapangan minta urutan sesuai RUTE PENGANTARAN (pengalaman lapangan), bukan
  abjad, biar tidak muter-muter antar-daerah. Rencana user lebih besar dari sekadar urutan: (a)
  urutan manual sekarang, (b) NANTI dipisah per-area, (c) JANGKA PANJANG auto-routing dari
  koordinat GPS. Investigasi awal (sebelum eksekusi) mengecek 2 pendekatan Filament
  (`->reorderable()` native vs kolom polos) DAN skenario operasional lanjutan user (operator
  ngantar lintas-area, prioritas kios laris, auto-lanjut trip saat stok masih ada) SEBELUM
  membangun apa pun — lihat section "TRACK 2" di bawah untuk hasil investigasi itu.
- KEPUTUSAN DESAIN (dikoreksi user dari draft awal): `sort_order` **PER-CLUSTER (per-area)**,
  BUKAN global. Alasan: operator ngantar rutin per-area dulu (seser habis satu area, baru pindah
  lintas-area) — urutan DALAM area adalah fondasi yang tepat, dan jadi acuan buat Track 2 nanti
  ("kios terakhir diantar di area X" → titik acuan rekomendasi area terdekat berikutnya).
- IMPLEMENTASI:
  1. **Migration** (`2026_07_09_000001_add_sort_order_to_kiosks_table.php`) — kolom `sort_order`
     (integer nullable) + index composite `(cluster_id, sort_order)`. Backfill data existing
     PER-CLUSTER: tiap cluster mulai dari 1, urut abjad saat ini sebagai titik awal (bukan urut
     per-owner/global). Dijalankan atas SEMUA cluster lintas 2 tenant (Ismi owner_id=2, Aidil
     owner_id=5) sekaligus — grouping by `cluster_id` otomatis tidak pernah mencampur kios
     lintas-tenant (satu cluster = satu owner, dijamin FK). Diverifikasi: 0 NULL sort_order
     pasca-backfill, tiap cluster restart dari 1 (dicek `Tempat Titipan` 956 kios → max_sort=956
     persis = count, tak ada gap/duplikat).
  2. **`KioskResource.php`** (owner) — `defaultSort` diganti jadi CLOSURE 3-level: urut nama
     cluster dulu (subquery correlated `Cluster::query()->select('name')->whereColumn(...)`,
     BUKAN `->join()` — sengaja, biar kolom `kiosks.*` tak tertimpa kolom `clusters.*` yang
     senama seperti `id`/`name`/`is_active`), lalu `sort_order IS NULL` (nulls-last DALAM
     area-nya sendiri, bukan turun ke bawah global), lalu `sort_order` asc, lalu `name` sebagai
     tie-break akhir. Tambah `Tables\Columns\TextInputColumn::make('sort_order')` (edit angka
     langsung di list, tak perlu buka form) + `->reorderable('sort_order')` (drag native
     Filament) + field form `sort_order` di Section "Informasi Dasar" dengan helper text yang
     eksplisit bilang "dalam Area" dan "jangan lintas area" saat drag.
  3. **`ActiveTrip.php`** (operator, `kioskViewData()`) — `orderBy('name')` diganti clause yang
     SAMA PERSIS (cluster name subquery → `sort_order IS NULL` → `sort_order` → `name`), CASE WHEN
     "kios sudah dikunjungi turun ke bawah" yang sudah ada TETAP dipertahankan sebagai prioritas
     paling atas (di depan clause baru). Mode `sortedByDistance` (tombol "Urutkan Jarak", nearest-
     neighbor GPS manual) SENGAJA tidak disentuh — itu override eksplisit operator, independen
     dari grouping area.
  4. Import `App\Models\Cluster` ditambah di kedua file (dipakai subquery correlated).
- ⚠️ TEMUAN PENTING SAAT VERIFIKASI (bukan bug kode kita, tapi batasan desain Filament core yang
  WAJIB dipahami sebelum pakai fitur drag): `Filament\Tables\Concerns\CanSortRecords::
  applySortingToTableQuery()` (baca: `vendor/filament/tables/src/Concerns/CanSortRecords.php:77-78`)
  eksplisit begini —
  ```php
  if ($this->isTableReordering()) {
      return $query->orderBy($this->getTable()->getReorderColumn());
  }
  ```
  Artinya: begitu mode reorder (drag) DIAKTIFKAN, Filament SELALU pakai `ORDER BY sort_order`
  POLOS (nulls FIRST versi MySQL, bukan last), MENGABAIKAN TOTAL `defaultSort` closure custom kita
  (grouping per-area hilang sementara) — dan ini juga berlaku untuk fitur native `->groups()`
  Filament (`CanGroupRecords.php:16` juga return `null` saat reordering, jadi bukan cuma masalah
  pendekatan kita, fitur grouping BAWAAN Filament pun sama-sama dimatikan saat reorder aktif).
  * DIBUKTIKAN via reproduksi nyata (Playwright, bukan baca kode doang): drag TANPA filter Area
    aktif pada list 2-cluster (3 kios Area A + 2 kios Area B) → SEMUA 5 baris yang tampil
    dinomori ulang sekuensial 1..5 LINTAS AREA (termasuk 1 kios Area B yang sama sekali tak
    disentuh, `sort_order`-nya ikut berubah dari NULL ke angka nyata cuma karena kebetulan
    tampil di render itu).
  * MITIGASI YANG SUDAH ADA (tak perlu kode baru): filter `SelectFilter::make('cluster_id')`
    ("Area") yang SUDAH ADA di `KioskResource` tetap berlaku SAAT reorder mode aktif (filter itu
    query-level, independen dari sorting) — jadi kalau owner filter ke SATU Area dulu sebelum
    toggle drag, drag jadi aman 100% (dibuktikan reproduksi ulang: filter ke 1 cluster → drag →
    HANYA kios cluster itu yang dinomori ulang, cluster lain 0 tersentuh). Ini SATU-SATUNYA cara
    aman pakai drag sekarang — didokumentasikan di helper text form + WAJIB diketahui owner
    sebelum pakai fitur drag di skala besar (217+ kios).
  * Edit angka manual (`TextInputColumn`) TIDAK kena batasan ini sama sekali — selalu aman dipakai
    kapan pun, filter atau tidak (cuma nulis 1 kolom 1 baris, tak melibatkan `isTableReordering()`).
- VERIFIKASI (headed Chrome via Playwright, `verify-browser`-style script kustom — data uji
  `ZZ_TEST_*` + 1 cluster temp dibuat lalu DIHAPUS lagi setelah tes, tak sentuh 959 kios/2 cluster
  asli):
  * Owner list (tak filter): kios terkelompok per-area (Area "Marelan Mabar" duluan, lalu Area
    "ZZ_TEST_AREA_B"), DALAM tiap area urut `sort_order` dgn null last — persis sesuai desain.
  * Edit angka inline: ubah `sort_order` via `TextInputColumn`, refresh → kios pindah posisi
    sesuai angka baru, DALAM area-nya sendiri (tak lompat ke area lain).
  * Drag TANPA filter → cross-area leak (lihat temuan di atas). Drag DENGAN filter ke 1 Area →
    aman, HANYA kios area itu yang berubah, area lain 0 tersentuh — dikonfirmasi query DB
    langsung sebelum/sesudah.
  * **Operator (`ActiveTrip`) — urutan IDENTIK PERSIS dengan owner**: kios yang sama, grouping
    area yang sama, urutan dalam area yang sama — dicek side-by-side (list owner vs kartu kunjungan
    operator di trip aktif yang sama), 100% match. Ini tujuan utama fitur (operator tak
    muter-muter) — TERCAPAI end-to-end, bukan cuma di admin panel.
  * Tenant isolation: `OwnerScope` (global scope Kiosk lewat `cluster.owner_id`, DAN `Cluster`
    lewat `BelongsToOwner`) TIDAK disentuh sama sekali oleh perubahan ini — subquery correlated
    `Cluster::query()` ikut kena scope otomatis (owner/operator masing-masing cuma lihat cluster
    miliknya), test existing `owner panel kiosk list is scoped to owner` tetap PASS.
  * `php artisan test`: 281 PASS (1067 assertions), 0 regresi — baseline SAMA sebelum & sesudah
    perubahan (fitur ini murni query/kolom + UI Filament bawaan, tak butuh test PHP baru; utamanya
    diverifikasi lewat browser nyata di atas).
- Commit: lihat `git log` — migration + `KioskResource.php` + `ActiveTrip.php` + `Kiosk.php`
  ($fillable `sort_order`).

## ✅ FIX UX URUTAN KEDAI: ANTI-DUPLIKAT (AUTO-REFLOW) + DRAG TERKUNCI-FILTER (9 Juli 2026)
- LAPORAN USER: `TextInputColumn` angka `sort_order` (Track 1) MEMBOLEHKAN duplikat — isi "3" di
  dua kios berbeda dalam area yang sama → dua-duanya jadi 3, urutan ambigu. Nyisip di tengah juga
  ribet (harus geser manual semua angka setelahnya). AKAR: kolom cuma `->rules(['nullable',
  'integer'])`, tak ada validasi/logic keunikan sama sekali.
- INVESTIGASI (sebelum bangun) mengevaluasi 3 opsi: (A) drag-only buang kolom angka, (B) angka
  anti-duplikat dgn auto-reflow, (C) angka read-only + drag-only. TEMUAN KUNCI yang menentukan
  pilihan: dicek distribusi cluster nyata — **956 kios Aidil SEMUA ada di SATU cluster**
  ("Tempat Titipan"), rencana pisah-area belum terjadi. Ini bikin opsi A/C (murni drag) GAGAL
  untuk kondisi nyata sekarang — filter "1 area" tak mengurangi apa pun buat tenant ini, drag 956
  baris tetap seberat sebelum ada sort_order. Direkomendasikan & DIPILIH user: **opsi B-full**
  (angka anti-duplikat + auto-reflow), drag TETAP dipertahankan (bukan dibuang) sebagai opsi kedua
  yang makin berguna begitu area benar-benar dipecah nanti.
- IMPLEMENTASI:
  1. **`Kiosk::reorderWithinCluster()`** (`app/Models/Kiosk.php`, method static baru) — algoritma
     "insert-within-list" (pola Notion/Trello), BUKAN naif "increment semua >= target" (itu bikin
     lubang kalau kios dipindah ke posisi LEBIH AWAL, slot lamanya ditinggal kosong). Logic:
     - Target di-clamp ke rentang valid `[1, totalOthers+1]` — **PAKAI COUNT kios lain yang sudah
       punya posisi, BUKAN MAX(sort_order)-nya**. Percobaan pertama pakai MAX ketangkep BUG
       off-by-one lewat test sendiri (`test_target_beyond_range_is_clamped_to_end` gagal duluan,
       expected 3 dapat 4) — MAX cuma benar kalau kios yang dipindah kebetulan sedang memegang
       nilai tertinggi; kalau bukan, MAX-nya-orang-lain tetap = nilai tertinggi keseluruhan →
       lebih 1 dari batas asli. Diganti COUNT, robust terhadap kasus apa pun.
     - `null` (belum punya posisi) → sisip baru: kios lain di posisi >= target naik 1.
     - Pindah ke belakang (target > posisi lama) → HANYA kios di ANTARA posisi lama & baru
       mundur/turun 1 (bukan semua >= target — itu akan menggeser kios yang di LUAR rentang juga).
     - Pindah ke depan (target < posisi lama) → HANYA kios di ANTARA posisi baru & lama naik 1.
     - `null` sebagai target → lepas dari urutan, TANPA mengompres sisanya (kios lain tak digeser).
     - Semua dalam `DB::transaction()`, di-scope `cluster_id` (cluster/tenant lain 0 tersentuh —
       OwnerScope Kiosk otomatis berlaku juga di query internal method ini, redundan tapi aman).
  2. **`KioskResource.php`** — `TextInputColumn::make('sort_order')` sekarang pakai
     `->updateStateUsing(fn ($record, $state) => Kiosk::reorderWithinCluster($record, ...))`,
     tambah rule `min:1`. Helper text form diupdate: "Isi angka yang sudah dipakai kios lain?
     Otomatis digeser, tak akan kembar."
  3. **Drag terkunci-filter**: `->reorderable('sort_order', fn ($livewire) => count($livewire->
     tableFilters['cluster_id']['values'] ?? []) === 1)`. Dikonfirmasi baca kode
     `vendor/filament/tables/resources/views/index.blade.php:188` (`@if ($isReorderable)`) —
     tombol drag **SEMBUNYI TOTAL dari DOM** (bukan cuma nonaktif/abu-abu) kecuali TEPAT 1 Area
     difilter. Menutup total jebakan "drag-lintas-area" yang ditemukan pas verifikasi Track 1 —
     sekarang mustahil salah jalan secara fisik, tak lagi cuma diperingatkan lewat helper text.
- VERIFIKASI:
  * **Test baru** (`tests/Feature/Owner/KioskSortOrderReflowTest.php`, 9 test): pindah ke bawah
    (geser range lama↔baru saja, kios di luar rentang tak berubah, hasil akhir 1..5 gapless);
    pindah ke atas (arah sebaliknya, sama-sama gapless); sisip dari null (dorong yang lain turun);
    lepas ke null (tak mengompres sisanya); clamp melebihi rentang (ke akhir); clamp di bawah 1
    (ke awal); pindah ke posisi sama (no-op); **reflow cluster A tidak menyentuh cluster B**;
    **reflow tenant A tidak menyentuh tenant B**. 290 PASS total (281 + 9 baru), 0 regresi.
  * **Headed Chrome, lewat TextInputColumn ASLI (bukan panggil method langsung)** — data uji
    `ZZ_R_*` (5 kios, posisi 1-5) dibuat lalu DIHAPUS lagi setelah tes: ketik "3" di kios posisi 1
    (nabrak kios yang sudah 3) → hasil B=1,C=2,A=3,D=4,E=5 (gapless, no duplikat, PERSIS sesuai
    algoritma). Lanjut ketik "2" di kios posisi 5 (nabrak yang sekarang 2) → hasil B=1,E=2,C=3,
    A=4,D=5 (gapless lagi, arah SEBALIKNYA sama-sama benar). Karakteristik yang sama dgn Track 1
    berlaku lagi: DB ter-update instan, tapi urutan visual BARIS di layar butuh reload utk
    ke-resort (Filament tak re-fetch urutan tabel dari 1 edit sel saja) — bukan regresi, sudah
    dikonfirmasi cara kerja yang sama sejak Track 1.
  * **Drag-lock, headed Chrome**: 0 filter → tombol reorder TAK ADA di DOM. 1 area difilter →
    tombol MUNCUL, toggle → HANYA 5 kios area itu yang tampil di mode reorder (tak ada kebocoran
    lintas-area). 2 area difilter → tombol HILANG lagi. Kembali ke 1 area → tombol muncul lagi.
    Semua 4 state dikonfirmasi lewat pembacaan DOM langsung, bukan asumsi.
  * **Operator (`ActiveTrip`) — urutan hasil reflow IDENTIK PERSIS dgn owner**: kios `ZZ_R_*` yang
    sama, urutan B,E,C,A,D (hasil dari dua reflow di atas) tampil SAMA di kartu kunjungan operator
    trip aktif — dicek side-by-side, 100% match. `ActiveTrip.php` 0 disentuh sesi ini (dikonfirmasi
    `git diff` kosong) — jalur baca sort_order tetap yang dari Track 1, cuma NILAI-nya sekarang
    dijamin bersih dari duplikat oleh sisi tulis (owner).
  * Tenant isolation & scoping: tak ada perubahan pada `OwnerScope`/`getEloquentQuery()`; method
    baru `reorderWithinCluster()` cuma menambah cara TULIS yang lebih aman, dibuktikan test
    eksplisit lintas-cluster & lintas-tenant di atas.
- CATATAN TERPISAH (dikonfirmasi, TIDAK dibangun sesi ini — permintaan user): fakta 956 kios Aidil
  di 1 cluster berarti manfaat PENUH fitur drag baru terasa setelah kios benar-benar dipecah per
  area. Desain `sort_order` **PER-CLUSTER** (bukan global, keputusan Track 1) sudah SIAP untuk itu
  tanpa perlu ubah kode urutan apa pun lagi — begitu kios dipindah ke cluster baru (tinggal ganti
  `cluster_id`), `reorderWithinCluster()` DAN `defaultSort` grouping SUDAH otomatis menghitung
  ulang posisi dalam cluster barunya sendiri (posisi di cluster lama tak ikut terbawa/bocor,
  dibuktikan test isolasi cluster di atas) — tak ada migrasi/refactor tambahan yang dibutuhkan.
- Commit: lihat `git log` — `app/Models/Kiosk.php` (method baru) + `KioskResource.php`
  (updateStateUsing + reorderable condition + helper text) + test baru.

## 📋 TRACK 2 — SMART ROUTING (BACKLOG, BELUM DIBANGUN)
- KONTEKS: user gambarkan skenario operasional lebih kompleks dari sekadar urutan statis: (1)
  operator kadang ngantar LINTAS-area (bukan selalu 1 area), (2) kios LARIS (cepat habis)
  diprioritaskan diantar duluan, bisa lintas-area, (3) trip LANJUT otomatis ke area terdekat
  berikutnya saat stok masih ada (bukan berhenti per-area). Investigasi (BUKAN eksekusi) dilakukan
  SEBELUM Track 1 dibangun, khusus mengecek apakah desain `sort_order` per-cluster masih pas untuk
  skenario ini — KESIMPULAN: iya, aman (lihat bagian "Dampak ke sort_order" di bawah), Track 2
  adalah LAPISAN TERPISAH di atasnya, bukan revisi Track 1.
- STATUS FONDASI DATA (dicek langsung baca kode, bukan asumsi):
  | Kebutuhan Track 2 | Status | Detail |
  |---|---|---|
  | Trip lintas-area | ⚠️ Parsial | `trips.starting_cluster_id` di-set SEKALI saat `StartTrip`, TAK PERNAH diubah lagi selama trip jalan (`ActiveTrip.php` cuma baca). Satu-satunya jalan lintas-area sekarang: pilih "Trip Bebas" di awal (starting_cluster_id null) — bukan transisi otomatis di tengah jalan. |
  | Stok di tangan operator | ⚠️ Data ada, tak live | `qty_carried_total` diisi sekali di awal. "Sisa stok" (`total_mika_sisa`) CUMA dihitung di `openEndTripModal()` — muncul HANYA saat mau mengakhiri trip, bukan indikator live yang selalu kelihatan. |
  | Prioritas kios "laris" | ⚠️ Sinyal ada, belum dipakai buat urutan | `fast_mover_threshold_days` + `avg_days` dari histori Settlement SUDAH ada & dipakai jadi badge visual (`computeKioskFlags()`) — tapi murni informasi, tak memengaruhi urutan tampil kios sama sekali. |
  | Data histori delivery (buat metrik laris) | ✅ Cukup detail | `deliveries` (qty_delivered, created_at, kiosk_id) + `settlements` (qty_sold, visit_date) + `kiosk_visits` (visited_at) — timestamp & qty per kios granular, cukup buat hitung velocity/rata-rata hari habis TANPA kolom baru. Ini yang sudah dipakai `computeKioskFlags()`. |
  | GPS per kios | ✅ Ada, presisi cukup | `kiosks.latitude/longitude` decimal(10,7), sudah dipakai haversine di `sortByDistance()` (operator, tombol "Urutkan Jarak" — one-shot manual, ambil GPS sekali per klik, BUKAN live-tracking). |
  | GPS per AREA (centroid cluster) | ❌ GAP NYATA | `clusters` table TIDAK punya kolom lat/lng — "area terdekat" (bukan "kios terdekat") belum bisa dihitung tanpa kerja tambahan (perlu computed centroid dari rata-rata kios dalam cluster, kolom baru atau query on-the-fly). |
  | "Kios terakhir diantar dalam area" | ✅ Bisa diturunkan, tak perlu kolom baru | `kiosk_visits` (trip_id, kiosk_id, visited_at) join kios→cluster sudah cukup untuk query "kios terakhir yang dikunjungi dalam area X pada trip ini" — tak ada gap skema. |
  | Auto-lanjut trip lintas-area saat stok ada | ❌ Belum ada sama sekali | `stock_habis` cuma salah satu `ended_reason` (alasan MENGAKHIRI trip) — begitu dipilih, trip SELESAI TOTAL, operator harus `StartTrip` baru. Tak ada mekanisme "area 1 selesai diseser tapi stok masih ada → lanjut ke area 2 otomatis dalam trip yang sama." |
- DAMPAK KE `sort_order` (Track 1): AMAN, tak perlu redesign. `sort_order` per-cluster yang sudah
  dibangun adalah baseline urutan MANUAL dalam satu area — cocok jadi lapisan dasar untuk Track 2
  nanti (mis. algoritma auto-routing bisa nulis ulang nilai `sort_order` per-area sebagai
  output-nya), tapi TIDAK otomatis menyelesaikan prioritas-laris atau auto-lanjut-area — itu
  murni lapisan tambahan di atas kolom yang sudah ada, bukan perubahan skema.
- ESTIMASI KOMPLEKSITAS (kasar, saat Track 2 mulai dikerjakan nanti):
  * Prioritas laris → pengaruhi urutan: SEDANG. Sinyal (`fast_mover`) sudah ada, perlu masuk ke
    query sort + keputusan desain (override `sort_order` manual, tie-break, atau skor gabungan?).
  * Area terdekat (bukan kios terdekat): SEDANG–BESAR. Perlu hitung centroid cluster (kolom
    computed/on-the-fly dari rata-rata lat/lng kios anggotanya) + logic pilih cluster belum-diseser
    terdekat dari posisi sekarang.
  * Auto-lanjut lintas-area saat stok ada: BESAR. `starting_cluster_id` perlu jadi state yang BISA
    berubah di tengah trip (bukan kunci sekali di awal) + trigger UI baru ("area ini selesai, lanjut
    ke area X?") saat semua kios area aktif sudah diseser TAPI `total_mika_sisa > 0`. Menyentuh
    `ActiveTrip.php`, mungkin skema `trips`, dan alur UX yang belum ada precedent di kode sama sekali.
- TIDAK DIBANGUN sesi ini (sengaja) — backlog murni, tunggu keputusan user kapan mau mulai.

## ✅ FIX BUG UI: ARROW-KEY BOCOR JADI TEKS DI DROPDOWN SEARCHABLE (CHOICES.JS) (8 Juli 2026)
- GEJALA: di form Create/Edit Kios, dropdown Area (Select `->searchable()`) — isi Nama Kios →
  Tab ke Area → ArrowDown (navigasi normal) → Esc (tutup dropdown) → ArrowDown lagi → teks
  "arrowdown" nyasar ke search box, menumpuk tiap Esc+ArrowDown berikutnya
  ("arrowdownarrowdownarrowdown..."). Kosmetik, tak ada data ke-submit salah (opsi tetap dipilih
  dari list, teks nyasar cuma di search box), tapi ganggu alur input cepat keyboard.
- AKAR (dikonfirmasi baca langsung source Choices.js terbundel di
  `public/js/filament/forms/components/select.js`, BUKAN dugaan): dua bug upstream Choices.js
  yang beririsan, bukan bug di kode kita:
  1. `_onEscapeKey()` Choices manggil `this.containerOuter.focus()` (bukan balik fokus ke search
     input) pas nutup dropdown → `this.input.isFocussed` jadi `false` walau secara visual masih
     di komponen yang sama.
  2. `_onKeyDown()` Choices deteksi "apakah tombol yang ditekan itu karakter yang bisa diketik"
     pakai `String.fromCharCode(e.keyCode)` — utk ArrowDown (`keyCode` 40) itu KEBETULAN sama
     dengan charCode `'('`, jadi dianggap "printable". Kombinasi #1 (`isFocussed=false`) + #2
     (`K=true`) bikin baris `this.input.isFocussed || (this.input.value += e.key.toLowerCase())`
     nyisipin teks "arrowdown"/"arrowup" MENTAH ke search input tiap kali ArrowDown/ArrowUp
     ditekan pas dropdown baru saja ditutup oleh Esc.
  * Percobaan awal (fokus-balik-ke-input SEBELUM Choices baca kondisinya) GAGAL — search input
    Choices utk select-one ada DI DALAM panel dropdown yang `display:none` selagi tertutup,
    jadi `.focus()` ke situ adalah no-op selama dropdown belum kebuka. Ketauan lewat browser
    verification manual (Playwright + Chrome asli), bukan asumsi.
- FIX (`app/Providers/AppServiceProvider.php`, method `fixChoicesSelectArrowKeyFocusLeak()`,
  dipanggil di `boot()`, TIDAK sentuh vendor sama sekali):
  - Listener `keydown` capture-phase di `document` (jalan lebih dulu drpd listener capture-phase
    Choices di `containerOuter`, krn `document` ancestor-nya). Khusus ArrowDown/ArrowUp & dropdown
    lagi tertutup: rekam isi search input SEBELUM Choices proses event, lalu `setTimeout(0)` cek
    apakah isinya sekarang persis "isi-lama + nama tombol" (tanda tangan bug ini persis) — kalau
    ya, balikin ke isi semula. Navigasi/reopen dropdown bawaan Choices SAMA SEKALI tak disentuh
    (tak ada `preventDefault`/`stopPropagation`), jadi tak mengganggu perilaku normal.
  - Global (semua Select `->searchable()` di semua panel Filament kena fix ini, bukan cuma Area
    di Kiosk) — konsisten sama pola render-hook lain di file yg sama (`fixFilamentMapPickerZIndex`),
    dan memang sesuai krn bug-nya inheren di Choices.js, bukan spesifik satu field.
- VERIFIKASI (browser asli via skill `verify-browser`, Playwright + Chrome sistem, headless):
  reproduksi urutan persis (Nama→Tab→ArrowDown→Esc→ArrowDown→Esc→ArrowDown) di Area (Kiosk
  create) → search box TETAP KOSONG di semua langkah, 0 teks nyasar, 0 penumpukan. Fungsi
  dropdown penuh dikonfirmasi tetap jalan: klik-pilih (mouse), search-by-typing (filter), &
  keyboard navigate+Enter — semua sukses set value dgn benar. Dropdown LAIN yg pakai Choices.js
  juga dicek (`owner_id` di UserResource, beda resource & panel sama sekali) — bug yg sama
  terkonfirmasi HILANG juga di situ setelah fix (fix-nya global, bukan ditempel per-field).
  `php artisan test` tetap 281 PASS (tak ada test PHP baru — ini murni
  fix JS/DOM, diverifikasi lewat browser nyata, bukan unit test).
- Field `role` di UserResource TIDAK kena bug ini sama sekali (bukan `->searchable()`, jadi
  Filament render native `<select>` browser biasa, bukan Choices.js) — konfirmasi bug ini
  murni scoped ke Select yang `->searchable()`.

### ⚠️→✅ LANJUTAN (8 Juli 2026, commit setelah 6696a87): fix di atas BOCOR ke ArrowRight
- GEJALA LANJUTAN: user tes ArrowRight setelah Esc → teks "arrowright" tetap nyasar & menumpuk,
  padahal ArrowDown/ArrowUp sudah ketutup. Fix awal (6696a87) cuma allowlist 2 tombol
  (`e.key !== 'ArrowDown' && e.key !== 'ArrowUp'`) — WHACK-A-MOLE, salah pendekatan.
- AKAR YG LEBIH LENGKAP: bug `String.fromCharCode(keyCode)` Choices.js itu collide utk SEMUA
  named key yang keyCode-nya kebetulan jatuh di rentang charCode printable (>31), bukan cuma
  ArrowDown(40)/ArrowUp(38) — juga ArrowLeft(37), ArrowRight(39), Home(36), End(35), PageUp(33),
  PageDown(34), Delete(46), Insert(45), F-keys, dst. ArrowLeft/Right/Home/End malah TIDAK punya
  `case` handler sendiri di switch Choices (cuma Up/Down/PageUp/PageDown yg punya), tapi bug
  leak-nya terjadi SEBELUM switch dispatch (di leading comma-expression), jadi tetap bocor teks
  walau key-nya sendiri tak melakukan navigasi apapun.
- FIX DIPERBAIKI JADI GENERIK (tutup KELAS, bukan instance): ganti allowlist 2-tombol dengan
  kriteria `e.key.length <= 1` → skip. Named key (ArrowDown, Home, Delete, dst) SELALU py
  `e.key.length > 1`; karakter tunggal yg MEMANG sah masuk sbg teks pencarian (huruf/angka/spasi)
  SELALU `e.key.length === 1`. Kriteria ini otomatis menutup SEMUA named key sekaligus (termasuk
  yg belum pernah dites eksplisit spt Home/End/PageUp/PageDown/Delete/Insert) TANPA daftar
  per-tombol yg gampang bolong lagi kalau ada named key lain yg kelewat.
- VERIFIKASI (browser asli, Playwright): urutan Esc→key diulang utk SEMUA 8 tombol —
  ArrowDown, ArrowUp, ArrowRight, ArrowLeft, Home, End, PageUp, PageDown — 0 dari 8 yang bocor
  teks (sebelumnya cuma 2/8 yg ketutup). Dicek juga di dropdown Choices.js LAIN (`owner_id` di
  UserResource) dgn ArrowRight — sama-sama bersih. Regresi dicek & AMAN: search-by-typing
  (isolated fresh-context test, krn di test gabungan sempat ada artifact timing yg tak terkait
  fix — dikonfirmasi via kriteria `e.key.length <= 1` yg secara struktural TAK PERNAH menyentuh
  ketikan huruf tunggal), mouse-click-select, navigasi panah SAAT dropdown terbuka (highlight
  tetap jalan, tak diblok — fix tak pernah `preventDefault`/`stopPropagation`). `php artisan test`
  tetap 281 PASS.

## ✅ FIX FOTO KIOS "KADANG MUNCUL KADANG TIDAK" DI LIST OWNER (7 Juli 2026, commit def0d90)
- GEJALA: foto kios kadang kosong di list `/owner-panel/kiosks` (Filament), reload ulang kadang
  muncul lagi. Owner sempat lihat "asal muncul di operator" — jadi kecurigaan awal: dua jalur
  beda antara owner & operator (bukan soal data foto hilang).
- AKAR (audit dulu, dibuktikan bukan diasumsikan): `Tables\Columns\ImageColumn` (dipakai
  `KioskResource.php` list owner) punya `checkFileExistence` default **TRUE**
  (`vendor/filament/tables/src/Columns/ImageColumn.php:55,156-164`) — tiap baris berfoto, tiap
  render, menembak **live HeadObject API call ke R2** (`Storage::disk('s3')->exists($path)`,
  server Railway → R2) SEBELUM menampilkan gambar. Gagal transient (jaringan/rate-limit sesaat)
  → exception ketangkep → baris itu kosong utk render tsb SAJA, reload berikutnya bisa sukses
  lagi. **Operator TIDAK kena** karena `Kiosk::photo_url` (accessor, dipakai `active-trip.blade.php`)
  murni `Storage::disk($disk)->url($path)` — 0 API call, cuma generate string URL.
  * BUKAN URL expired/signed (`->visibility('public')` eksplisit di FileUpload field →
    cabang `temporaryUrl()` di ImageColumn TIDAK PERNAH aktif — dikonfirmasi kode+reproduksi:
    URL foto R2 permanen, `<img>` sukses 200 berulang kali).
  * BUKAN Service Worker — R2 (`r2.dev`) beda origin dari app, `sw.js:93-95` bypass total
    request cross-origin, tak pernah intersep/cache foto.
- FIX (`app/Filament/Resources/KioskResource.php`, ImageColumn `photo_path`):
  1. `->checkFileExistence(false)` — hilangkan live-check, list owner ikut jalur SAMA dengan
     operator (tampilkan URL langsung, browser yang urus fetch + fallback broken-image kalau
     file BENERAN hilang dari bucket — bukan disembunyikan diam-diam).
  2. `->defaultImageUrl(self::PHOTO_PLACEHOLDER)` — SVG inline (bukan file baru) utk kios yang
     memang belum punya foto (`photo_path` NULL, data import lama) → placeholder ikon rapi,
     dibedakan jelas dari kasus "foto ada tapi gagal tampil" di atas.
- TEST BARU (`KioskPhotoListStabilityTest`, 2 test): membuktikan PERILAKU langsung (bukan cuma
  baca kode) — render list kios TETAP menampilkan URL foto walau file SENGAJA tidak dibuat di
  disk fake (kalau checkFileExistence masih aktif, ini akan disembunyikan/null); kios tanpa foto
  menampilkan placeholder SVG, bukan kotak kosong. 274 PASS total, 0 regresi.
- VERIFIKASI PRODUKSI (headed Chrome asli, setelah deploy via push): 10× reload berturut ke
  `/owner-panel/kiosks` — foto "HM Said 1" & "Kedai Cempaka" render KONSISTEN 1280px tiap kali
  (sebelumnya intermiten). Operator TIDAK disentuh (`Kiosk.php`/`active-trip.blade.php` 0 diff
  di commit ini — dikonfirmasi via `git diff --name-only`).
- ⚠️ TEMUAN BUAT RENCANA ROTASI R2 TOKEN:
  * SEBELUM fix ini: exists()-check pakai `AWS_ACCESS_KEY_ID`/`SECRET` (S3 API auth). Selama
    window rotasi token (token lama invalid, token baru belum ter-deploy), exists()-check akan
    GAGAL MASSAL → foto-foto di list owner hilang SERENTAK sementara (bukan cuma 1-2 baris).
  * SETELAH fix ini: exists()-check sudah DIHAPUS dari jalur ini → **rotasi token R2 tidak lagi
    berefek ke tampilan foto owner sama sekali** (bonus tak terduga dari fix ini — rotasi jadi
    lebih aman). URL publik (`pub-*.r2.dev`) independen dari API key (fitur "public access" di
    level bucket Cloudflare), jadi baik jalur owner maupun operator tetap jalan normal saat rotasi.
  * R2 bucket BELUM ada CORS policy (`Access-Control-Allow-Origin`) — dikonfirmasi via `fetch()`
    langsung dari browser ke R2 URL, gagal CORS eksplisit. TIDAK masalah utk `<img>` biasa (tak
    butuh CORS), tapi PENTING dicatat kalau nanti ada fitur yang butuh akses canvas/pixel gambar
    (lightbox zoom, crop ulang foto lama, embed gambar di export PDF) — bucket perlu CORS
    ditambah SEBELUM fitur itu dibangun, supaya tak kejebak lagi kaget di kemudian hari.

### RONDE 2 — user masih lihat kosong setelah fix di atas (7 Juli 2026, commit 71cf0eb)
- User laporan lagi: SETELAH fix `checkFileExistence(false)` + deploy + hard-reload, foto MASIH
  broken-image icon. Beberapa teori DIBANTAH satu-per-satu dengan bukti keras, BUKAN diasumsikan:
  1. **"URL owner beda/signed dari operator"** — DIBANTAH: `php artisan tinker` pakai config
     produksi asli menghasilkan URL BYTE-IDENTIK utk accessor (operator) vs `ImageColumn::
     getImageUrl()` (owner) — sama-sama `Storage::url()` polos, `getVisibility()` ImageColumn
     default `'public'` juga (bukan cuma FileUpload form), jadi cabang `temporaryUrl()` tak
     pernah aktif di KEDUA jalur. `<img>` tag lengkap (semua atribut) juga dibandingkan — tak ada
     `loading="lazy"`, tak ada query-signature, keduanya `<img>` polos.
  2. **"Livewire DOM-morph batalin request (ERR_ABORTED)"** — DIBANTAH: 20× reload langsung
     (`page.goto`) + 15× via klik navigasi sidebar, 0 `ERR_ABORTED`, 0 auto Livewire POST
     terdeteksi setelah load, tak ada `wire:poll` di halaman.
  3. **"Cert R2 salah / jaringan/device user"** — SEBAGIAN BENAR gejalanya (`net::
     ERR_CERT_COMMON_NAME_INVALID` betulan direproduksi), TAPI BUKAN sebab yang diasumsikan:
     `curl` 30× beruntun ke URL yang SAMA PERSIS = 100% `200 OK` (server R2 tak masalah); script
     Playwright yang GAGAL 15/15 tadi, di-run ULANG PERSIS beberapa menit kemudian di mesin yang
     SAMA = 15/15 SUKSES (bukan konsisten di satu metode navigasi/kode tertentu — gagal lalu
     pulih sendiri tanpa perubahan apa pun). Ini reproduksi di MESIN & JARINGAN BERBEDA dari user,
     jadi bukan spesifik ke device/network/antivirus user.
- **AKAR RONDE 2 (dibuktikan, bukan diasumsikan)**: kegagalan TRANSIEN di level **koneksi
  browser Chromium** ke domain CDN cert-wildcard bersama (`*.r2.dev`) — kemungkinan besar
  connection-reuse/coalescing HTTP/2 Chromium yang sesekali salah pasang sertifikat utk domain
  yang di-cache/pool-kan, BUKAN app/URL/config (identik terbukti), BUKAN server R2 (curl bersih),
  BUKAN jaringan/device user secara spesifik (direproduksi di mesin lain). Ini kelas bug BROWSER,
  di luar kendali app — tapi BISA dimitigasi dari sisi klien.
- FIX (`KioskResource.php`, `->extraImgAttributes(['onerror' => ...])`, API resmi Filament, TIDAK
  sentuh vendor): retry SEKALI dengan delay 400ms (biar browser buka koneksi baru, bukan reuse
  yang bermasalah) via cache-bust query param, dengan guard `dataset.retried` cegah retry-loop
  tak terbatas kalau file BENERAN hilang.
- VERIFIKASI: disimulasikan kegagalan NYATA (intercept request pertama → abort paksa via
  Playwright route) → `onerror` terpicu → retry setelah 400ms dgn `?retry=<timestamp>` → attempt
  kedua SUKSES (`naturalWidth: 1280`, `dataset.retried: "1"`) — bukti mekanisme retry BEKERJA,
  bukan cuma kode benar di atas kertas. 10× reload lanjutan setelah deploy tetap 100% stabil.
  274 PASS, 0 regresi (fix ini murni tambahan atribut HTML, tak ada test PHP baru).

### RONDE 3 — FIX DEFINITIF: proxy same-origin (7 Juli 2026, commit 1a54eca)
- User BUKTI FINAL (menentukan arah, membantah semua teori environment sebelumnya): URL R2
  dibuka LANGSUNG di tab (WiFi/Chrome sama persis) → foto tampil, cert valid. URL BYTE-SAMA
  sebagai `<img>` di list owner, jaringan sama, menit berdekatan → `ERR_CERT_COMMON_NAME_INVALID`
  konsisten, `?retry=` ikut gagal. → Koneksi browser ke `pub-*.r2.dev` **DALAM KONTEKS HALAMAN**
  di-mishandle di environment tertentu (kemungkinan HTTP/2 connection coalescing/ECH Chromium),
  environment-dependent, TIDAK worth dikejar lagi — solusi: hilangkan ketergantungan browser ke
  r2.dev SEPENUHNYA, bukan lanjut diagnosa cert/DNS/antivirus.
- IMPLEMENTASI:
  1. `App\Http\Controllers\KioskPhotoController` — route `GET /kiosks/{kiosk}/photo`
     (middleware `auth` saja, SENGAJA TANPA `no-store` karena butuh Cache-Control publik).
     Route model binding `Kiosk $kiosk` otomatis kena `OwnerScope` global (`Kiosk::booted()`)
     → owner/operator lintas-tenant dapat 404 (record "tak pernah ada" bagi mereka), super_admin
     bypass — **peningkatan keamanan** dibanding public bucket R2 sebelumnya (siapa pun yang tahu
     URL R2 bisa akses, sekarang wajib auth + scope tenant benar). Stream via
     `Storage::disk($mediaDisk)->response($photo_path, ...)`, Content-Type dari ekstensi file
     (hindari 1 round-trip `mimeType()` API R2), `Cache-Control: public, max-age=31536000,
     immutable` + ETag.
  2. `Kiosk::photo_url` — SATU sumber URL: `route('kiosks.photo', $this).'?v='.$this->
     updated_at->timestamp`. Owner (`KioskResource` `ImageColumn::getStateUsing`) DAN operator
     (`active-trip.blade.php`, TIDAK diubah — sudah pakai accessor ini) sekarang genuinely satu
     jalur yang SAMA, bukan cuma "hasilnya identik" seperti ronde sebelumnya. `?v=` (timestamp)
     cache-bust otomatis tiap kios disimpan ulang (termasuk upload foto baru) — aman cache
     agresif krn URL berubah begitu konten berubah (pola sama dgn Vite hash).
  3. `sw.js`: `/kiosks/{id}/photo` masuk cache-first (immutable karena ber-versi) → foto instan
     + OFFLINE utk operator lapangan, bonus dari arsitektur baru.
  4. TIDAK diubah: bucket R2, `AWS_URL`, tak ada diagnosa cert/DNS/antivirus lanjutan (final,
     bukan actionable dari app).
- TEST BARU (`KioskPhotoControllerTest`, 7 test): guest → redirect login; owner A akses foto
  kios owner B → 404; operator tenant benar → 200 (`Content-Type: image/*`, `Cache-Control:
  public`); operator lintas-tenant → 404; super_admin → 200 (bypass scope); kios tanpa foto →
  404; `photo_path` terisi tapi file hilang dari disk → 404 bersih (bukan 500). Test lama
  (`KioskPhotoStorageTest`, `KioskPhotoListStabilityTest`) diupdate ikut kontrak accessor baru
  (assert URL proxy, bukan URL R2 langsung; assert `r2.dev` TIDAK PERNAH muncul di HTML).
  281 PASS total, 0 regresi.
- VERIFIKASI PRODUKSI (headed Chrome asli, setelah deploy):
  * 10× reload list owner: 100% stabil, **0 request ke r2.dev/r2.cloudflarestorage.com dari
    browser** (dikonfirmasi `page.on('response')` across semua reload — array kosong).
    Raw HTML server: 0 occurrence `r2.dev`, 2 occurrence `/kiosks/{id}/photo` (URL proxy benar).
  * Latency: load pertama (uncached) 558ms & 1270ms per foto (round-trip Railway↔R2 asli, wajar).
    Reload biasa (soft): **0ms, transferSize 0** — disajikan LANGSUNG dari HTTP cache browser
    (lebih baik dari sekadar 304 — TANPA request jaringan sama sekali, berkat `Cache-Control:
    immutable` + URL ber-versi).
  * Screenshot: "HM Said 1" & "Kedai Cempaka" tampil normal dari list owner produksi.
- ⚠️ TODO USER: verifikasi FINAL di device yang SEBELUMNYA konsisten gagal (itu ujian
  sebenarnya) — akar sudah dibuktikan environment-dependent di sisi Chromium, jadi device lama
  itu HARUS sembuh sekarang (browser tak lagi pernah konek ke r2.dev sama sekali, kelas bug ini
  literally tak bisa terjadi lagi apa pun environment-nya).

### RONDE 4 — VALIDASI MENDALAM pasca-deploy: 1 REGRESI NYATA ketemu & difix (7 Juli 2026, commit 4935057)
- User konfirmasi foto MUNCUL di produksi, tapi minta audit "jangan bilang aman tanpa bukti" —
  cek regresi, efek samping, & jelaskan Network log merah yang masih terlihat.
- **(A) REGRESI NYATA DITEMUKAN — jalur KETIGA yang kelewat**: audit grep menyeluruh
  (`photo_url`/`photo_path` di seluruh codebase) menemukan Filament `FileUpload::
  getUploadedFileUsing()` BAWAAN (widget upload di form create/edit kios — BEDA dari
  `Tables\Columns\ImageColumn` yang sudah difix) generate URL R2 LANGSUNG lewat
  `$storage->url($file)` sendiri, TIDAK PERNAH ikut dikonversi ke proxy. Dibuktikan headed
  Chrome (lokal DAN produksi, kios nyata "HM Said 1"): widget "Foto Kios" macet SELAMANYA di
  "Waiting for size" (dipantau 20+ detik, tak pernah resolve — bukan lambat, PERMANEN rusak)
  krn browser request `pub-*.r2.dev` kena `net::ERR_CERT_COMMON_NAME_INVALID` yang SAMA PERSIS.
  * FIX: `->getUploadedFileUsing()` di-override pakai `Kiosk::photo_url` (proxy sama persis
    dgn list & operator) — SEKARANG genuinely SATU jalur di SELURUH app (list, operator, form
    create/edit), bukan cuma "hasilnya kebetulan sama" seperti klaim ronde sebelumnya.
  * VERIFIKASI: headed Chrome produksi, kios nyata "HM Said 1" (`/owner-panel/kiosks/965/edit`)
    — widget resolve INSTAN (bukan lagi stuck), foto asli (Google Street View kios) tampil,
    request `/kiosks/965/photo?v=...` → `200`, `Cache-Control: immutable, max-age=31536000,
    public`. 0 request r2.dev di seluruh sesi (login+list+edit).
  * SEVERITY: serius kalau tak ketemu (form edit/create kios jadi tak bisa dipakai normal utk
    kios berfoto — bukan kosmetik), TAPI sudah 100% tertutup sebelum jadi laporan user.
- **Operator — DIKONFIRMASI TETAP JALAN** (kekhawatiran utama user): headed Chrome sbg
  operator, buka trip aktif → modal kunjungan kios nyata → foto render `naturalWidth: 1280`,
  request proxy `200` `content-type: image/jpeg`, 0 request r2.dev. TIDAK ADA regresi di jalur
  operator (kode `active-trip.blade.php` 0 disentuh sejak awal).
- **Upload foto baru**: dibuktikan `KioskPhotoStorageTest` (3 test, PASS) — upload → tersimpan
  ke disk media → `photo_url` accessor generate proxy URL dgn benar, end-to-end.
- **Tenant isolation — DIBUKTIKAN, bukan diasumsikan** (7 test `KioskPhotoControllerTest`):
  guest → redirect login; owner A akses foto kios owner B → `404` (`OwnerScope` global pada
  route model binding `Kiosk $kiosk`, otomatis, TIDAK ADA bypass); operator lintas-tenant →
  `404`; operator tenant benar → `200`; super_admin → `200` (bypass scope, sesuai desain);
  kios tanpa foto → `404`; `photo_path` terisi tapi file hilang dari disk → `404` bersih
  (bukan 500). Route model binding DIKONFIRMASI kena scope (bukan query mentah) — cek kode
  `Kiosk::booted()` + hasil test cross-tenant.
- **(B) Efek samping performa/beban — angka konkret**:
  * Latency: load pertama (uncached) 558–1270ms per foto (round-trip Railway↔R2 asli via
    proxy — hop TAMBAHAN dibanding dulu browser→R2 langsung, tapi masih dalam batas wajar).
    Reload berikutnya: **0ms, transferSize 0** (browser cache `immutable`, TANPA request
    jaringan sama sekali) — LEBIH CEPAT dari sebelumnya (dulu selalu ke r2.dev tiap kali,
    sekarang cuma sekali per versi foto). Operator lapangan: foto yang SUDAH pernah dilihat
    sekali JADI BISA DIBUKA OFFLINE (bonus, tak ada sebelumnya).
  * Memori server: `Storage::response()` (Laravel core) pakai `fpassthru($stream)` pada
    resource stream dari `readStream()` — TIDAK memuat file penuh ke variabel PHP, genuinely
    streaming chunk-by-chunk. Dikonfirmasi baca source `FilesystemAdapter::response()`
    langsung, bukan asumsi.
  * SW: aturan `/kiosks/{id}/photo` dicek TAK bentrok urutan dgn aturan lain (path tak match
    `IMMUTABLE_ASSET_PATTERN` krn tanpa ekstensi file, jadi jatuh ke aturan eksplisit barunya
    sendiri). Cache-bust DIBUKTIKAN jalan: simulasi ganti foto (`photo_path` baru + `updated_at`
    ke-touch) → `?v=` berubah → reload berikutnya fetch URL BARU (bukan basi), entry cache lama
    jadi orphan tak berbahaya (tak pernah direferensikan lagi).
- **(C) Network log merah ERR_CERT yang user lihat — PENJELASAN**: reload BERSIH (browser
  profile baru, 0 riwayat sebelumnya) di produksi = **0 request r2.dev**, dikonfirmasi berulang
  kali di sesi ini. Kalau user masih lihat entry merah di DevTools dengan "Preserve log"
  tercentang, itu request LAMA dari SEBELUM fix ter-deploy yang MENUMPUK di log (Preserve log
  sengaja tak pernah membersihkan log lintas-navigasi) — bukan request baru dari kode yang
  sekarang. TODO USER: uncheck "Preserve log" (atau klik 🚫 clear) lalu reload → seharusnya
  nihil entry r2.dev baru muncul.
- KESIMPULAN JUJUR: fix AMAN dipakai, TAK ADA fitur yang jadi korban permanen — SATU regresi
  transisional (widget form) ketemu & tertutup DALAM audit ini sebelum sempat jadi keluhan
  user. 281 PASS tetap (0 regresi test), 0 request r2.dev di semua jalur yang diperiksa.
  TIDAK ada rekomendasi untuk membatalkan fix.

## ✅ AUDIT SESSION & TRIP PERSISTENCE — VERDICT AMAN (7 Juli 2026)
- KEKHAWATIRAN OWNER: operator ke-logout otomatis di lapangan (sinyal jelek, HP ke-lock
  berjam-jam) → trip yang sedang jalan HILANG / harus mulai trip baru. Klaim "login tahan
  5 tahun" dari sesi lalu diverifikasi ULANG (bukan diasumsikan lagi).
- **Trip persistence — DB-based, TERBUKTI, bukan teori:**
  * `app/Models/Trip.php` — model Eloquent biasa (kolom `started_at`/`ended_at`), TIDAK ADA
    jejak session/cache sama sekali.
  * `Dashboard.php:26-30`, `StartTrip.php:24-33`, `ActiveTrip.php:152-160` — SEMUA query
    `Trip::where('operator_id', auth()->id())->whereNull('ended_at')->first()` langsung ke DB
    tiap mount. `ActiveTrip::mount()` malah MENGABAIKAN `{tripId}` dari URL sama sekali —
    selalu cari trip aktif operator dari DB, jadi URL basi/bookmark tetap resolve ke trip benar.
  * Dashboard tampilkan tombol "Lanjutkan Trip Aktif" (dashboard.blade.php:21-29) kalau trip
    aktif ketemu; StartTrip auto-redirect ke trip yang sudah ada (tak pernah biarkan trip dobel).
  * TEST BARU (commit fd46bff): `TripPersistenceAcrossLoginTest` — logout BENERAN (invalidate
    session + `Auth::logout()` yang rotate token, bukan simulasi) lalu login ulang → Dashboard/
    StartTrip/ActiveTrip semua resume ke trip yang SAMA, cuma 1 baris trip di DB (tak dobel);
    plus `{tripId}` URL sembarang tetap resolve ke trip aktif yang benar. 2 test, 272 PASS total.
- **Session/login — remember-me default-on, ~5 tahun, FAKTA framework:**
  * `LoginForm.php:24`: `$remember = true` DEFAULT (bukan opt-in), checkbox login.blade.php:65
    tercentang bawaan. `Auth::attempt($credentials, true)` → Laravel `SessionGuard`/`CookieJar::
    forever()` = 2.628.000 menit = 5 tahun PERSIS (konstanta framework, bukan klaim dikarang).
  * Konsekuensi: raw `SESSION_LIFETIME` (480 mnt/8 jam di `.env` lokal) BOLEH habis karena idle,
    tapi remember-cookie tetap ada → request berikutnya (page load ATAU aksi Livewire AJAX,
    keduanya lewat middleware `web` yang sama) auto re-login TRANSPARAN tanpa form. Operator
    praktis tetap login selama device/browser sama & tak logout manual — jauh melampaui skenario
    "HP di-lock beberapa jam".
  * Edge-case yang SEBELUMNYA (17 Juni 2026, bukan sesi ini) bikin 419 saat rotasi sesi diam-diam
    — dicek MASIH AKTIF sekarang: `pwa-token-refresh.blade.php` (sinkron token saat resume),
    `bootstrap/app.php` CSRF-exempt logout + handler 419 ramah. Tak ada mekanisme logout paksa
    lain (dicek config/auth.php + app/Http/Middleware/ — tak ada single-session/IP-lock;
    `Filament\Http\Middleware\AuthenticateSession` di OwnerPanelProvider cuma re-validasi
    password hash, bukan timeout tambahan, dan operator tak pernah pakai panel Filament owner).
- ⚠️ SATU ITEM TERBUKA (tak ubah verdict): `SESSION_LIFETIME` aktual di Railway produksi BELUM
  di-cross-check langsung sesi ini (tak ada akses Railway CLI/dashboard dari sini). Tidak
  mengubah kesimpulan — remember-me bekerja independen dari angka lifetime itu. TODO USER:
  cek Railway dashboard kalau mau 100% pasti angkanya, tapi tak mendesak.
- KESIMPULAN: TIDAK ADA FIX yang direkomendasikan/dieksekusi. Kedua kekhawatiran sudah tertutup
  desain yang ada (audit murni, test-only, 0 perubahan kode produksi).

## ✅ AUDIT + FIX CACHE-BUSTING SERVICE WORKER — public/sw.js NAIK ke v5 (7 Juli 2026)
- KONTEKS: dipicu 2 insiden cache-basi produksi (CSS Filament, lalu leaflet-map-picker.js). Audit
  dulu (TANPA ubah kode) menemukan struktur besar SW SUDAH benar (skipWaiting/clients.claim/
  HTML network-only/Livewire bypass semua textbook), tapi 2 celah nyata:
  1. **`networkFirst()` tak memaksa bypass HTTP-cache browser** — `fetch(request)` polos memakai
     cache mode default. Produksi TIDAK kirim header `Cache-Control` sama sekali (dikonfirmasi
     `curl -D-` ke asset live) — cuma ETag + Last-Modified — jadi browser BOLEH pakai
     heuristic-freshness dan diam-diam anggap respons lama "masih fresh" TANPA pernah nanya
     server, walau SW-nya sendiri niatnya "network-first". Ini akar paling konkret kenapa
     "sudah network-first" tetap bisa basi.
  2. **Default fallback utk path tak dikenal = cache-first** (allowlist, bukan aman-by-default).
     File baru yang lupa didaftarkan ke daftar network-first otomatis basi-selamanya — persis
     pola 2 insiden di atas.
- FIX (3, sesuai brief user, SEMUA additive/low-risk — TIDAK ada yang mengubah HTML/Livewire
  handling yang sudah benar):
  1. **`networkFirst()` pakai `fetch(request, { cache: 'no-cache' })`** (public/sw.js:182-194).
     ⚠️ KOREKSI TEKNIS dari brief awal: brief minta `'reload'`, tapi per spek Fetch API `'reload'`
     SKIP validator sama sekali (selalu full re-download, TAK PERNAH dapat 304) — kebalikan dari
     yang diminta ("tetap dapat 304"). `'no-cache'` yang benar-benar memaksa validasi
     (If-None-Match/If-Modified-Since) SAMBIL tetap membolehkan 304 cepat. Dipakai `'no-cache'`,
     bukan literal `'reload'` — kalau dipakai `'reload'` malah lebih berat di sinyal jelek
     (selalu full download), bertentangan dengan tujuan "sat-set operator lapangan".
  2. **Default dibalik jadi network-first (aman-by-default)** (public/sw.js:82-148). Immutable
     (cache-first) sekarang cuma 3 kategori eksplisit: `/build/*` (Vite hash), `APP_SHELL` (app
     shell yang di-precache), dan **gambar/font by extension**
     (`/\.(png|jpe?g|gif|webp|svg|ico|woff2?|ttf|eot)$/i` — mencakup foto kios upload, ikon marker
     Leaflet, favicon.ico, dll: berat byte-wise tapi upload baru = nama file baru, jadi aman
     cache-first selamanya). SEMUA SISANYA (Filament CSS/JS, leaflet.js/css,
     leaflet-map-picker.js, DAN path baru apa pun di masa depan) otomatis network-first — lupa
     daftarin = tetap fresh (aman), bukan basi (bug).
  3. **`registration.update()`** ditambah setelah `.register()` di `pwa-head.blade.php:13-18` —
     murni tambahan, paksa cek SW baru tiap app dibuka (bukan nunggu siklus ~24 jam default
     browser).
  4. `CACHE_NAME` dibump `dodol-v4` → `dodol-v5` — dipakai KARENA strategi per-path berubah
     (beberapa path pindah bucket cache-first↔network-first), bukan rutinitas tiap deploy biasa
     (lihat catatan arsitektur di bawah).
- PEMETAAN CACHE BARU (v5):
  | Kategori | Path | Strategi | Alasan |
  |---|---|---|---|
  | Immutable | `/build/assets/*` (Vite hash) | cache-first | nama file berubah tiap build |
  | Immutable | App shell (`offline.html`, manifest, ikon) | cache-first | di-precache saat install |
  | Immutable | `*.png/jpg/gif/webp/svg/ico/woff/ttf/eot` | cache-first | foto/ikon berat, upload baru = URL baru |
  | — | Navigasi HTML | network-only | multi-tenant safety, tak pernah cache |
  | — | `/livewire/*`, `/csrf-token` | bypass total | tak boleh nambah delay / tak boleh basi |
  | **DEFAULT** | **semua sisanya** (Filament CSS/JS, Leaflet vendor JS/CSS, leaflet-map-picker.js, path baru) | **network-first (`no-cache`)** | aman-by-default — lupa daftarin = fresh, bukan basi |
- AUDIT PERFORMA (headed Chrome asli, BUKAN headless, terhadap **production build** — `npm run
  build` + `php artisan serve`, bukan `npm run dev`, karena Vite dev server tak menghasilkan
  `/build/*` hash yang perlu diuji):
  * Cache-first assets (Vite `/build/*`, `favicon.png`) pada load ke-2: **transferSize = 0B**,
    duration ~1ms — instan dari Cache Storage, tak ada request jaringan sama sekali. Ini yang
    menjaga operator tetap sat-set utk aset berat.
  * Network-first assets (Filament CSS/JS) pada load ke-2 di **dev server lokal**: status 200
    (bukan 304) — TAPI ini keterbatasan `php artisan serve` sendiri, dikonfirmasi via curl:
    dev server TIDAK PERNAH kirim header `ETag`/`Last-Modified` sama sekali, jadi browser tak
    punya validator apa pun untuk dikirim balik, terlepas dari sw.js. **Produksi BEDA**: `curl`
    langsung ke asset live dengan `If-None-Match` dari ETag sebelumnya → server balas
    **`304 Not Modified`** (dikonfirmasi eksplisit). Jadi fix `no-cache` akan dapat manfaat 304
    penuh begitu di-deploy — cuma tak bisa didemonstrasikan di dev server lokal.
  * SW update mechanics: simulasi "deploy baru" (byte-diff sw.js di disk + `registration.update()`
    manual) → event `controllerchange` fire **47ms** kemudian, SW baru ambil alih tab yang SUDAH
    TERBUKA tanpa perlu ditutup. skipWaiting+clients.claim terbukti jalan cepat.
  * Throttle Slow 3G (CDP `Network.emulateNetworkConditions`, ~400kbps/400ms latency): repeat-load
    operator/dashboard = 1649ms, halaman tetap render & usable.
  * Offline fallback: server dimatikan BENERAN (bukan cuma CDP offline-emulation — itu tak
    menjangkau execution context Service Worker-nya sendiri, jadi under-test kalau cuma
    emulasi), navigasi ke halaman baru → benar-benar jatuh ke `offline.html` ("Kamu sedang
    offline... Cek sinyal atau Wi-Fi kamu, lalu coba lagi"), BUKAN dashboard lama & BUKAN error
    browser generik.
- KESIMPULAN PERFORMA: operator lapangan **TIDAK jadi lebih lambat**. Aset kritikal-kecepatan
  (Vite bundle, foto kios, ikon) tetap cache-first instan. Yang berubah cuma aset non-hash
  (Filament panel CSS/JS — TIDAK dipakai operator sama sekali, cuma owner/admin panel — dan
  leaflet-map-picker.js/vendor Leaflet JS/CSS yang sudah kecil) jadi selalu validasi ke server,
  yang di produksi dapat 304 cepat (bukan re-download penuh).
- php artisan test: 270 PASS (1039 assertions) — SW murni JS, tak menyentuh test PHP, dicek untuk
  pastikan tak ada regresi tak terduga.
- TODO USER: setelah deploy, verifikasi produksi — buka DevTools Application tab, konfirmasi
  `dodol-v5` jadi cache aktif (v4 lama otomatis dibuang), dan cek Network tab utk asset Filament/
  Leaflet menunjukkan 304 pada reload kedua.

## ✅ MIGRASI PETA OWNER: dotswan/filament-map-picker → LEAFLET HAND-ROLLED (6 Juli 2026)
  1. **Super_admin bikin owner baru** — alur: panel `/admin` (HANYA super_admin/owner boleh masuk),
     `UserResource` + `Pages/CreateUser.php` (app/Filament/Resources/UserResource.php:70-76: opsi
     role 'owner'/'super_admin' cuma muncul kalau `isSuperAdmin()`). Owner baru TERBUKTI mulai
     dengan 0 kios/0 cluster/0 operator walau owner lain sudah punya data (test create user via
     Livewire form asli → login owner baru → assert count=0). Owner sendiri TAK BISA bikin owner
     lain (CreateUser::mutateFormDataBeforeCreate paksa role=operator+owner_id=diri walau payload
     di-tamper — dites eksplisit). Test: `SuperAdminOwnerProvisioningTest` (4 test, baru).
  2. **Isolasi antar-owner, READ + WRITE** — OwnerScope aktif di 5 model Level-1 (Cluster, Supplier,
     Product, ProcurementBatch, Trip via trait `BelongsToOwner`) + Kiosk (Level-2 via cluster,
     didaftarkan langsung di `Kiosk::booted()`). READ sudah lama dibuktikan (`OwnerScopeTest`).
     WRITE cross-tenant di panel owner (Filament CRUD) BARU dibuktikan sesi ini: GET edit page
     kios/cluster/supplier/product/procurement-batch/product-variant milik owner lain → 404 semua
     (route-model-binding gagal nemu record duluan, intruder tak tahu record itu ada); mount
     Livewire EditKiosk paksa ID asing → ModelNotFoundException; delete table action utk kios
     owner lain → DITOLAK Filament (record tak pernah "visible" di query yang di-scope); list
     kios TAK PERNAH memuat kios owner lain. Bonus temuan: super_admin JUSTRU DITOLAK (403) masuk
     `/owner-panel/*` sama sekali (panel itu cuma untuk role 'owner' — beda dari bypass "lihat
     semua" yang berlaku di widget dashboard admin panel, BUKAN di rute Filament resource
     owner-panel). Test: `OwnerWriteCrossTenantTest` (10 test, baru).
  3. **Isolasi operator per-owner** — scope lewat `cluster.owner_id`, enforced ganda: OwnerScope
     global (otomatis) + gerbang manual `ownedKiosk()`/`whereHas('cluster', owner_id)` di
     ActiveTrip.php (openVisitModal, saveVisit, stopWithSettle, stopWithoutSettle) — SENGAJA
     dipertahankan dobel sebagai defense-in-depth terhadap properti Livewire yang di-tamper klien
     (bukan cuma andalkan OwnerScope). 4 lubang WRITE dari audit Langkah 1 (openVisitModal,
     saveVisit, stopWithSettle, stopWithoutSettle) MASIH tertutup & test MASIH HIJAU (tak perlu
     ditulis ulang): `ActiveTripCrossTenantTest` (6 test, existing, di-re-run & dikonfirmasi).
  * ✅ **TEMUAN RESIDUAL ProductVariant — SUDAH DIFIX (6 Juli 2026, commit selanjutnya)**: dulu
    `ProductVariant` (Level-2 via `product.owner_id`, sama bentuknya dengan Kiosk) TIDAK
    didaftarkan di `OwnerScope::apply()` → `find()`/`::all()` POLOS BOCOR lintas-owner tanpa scope
    manual. FIX: pendekatan (a) yang dipilih (isolasi > DRY untuk kode security-critical) —
    `ProductVariant::booted()` daftarkan `OwnerScope` persis pola `Kiosk::booted()`, dengan cabang
    BARU & TERPISAH di `OwnerScope::apply()` (`instanceof ProductVariant` → `whereHas('product',
    owner_id)`) yang TIDAK menyentuh/mengubah cabang `instanceof Kiosk` yang sudah ada. Opsi (b)
    generalisasi ditolak — lebih DRY tapi lebih beresiko ngubah kode yang jaga Kiosk.
    Call site manual (ProductVariantResource, ActiveTrip::resolveActiveVariant/B1) DIBIARKAN
    (redundan tapi aman, pola sama dgn gerbang dobel Kiosk di ActiveTrip). Verifikasi: super_admin
    & bypass no-auth/CLI tetap jalan (test eksplisit), escape hatch `withoutGlobalScope` tetap
    reachable, 0 regresi di OwnerScopeTest/OwnerWriteCrossTenantTest/ActiveTripCrossTenantTest/
    SuperAdminOwnerProvisioningTest (27 test, semua hijau). Test:
    `ProductVariantScopeGapTest` (6 test, expectation dibalik dari "bocor" jadi "aman").
- 249 PASS (985 assertions) sebelum audit isolasi sesi ini, runtime ~63-102s tergantung beban
  sistem (tak ada test abnormal lambat dari fitur maps — semua kasus <1s; satu outlier 2.98s
  pre-existing tak terkait, DeliveryObserverTest).
- ✅ FITUR INPUT LOKASI GOOGLE MAPS SELESAI & PUSHED (6 Juli 2026, commit 1bf6e48/0c7bd4c/47ed5ac):
  * Tier 1 (KioskLocationParser, regex murni 0-network): koordinat langsung, @lat,lng, ?q=, ?ll=,
    !3d!4d — SOLID, teruji beneran unit test.
  * Tier 2 (GoogleMapsShortLinkResolver): resolve short-link maps.app.goo.gl/goo.gl/g.co via
    HTTP redirect hop-by-hop + SSRF safeguard (allowlist host per-hop + DNS private-IP guard).
    CATATAN PENTING: HANYA teruji via Http::fake() (mock) — BELUM PERNAH divalidasi terhadap
    link pendek ASLI. Primary use-case (operator share lokasi dari HP via WhatsApp/Maps app)
    MASIH BELUM diverifikasi end-to-end. TODO USER: coba tempel link maps.app.goo.gl asli
    di form owner (tombol "Loncat") dan form operator (create-kiosk), pastikan benar landing
    di koordinat yang benar.
  * Wiring UX: tombol "Loncat" di form owner (KioskResource, Filament Action) + operator
    (CreateKiosk, wire:click="jumpToMapsLocation") — resolver Tier 2 HANYA jalan saat tombol
    diklik (bukan di form load/validasi rutin, dikonfirmasi via audit performa di bawah).
  * Fix bonus: invalidateSize peta pakai IntersectionObserver (map-invalidate-size.blade.php)
    biar peta owner ga blank kalau tombol "Loncat" diklik sebelum peta pernah ke-scroll ke layar.
- ✅ AUDIT PERFORMA PASCA-OwnerScope+maps (6 Juli 2026) — VERDICT RINGAN, dibuktikan pakai query
  log nyata (bukan teori):
  * Owner dashboard: 21 query (EXISTS/IN-batched, tak ada loop per-row).
  * Owner list kios (Filament): 4 query (identik baseline lama).
  * Owner form Buat Kios (render): 1 query (dropdown cluster).
  * Operator form Buat Kios (mount): 2 query — method jumpToMapsLocation() SENDIRI 0 query DB
    (murni regex + HTTP kondisional).
  * Operator modal kunjungan: mount 8 query, openVisitModal +12 query (delta) — flat, batched,
    konsisten baseline lama (~7-9q).
  * Resolver Tier 2: DIKONFIRMASI hanya terpasang di ->action()/wire:click tombol "Loncat" —
    BUKAN ->live()/afterStateUpdated()/mount. 0 query DB, 0 HTTP call kecuali tombol diklik DAN
    Tier 1 gagal DAN host lolos allowlist. Tak ada resiko lambat di form load/validasi rutin.
  * TEMUAN (bukan regresi dari maps, sudah ada sejak Langkah 2/OwnerScope): beberapa query Kiosk
    (KioskResource::getEloquentQuery, ActiveTrip::kioskViewData/ownedKiosk) punya KLAUSA EXISTS
    ganda — manual whereHas('cluster', owner_id) peninggalan Langkah 1 (pre-OwnerScope) + EXISTS
    otomatis dari OwnerScope, terlihat dobel di SQL log. TAPI: 0 query tambahan, 0 round-trip
    tambahan — cuma predikat terindeks yang diulang dalam SATU statement yang sama (murah). Di
    ActiveTrip gate ini SENGAJA dipertahankan sbg defense-in-depth (cegah properti Livewire
    di-tamper klien, lihat catatan Langkah 1) — JANGAN dibuang tanpa pertimbangan keamanan. Di
    Filament resource scoping murni jadi dead-weight kosmetik (aman dibuang kapan saja, tak ada
    rush). TAK PERLU FIX SEKARANG (dampak nol pada jumlah query/latency), dicatat buat cleanup
    opsional sesi depan.
  * Asset: 0 dependency baru (package.json/composer.json tak berubah). JS fitur maps ~30 baris
    vanilla/Alpine inline di file blade yang sudah ada — tak nambah bundle Vite.
- ✅ Audit performa: RINGAN, Fase-1 utuh. 3 fix murah dieksekusi (whereDate→where #1, N+1 batchStok
  #3, guard memory GD #4). Ditunda: #2 thumbnail foto, #5 denormalisasi owner_id (sblm 10k+ settlement).
- ✅ Upload foto kios FIXED & verified PRODUKSI (desktop+mobile, owner+operator): 2 blocker beruntun
  dibereskan — (1) CORS temp-upload R2 (commit 0455b58: pin temp disk local) + (2) 401 signed-URL
  di balik proxy Railway (commit 5e8a032: trustProxies). Bukti: /livewire/upload-file 200, foto
  mendarat pub-*.r2.dev HTTP 200 (create→R2→delete di prod, sudah dibersihkan).
- ✅ Peta grey-saat-zoom FIXED & verified PRODUKSI (commit bad45b7: cap maxZoom 20 + Carto Voyager,
  owner+operator): hard-zoom ke max coverage 100%, no grey, desktop+mobile.
- Live di Railway: https://dodol-app-production.up.railway.app — PRODUKSI SUDAH LIVE dengan user asli.
- ⚠️ UTANG KRITIKAL GANTUNG — ROTASI KREDENSIAL: token R2 (akses bucket foto kios) + password DB
  produksi BELUM PERNAH dirotasi sejak setup awal. Makin lama makin beresiko sekarang produksi
  sudah live & dipakai user asli. ⏳ TODO: jadwalkan rotasi R2 token + DB password produksi, update
  env Railway, redeploy, verifikasi upload+DB masih jalan setelah rotasi.
- Semua fitur complete (Tier 2 maps butuh verifikasi link asli sebelum dianggap 100% tuntas)

## ✅ LANGKAH 2 — GLOBAL SCOPE SECURE-BY-DEFAULT SELESAI (3 Juli 2026)
- JAMINAN ISOLASI TENANT KINI BY DESIGN, BUKAN MANUAL: lupa nambah `where owner_id` = data
  ke-FILTER (aman), bukan bocor. Menutup akar kerapuhan yang bikin bug dropdown + lubang operator.
- IMPLEMENTASI (arah user: 5 model Level-1 + Kiosk; TANPA denormalisasi Level-2 = #5 tetap ditunda):
  * BARU `app/Models/Scopes/OwnerScope.php` — global read scope + resolver `activeOwnerId()`.
    - Level-1 (punya kolom owner_id): `where('<table>.owner_id', $active)` → clusters, suppliers,
      products, procurement_batches, trips. Didaftarkan via `BelongsToOwner::bootBelongsToOwner()`.
    - Kiosk (Level-2, owner via cluster): `whereHas('cluster', owner_id=$active)`. Didaftarkan di
      `Kiosk::booted()` (Kiosk tak pakai trait).
  * RESOLVER `activeOwnerId()` (aturan per role):
    owner login → auth()->id(); operator login → auth()->user()->owner_id;
    super_admin → NULL (BYPASS, lihat semua); tanpa auth (seeder/factory/CLI/queue/migration) →
    NULL (BYPASS); operator legacy owner_id null → bypass (konsisten pola lama).
- withoutGlobalScope: **0 titik perlu ditambah**. Semua query lintas-owner yang disengaja aman
  OTOMATIS via bypass: 4 widget super_admin (canView super_admin → bypass), OwnerOmsetChart
  (pakai ->join raw, scope tak sentuh tabel di-join), EnsureWalkInSentinel + CLI + migrasi backfill
  (tanpa auth → bypass), importer/observer (owner sama). Escape hatch `withoutGlobalScope(OwnerScope::class)`
  tetap tersedia + ada test-nya kalau nanti butuh lintas-owner sengaja.
- REGRESI DITANGANI (221 PASS, 920 assertions):
  * TripFactory backfill owner_id dari operator (di prod selalu terisi; factory tanpa auth → hook
    creating tak jalan). Fixture realistis, tak bikin user baru. → benerin 11 test operator.
  * 2 test 403→404 (TripDeleteTest, ReportExportTest) = PENINGKATAN keamanan: route-model-binding
    kini 404 intruder SEBELUM controller (intruder tak tahu record ada). Guard abort(403) controller
    tetap ada sbg defense-in-depth. Test di-update ke assertNotFound.
  * 1 fixture BatchStokTest: product/variant/supplier dibuat konsisten milik owner yang sama
    (relasi product kini kena OwnerScope → data null-owner bikin kolom productVariant.product.name null).
- KASUS ORPHAN (kios cluster_id null): TER-FILTER (tak tampil) saat ada owner aktif — AMAN (kios
  tak-ber-owner tak bocor). Data BARU tak mungkin orphan (cluster wajib sejak commit 5c9b3e7).
  ⏳ TODO PRODUKSI: cek `SELECT COUNT(*) FROM kiosks WHERE cluster_id IS NULL` — kalau ada, backfill
  cluster (jangan biarkan invisible). Sentinel walk-in TIDAK orphan (selalu punya cluster sentinel).
- TEST BARU `tests/Feature/MultiTenant/OwnerScopeTest.php` (7): owner/operator lihat data sendiri +
  `Kiosk::find(id_owner_lain)`=NULL; super_admin bypass lihat semua; tanpa-auth bypass; escape hatch;
  orphan ter-filter; NO N+1 (Kiosk::get 21 kios = 1 query EXISTS inline).
- PERFORMA before/after IDENTIK (bypass vs scope): owner dashboard 28 query, operator ActiveTrip
  render 7 query — scope nambah 0 round-trip, cuma klausa WHERE / 1 subquery EXISTS (terindeks).
- VERIFIKASI PER-ROLE BROWSER NYATA (data lokal 2 owner: Ismi=2 kios, Aidil=957): Owner Ismi lihat
  HANYA kiosnya (bukan 957, bukan kosong); super_admin dashboard "Total Kios 957" + tabel komisi
  KEDUA owner (bypass tembus semua); operator render 200 ter-scope. 4/4 cek + screenshot (bukan blank).
- ✅ RESIDUAL LANGKAH 2 SELESAI (3 Juli 2026, Tugas B) — 4 fix scoping minor + test:
  * B1 resolveActiveVariant() :1007 → scope owner (varian via product.owner_id). Efek: owner tanpa
    varian aktif kini DITOLAK ("Tidak ada varian produk aktif") alih-alih diam-diam pakai varian owner
    lain. Fixture 5 test dibikin realistis (varian pakai product milik owner sama) — sama pola TripFactory.
  * B2 StartTrip exists:clusters :142 → owner-scoped (Rule::exists->where owner_id, pola CreateKiosk).
  * B3 UserResource EditUser → mutateFormDataBeforeSave re-force role=operator + owner_id (parity CreateUser).
  * B4 ProcurementBatch getBatchNumberAttribute :58 → count PER-OWNER (nomor batch tak hitung owner lain).
  * Test baru tests/Feature/MultiTenant/ResidualScopingTest.php (7). 228 PASS.
- ✅ AUDIT PERFORMA Langkah 1+2 @ 957 kios (Tugas A) — VERDICT RINGAN. Global scope nambah 0 query
  (identik with/without di semua surface). whereHas Kiosk = EXISTS terindeks (EXPLAIN type=ref,
  clusters_owner_id_foreign→kiosks_cluster_id_foreign, rows=1), BUKAN N+1. Index clusters.owner_id ADA.
  operator ActiveTrip render 9q FLAT, owner dashboard 37q bounded, Filament list 4q (latensi = boot
  Filament, bukan query). Snapshot operator 1385B (~1.35KB) flat vs jumlah kios, tak berubah oleh Langkah 1/2.
- ⏳ TODO PRODUKSI ORPHAN (Tugas C — query sudah disiapkan buat user): jalankan di Railway
  `SELECT COUNT(*) FROM kiosks WHERE cluster_id IS NULL;` + list. Kalau ada, backfill cluster ke owner
  yang benar (JANGAN borongan kalau lintas owner). Kios orphan invisible sejak OwnerScope (aman, tak bocor).

## ✅ AUDIT ISOLASI MULTI-TENANT MENYELURUH + LANGKAH 1 (fix darurat operator) SELESAI (2-3 Juli 2026)
- KONTEKS: user minta JAMINAN isolasi tenant tahan-banting (bug dropdown kemarin lolos test → pertahanan
  rapuh karena scope MANUAL per-query). Audit exhaustive 5 permukaan (model/rantai, Filament, operator
  Livewire, controller/export/route, test) — BUKAN sampling.
- HASIL AUDIT: Filament (8 resource getEloquentQuery + semua dropdown form/filter), controller/export/route,
  observer, importer, CLI = SEMUA SCOPED (0 bocor — fix dropdown kemarin memang rapat). TAPI ditemukan
  **4 LUBANG WRITE/READ CROSS-TENANT KRITIS di panel OPERATOR (Livewire ActiveTrip)** — akar sama:
  BelongsToOwner cuma auto-set owner_id saat create, TIDAK ada global read scope → isolasi opt-in per query.
- AKAR TEKNIS: `openVisitModal($kioskId)` load `Kiosk::find()` TANPA scope → `$selectedKiosk`/`$pendingDelivery`
  (properti publik Livewire, bisa di-tamper klien) dipercaya mentah oleh write sink: `saveVisit`,
  `stopWithSettle`, `stopWithoutSettle`. Operator owner A bisa TULIS transaksi / MATIKAN kios / catat
  kerugian palsu ke kios owner B. (Gerbang benar SUDAH ADA di `saveKioskPhoto`:650 & `scopedPendingSettlements`:479
  — tinggal belum dipasang di 4 titik ini = bukti kerapuhan "ketergantungan developer ingat".)
- ✅ LANGKAH 1 (DARURAT) — SELESAI (commit ini): tutup 4 lubang pakai gerbang yang terbukti.
  * Helper baru `ownedKiosk($kioskId)` (pola identik saveKioskPhoto): ambil kios HANYA jika
    `whereHas('cluster', owner_id == auth()->user()->owner_id)`, null kalau bukan milik operator.
    `when($ownerId !== null)` → operator legacy tanpa owner_id tetap jalan (sama pola lama).
  * `openVisitModal` → pakai ownedKiosk; kios owner lain → tolak, modal TAK dibuka, selectedKiosk null.
  * `saveVisit` / `stopWithSettle` / `stopWithoutSettle` → re-verifikasi ownedKiosk sebelum tulis/matikan.
  * DEFENSE-IN-DEPTH: cek konsistensi `pendingDelivery->kiosk_id === selectedKiosk->id` (properti pendingDelivery
    juga di-hidrasi klien → cegah settle titipan owner lain walau kios sendiri).
  * TEST BARU: tests/Feature/Operator/ActiveTripCrossTenantTest.php (6 test) — 4 serangan lintas-owner
    diblokir (openVisitModal/saveVisit/stopWithSettle/stopWithoutSettle, selectedKiosk+pendingDelivery
    dipaksa via ->set meniru snapshot di-tamper) + 2 happy-path (operator normal atas kios SENDIRI saat
    owner_id di-set = skenario prod). **214 PASS (890 assertions), nol regresi.**
- ⏳ LANGKAH 2 (MASIH PENDING — keputusan user #2, JANGAN kerjakan tanpa konfirmasi): GLOBAL SCOPE
  secure-by-default. Tambah Eloquent global read scope ke BelongsToOwner utk 5 model Level-1 (clusters,
  suppliers, products, procurement_batches, trips — murah, indexed) + Kiosk via whereHas cluster
  (menutup class openVisitModal by-default: Kiosk::find auto-null utk kios owner lain). Harus: resolver
  "owner aktif" (owner=id, operator=owner_id, super_admin+CLI/seeder=bypass), withoutGlobalScope di widget
  super_admin/sentinel, tangani Kiosk.cluster_id NULLABLE (orphan), jalankan full suite (risiko regresi).
  Jangka panjang: denormalisasi owner_id ke tabel Level-2 → scope seragam murah, buang whereHas.
- ⏳ RESIDUAL MINOR (dibereskan di Langkah 2): `resolveActiveVariant()` :1009 pilih varian aktif TANPA
  scope owner (varian, bukan kios — dampak kecil); rule `exists:clusters,id` di StartTrip::startTrip :142
  tak owner-scoped (RAGU, ke-intersect jadi kosong oleh list scoped, tak ada bocor teramati); UserResource
  EditUser tak ada mutateFormDataBeforeSave re-force (RAGU rendah, aman via field tak-dirender);
  ProcurementBatch getBatchNumberAttribute count lintas-owner (kosmetik, penomoran).

## ✅ FIX BOCOR MULTI-TENANT DROPDOWN FILAMENT + "kios tidak muncul di list" (2 Juli 2026, commit 6edad9f)
- GEJALA (produksi, Ismi input kios pertama): create kios di panel OWNER → submit → kios TIDAK
  muncul di list. (User juga sebut "error aneh pas refresh" — TIDAK bisa direproduksi; list
  reload 4x semua 200, tak ada 500. Butuh log Railway / teks error asli buat pin; dugaan transient.)
- AKAR (terbukti read-only di prod): dropdown "Area" (cluster) di form Buat Kios owner TIDAK
  di-scope owner — cuma `->where('is_active', true)`. Ismi punya 1 cluster ("Marelan Mabar") tapi
  dropdown nampilin 2 (+ "Tempat Titipan" milik owner lain). Ismi pilih cluster owner lain → kios
  ke-assign ke situ → di-filter keluar dari list Ismi (getEloquentQuery: whereHas cluster.owner_id
  = auth id) → "tidak muncul". Sekaligus BOCOR multi-tenant. BelongsToOwner cuma auto-set owner_id
  saat create, TIDAK ada global scope baca → makanya relationship dropdown bocor.
- AUDIT SEMUA relationship dropdown Filament (bukan tambal 1) — yang di-scope owner:
  * KioskResource: cluster form dropdown (AKAR) + table filter → FIX scope owner.
  * ProcurementBatchResource: supplier + productVariant form dropdown + filter → FIX (supplier
    owner_id direct; varian lewat product.owner_id).
  * ProductVariantResource: product table filter → FIX (form dropdown-nya SUDAH scoped sebelumnya).
  * Pola: `->when(! (auth()->user()?->isSuperAdmin() ?? false), fn($q)=>$q->where('owner_id', auth()->id()))`
    (varian pakai whereHas('product',...)). Super admin tetap lihat semua.
- VERIFIKASI: 205 PASS (+1 test: owner form kiosk cluster dropdown cuma nampilin cluster miliknya).
  PRODUKSI (login Ismi): deploy transisi ke-capture live — dropdown [Marelan Mabar, Tempat Titipan]
  → [Marelan Mabar] saja; "Tempat Titipan" HILANG. Create kios (cluster sendiri) → MUNCUL di list
  → dihapus (bersih).
- ⏳ BELUM DIKERJAKAN (perlu keputusan user):
  * DATA NYANGKUT: kalau Ismi terlanjur buat kios di bawah "Tempat Titipan" saat percobaan asli,
    kios itu di tenant owner lain — TAK bisa kuidentifikasi dari sini (admin panel cuma UserResource;
    no akses DB/log Railway). User cek manual / kasih akses DB → bisa dibantu reassign (jangan
    auto-hapus). Setelah fix, Ismi tinggal buat ulang dgn benar.
  * GUARD OPERATOR: operator CreateKiosk izinkan cluster_id NULL (`clusterId nullable` →
    `cluster_id => $this->clusterId ?: null`) → kios tanpa cluster juga invisible di list owner.
    Bikin cluster_id WAJIB = ubah perilaku existing → TUNGGU konfirmasi user sebelum diubah.

## ✅ AUDIT PERFORMA MENYELURUH + 3 FIX MURAH (2 Juli 2026, commit 6909dab, 995e604, 46a9d27)
- KONTEKS: audit menyeluruh setelah banyak perubahan sesi (foto operator, peta Carto, upload-fix,
  trust-proxy, istilah) — pastikan app masih RINGAN buat owner input data + operator hectic di HP.
- VERDICT: **RINGAN, tidak ada regresi.** Kemenangan Fase-1 UTUH: snapshot Livewire operator tetap
  0.3–0.4KB (foto=WithFileUploads null/file-ref, piutang+tertunda semua scalar — bukan koleksi);
  ActiveTrip render 6 query FLAT (≤ baseline 9), DISPLAY_LIMIT 50 + search debounce utuh; operator
  dashboard 24KB/9 req/58 DOM/TTFB 62ms. Bundle: app.js 42KB, app.css 108KB, Leaflet 148KB
  (operator), filament-map-picker 447KB (OWNER-only, bukan operator). Latency prod TTFB 51–77ms
  (owner kiosks-list Filament 437ms outlier). GD di ImageResizer di-imagedestroy → tak bocor Octane.
- 3 FIX MURAH NO-MIGRASI YANG DIEKSEKUSI (verified, 204 PASS):
  * #1 (commit 6909dab, OwnerDashboardController): whereDate('visit_date'/'trip_date', today()) →
    where(..., today()->toDateString()). whereDate bikin DATE(col) → batalin index (full-scan saat
    settlement numpuk). Kolom sudah tipe DATE → hasil IDENTIK (bukti: sum 50000==50000, SQL jadi
    `where visit_date = ?` bukan `date(visit_date) = ?`).
  * #3 (commit 995e604, ProcurementBatch + OwnerDashboardController): N+1 batchStok. remaining_packs
    dulu `deliveries()->sum()` (query tiap panggil, 3x/batch). Diubah: `relationLoaded('deliveries')
    ? $this->deliveries->sum() : $this->deliveries()->sum()` → dashboard eager-loaded = 0 query,
    kode lain (baca ulang stok setelah nambah delivery di instance sama) tetap FRESH (perilaku lama
    utuh — awalnya sempat bikin BatchStokTest merah karena lazy-cache basi, diperbaiki dgn
    relationLoaded). Map hitung $stok sekali. Bukti: 3 baca accessor 4→0 query, stok/flag identik.
  * #4 (commit 46a9d27, ImageResizer): guard memory GD. Setelah early-return foto normal (≤1280,
    hasil kompres browser — tak tersentuh): tolak decode >24MP (patologis/bomb) + raise/restore
    memory_limit sementara buat foto besar wajar (mis. 12MP fallback ~48MB) → cegah OOM worker
    Octane. Bukti: 2400x1800→1280x960 OK, 800x600 untouched, memory_limit restored.
- DITUNDA SENGAJA (belum dikerjakan — putуskan nanti):
  * #2 THUMBNAIL FOTO: foto modal kunjungan masih full-res 1280px (≤~0.5MB) tanpa thumbnail /
    loading=lazy / width-height. Bounded (1 foto/waktu, ga numpuk) tapi tiap buka kios ber-foto =
    download ≤0.5MB di sinyal jelek. Fix = derivative thumbnail ~320px saat upload + loading=lazy.
    Paling ngaruh ke operator lapangan; terpisah karena butuh generate derivative.
  * #5 DENORMALISASI owner_id: tabel level-2 (settlements/deliveries/kiosk_visits) tak punya
    owner_id → tiap widget owner resolve lewat whereHas('delivery.kiosk.cluster', owner_id) 3–4
    tabel. Index-backed, OK sekarang. TUNDA sampai settlement/kiosk_visit tembus ~10k baris, lalu
    denormalisasi owner_id (+kiosk_id) ke settlements + composite (owner_id, visit_date)/(owner_id, status).

## ✅ FIX UPLOAD FOTO 401 DI PRODUKSI — trust proxy Railway (2 Juli 2026, commit 5e8a032)
- KONTEKS: user info PENTING — issue upload + peta dialami di LAPTOP (DESKTOP browser), BUKAN HP.
  Selama ini salah asumsi (fokus tes mobile). Fix harus jalan desktop & mobile, di PRODUKSI ASLI.
- Setelah commit 0455b58 (fix CORS temp-disk) DEPLOY, verifikasi produksi nemu BLOCKER KEDUA:
  upload tak lagi CORS (temp disk local, hits /livewire/upload-file server) TAPI balas HTTP 401
  (body {"message":""}). Livewire FileUploadController: abort_unless(request()->hasValidSignature(), 401).
- AKAR (terbukti lokal + produksi): AppServiceProvider forceScheme('https') di produksi →
  signed-URL (temp upload) ditandatangani sebagai https. TAPI bootstrap/app.php TIDAK punya
  trustProxies → di balik proxy Railway (terminasi TLS, forward http internal) request ke-baca
  http → hasValidSignature() recompute pakai http → MISMATCH → 401. Bukti lokal deterministik:
  https-signed + request http = INVALID(401); + request https = VALID(200). Kena SEMUA signed-URL
  di produksi (desktop & mobile) — makanya user kena di laptop.
- FIX (bootstrap/app.php): $middleware->trustProxies(at: '*', headers: X_FORWARDED_FOR|HOST|PORT|
  PROTO|AWS_ELB). Skema request = https (dari X-Forwarded-Proto Railway) → tanda tangan cocok →
  upload 200. Railway satu-satunya proxy di depan app → at:'*' aman. 204 PASS.
- ⚠️ CATATAN GENERAL: semua fitur signed-URL/temporaryUrl di produksi bergantung ke trustProxies
  ini. Kalau ada yang otak-atik middleware, JANGAN buang trustProxies.
- VERIFIKASI PRODUKSI ASLI (https://dodol-app-production..., login owner+operator, DESKTOP 1280px
  + MOBILE 360px): upload /livewire/upload-file 200 + "Upload complete" (owner), no CORS, no
  direct-R2, keempat kombinasi. END-TO-END: buat kios test + foto → mendarat pub-*.r2.dev HTTP 200
  → foto ke-load → kios test DIHAPUS (bersih, 0 baris tersisa). Peta produksi: config Carto Voyager
  + maxZoom 20 + detectRetina false (dibaca dari HTML prod), hard-zoom ke max coverage 100% no grey,
  desktop+mobile owner+operator.

## ✅ FIX UPLOAD FOTO KIOS GAGAL DI PRODUKSI — CORS temp-upload R2 (1 Juli 2026, commit 0455b58)
- GEJALA (screenshot user, HP asli): form Create Kios OWNER (Filament) → field "Foto Kios" →
  pilih foto WhatsApp 134 KB (kecil!) → merah "Error during upload / tap to retry" +
  "The data.photo_path.[uuid] failed to upload". BLOCKER — user ga bisa input kios.
- AKAR (terbukti via reproduksi bertingkat, BUKAN ukuran): CORS. Di PRODUKSI FILESYSTEM_DISK=s3
  → disk default = R2. Livewire pakai disk default utk TEMPORARY upload. Begitu temp disk = disk
  's3', Livewire beralih ke mode presigned PUT LANGSUNG dari browser ke R2. R2 tanpa CORS policy
  → browser BLOKIR PUT (net::ERR_FAILED / "blocked by CORS") → "failed to upload". Size-independent
  (repro file 3 KB gagal sama seperti 134 KB). Reproduksi: FILESYSTEM_DISK=local → upload-file 200 OK;
  FILESYSTEM_DISK=s3 → CORS block (repro); s3 + fix → 200 OK.
- KENAPA verifikasi foto operator kemarin lolos: tes jalan di server LOKAL (FILESYSTEM_DISK=local)
  → temp upload masuk server dulu, lalu simpan R2 server-side (tanpa CORS). Produksi (s3) memicu
  jalur browser→R2 yang kena CORS. Pola sama dgn columnSpan: kondisi tes ≠ kondisi HP asli.
- FIX: config/livewire.php (BARU, di-publish) → temporary_file_upload.disk = 'local' (JANGAN ikut
  FILESYSTEM_DISK). Temp upload masuk server dulu (tanpa CORS), file FINAL tetap ke R2 server-side
  (config('app.media_disk')). Membenerkan owner (Filament) DAN operator (Livewire) sekaligus. Aman
  apa pun nilai FILESYSTEM_DISK di produksi. Alternatif "set CORS di bucket R2" DITOLAK (butuh infra
  R2 + upload file mentah full-size dari HP = boros data mobile).
- VERIFIKASI (FILESYSTEM_DISK=s3, mobile 360px): temp upload /livewire/upload-file 200 tanpa CORS;
  final store ke R2 exists=YES + public URL HTTP 200. 204 PASS. ⚠️ WAJIB redeploy Railway (config
  di-cache saat runtime → ke-pickup pas redeploy). Belum bisa baca env Railway langsung (no CLI),
  tapi error CORS yg ter-reproduksi PERSIS cocok dgn error user → indikasi kuat prod = FILESYSTEM_DISK=s3.

## ✅ FIX PETA GREY SAAT ZOOM KUAT — cap maxZoom 20 + Carto (1 Juli 2026, commit bad45b7)
- GEJALA (HP asli, user MASIH ngalamin walau hard-restart): peta owner grey pas ZOOM KUAT (nyari
  rumah persis). Fix invalidateSize kemarin (commit 1d713c0) nutup scroll-in TAPI TIDAK nutup zoom.
- AKAR BEDA dari fix kemarin (terbukti via hard-zoom repro): peta owner boleh zoom sampai z28
  (maxZoom default paket filament-map-picker), TAPI tile OSM cuma ada s/d z19. Zoom lewat itu →
  tile.openstreetmap.org balas HTTP 400 / tanpa tile → GREY. Bukti: hard-zoom z13→z26 → coverage
  0% (fully grey), tile statuses {"200":13,"400":4}. invalidateSize (resize) TAK bisa nolong —
  tile-nya memang tidak ada, bukan sizing. Verifikasi kemarin cuma zoom ringan (~z16) jadi tak kena.
- FIX (owner + operator KONSISTEN): (1) cap maxZoom = 20 (map & tileLayer) → user tak bisa over-zoom
  ke zona tanpa-tile; z20 = level bangunan, cukup nunjuk lokasi kios. (2) ganti OSM → Carto Voyager
  (https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}.png) — tetap GRATIS, CDN +
  subdomain a/b/c/d (tile keisi paralel → transient-grey lebih pendek), usage lebih longgar dari OSM
  single-host (anti-throttle). Native 256px + atribusi "© OpenStreetMap, © CARTO".
  * Owner (KioskResource.php): ->maxZoom(20), tilesUrl Carto, ->extraTileControl([tileSize 256,
    zoomOffset 0, maxZoom 20, attribution]).
  * Operator (create-kiosk.blade.php): L.map(...,{maxZoom:20}), Carto tileLayer subdomains 'abcd',
    maxNativeZoom 20, maxZoom 20.
- ⚠️ KOREKSI catatan "PETA OPERATOR AMAN, TIDAK DIUBAH" kemarin (di bawah): TERNYATA kurang lengkap
  utk kasus OVER-ZOOM. Operator memang tak grey pas scroll-in, TAPI utk konsistensi + benefit Carto
  (CDN/anti-throttle) + cap zoom, peta operator SEKARANG IKUT diubah (Carto + maxZoom 20). Jadi
  kedua peta kini seragam.
- VERIFIKASI (mobile 360px, throttle 4G, hard-zoom ke MAX + zoom cepat bolak-balik): owner & operator
  coverage 100% di semua tahap; hard-zoom owner 29 tile semua 200 (0×400/404), operator 30 tile semua
  200; 4 subdomain Carto paralel; 0 throttle. Screenshot at-max-zoom dua-duanya keisi tile bangunan
  asli (Jalan Sei Deli), nol grey, tombol "+" ke-disable di batas z20. 204 PASS.

## ✅ MIGRASI PETA OWNER: dotswan/filament-map-picker → LEAFLET HAND-ROLLED (6 Juli 2026)
> ⚠️ MENGGANTIKAN keputusan "tidak ada bug" di section investigasi di bawah ini. Setelah user
> jalankan console diagnostik di produksi, akar SEBENARNYA ketemu: event `kiosk-location-jumped`
> diterima, peta hidup, `getCoordinates()` benar — TAPI `refreshMap()` bawaan dotswan tak pernah
> ke-panggil (integrasi vendor black-box, bukan lagi soal deploy/cache). Keputusan FINAL: dotswan
> dicopot total, diganti Leaflet hand-rolled (pola sama persis dgn peta operator yang sudah
> terbukti stabil sejak awal — 0 bug integrasi vendor).
- KENAPA: dotswan sudah 3× jadi sumber bug kelas integrasi/lifecycle di form owner (grey-tile
  invalidateSize → commit 1d713c0; lalu refreshMap tak ke-panggil → investigasi sesi ini). Peta
  operator (Livewire + Leaflet manual, `create-kiosk.blade.php`) TAK PERNAH kena kelas bug ini
  karena kontrol penuh — `map.setView()` dipanggil LANGSUNG, tanpa refreshMap/IntersectionObserver
  pihak ketiga yang bisa diam-diam berhenti bekerja.
- ARSITEKTUR (dipilih: TERPISAH, bukan 1 komponen Blade reusable owner+operator): owner pakai
  Filament Field (`Forms\Components\Field` custom, state via `$set`/`$get`/statePath) sedangkan
  operator pakai Livewire mentah (`@this.set('latitude', ..)` dua properti scalar terpisah) — dua
  paradigma binding state yang beda secara fundamental. Memaksa 1 abstraksi bersama nambah
  kompleksitas tanpa manfaat nyata sekarang, DAN operator TIDAK PERNAH rusak (0 bug) jadi tak ada
  alasan mengubah kodenya. Duplikasi yang tersisa cuma config kecil (tile URL, zoom cap, warna
  marker) — bukan logic rawan-bug. Kalau nanti ada bug KETIGA yang sama persis di operator (belum
  pernah), baru worth disatukan.
- FILE BARU:
  * `app/Filament/Forms/Components/LeafletMapPicker.php` — Filament Field custom (extends `Field`),
    fluent API (defaultLocation/draggable/clickable/markerColor/showZoomControl/
    showFullscreenControl/showMyLocationButton/tilesUrl/attribution/zoom/maxZoom). afterStateUpdated/
    afterStateHydrated/dehydrated/columnSpanFull/label/helperText WARISAN dari `Field` base class
    (Filament sendiri, bukan ditulis ulang).
  * `resources/views/filament/forms/leaflet-map-picker.blade.php` — blade field, include
    `vendor/leaflet/leaflet.{css,js}` + `js/leaflet-map-picker.js` langsung (pola sama dgn operator,
    BUKAN lewat FilamentAsset::register — hindari ketergantungan pada `php artisan filament:assets`
    publish step, persis kelas isu yang baru saja diinvestigasi utk dotswan).
  * `public/js/leaflet-map-picker.js` — factory Alpine `leafletMapPicker($wire, config)`. Klik
    "Loncat" → listener `window.addEventListener('kiosk-location-jumped', ...)` LANGSUNG panggil
    `map.setView()` — TANPA scrollIntoView/500ms delay/refreshMap perantara. Ganti workaround
    IntersectionObserver dotswan (scan 500ms + scroll listener + dispatch resize bertahap) dengan
    `ResizeObserver` bawaan browser (invalidateSize tiap ukuran container BENERAN berubah — section
    Lokasi dibuka/ditutup, layout mobile settle). GPS button + fullscreen custom Leaflet control
    (bukan plugin `leaflet-fullscreen` dotswan — cukup Fullscreen API native + toggle sendiri).
  * BUG DITEMUKAN & DIFIX saat verifikasi headed Chrome: `L.map()` bisa kepanggil 2x pada container
    DOM yang SAMA (Livewire commit-ulang sesaat setelah load awal memicu `x-init` dobel walau node
    DOM-nya tak berubah — `wire:ignore` melindungi children dari morph, BUKAN dari re-run x-init
    Alpine). FIX: guard `if (el._leaflet_id) return;` di awal `init()` — panggilan kedua no-op,
    instance PERTAMA (yang sudah pegang listener asli) tetap satu-satunya map hidup.
- DIHAPUS: `vendor/dotswan/filament-map-picker` (`composer remove`, composer.json/lock bersih),
  `public/{css,js}/dotswan/**` (generated, sudah orphan), `resources/views/filament/forms/
  map-invalidate-size.blade.php` (workaround IntersectionObserver, tak dibutuhkan lagi), baris
  `View::make('filament.forms.map-invalidate-size')` di KioskResource, komentar dotswan-specific di
  `AppServiceProvider::fixFilamentMapPickerZIndex()` (CSS z-index-nya TETAP — masih relevan utk
  Leaflet native), regex `/js/dotswan/` mati di `public/sw.js` (diganti eksplisit
  `/js/leaflet-map-picker.js` masuk kebijakan NETWORK-FIRST yang sama dgn aset Filament — file ini
  bukan hasil Vite/tak ber-hash, jadi rawan basi kalau cache-first).
- TEST BARU: `tests/Feature/Owner/KioskMapJumpTest.php` — bukti lat/lng yang TERSIMPAN ke DB benar
  (bukan cuma peta bergerak di browser) setelah tombol "Loncat" dgn koordinat asli Sippin Milk&Tea
  (3.6157078, 98.6758666), termasuk assert eksplisit latitude≠longitude tertukar. 270 PASS total
  (269 lama + 1 baru), 0 regresi.
- VERIFIKASI HEADED CHROME ASLI (bukan headless, localhost — playwright-core + system Chrome/Edge,
  skrip ad-hoc di scratchpad di luar repo):
  * CREATE: paste link → jump → pin pindah TEPAT ke target (3.6157046, 98.6758733 vs target
    3.6157078, 98.6758666). Klik di peta → lat/lng ter-set sesuai titik klik. Drag marker → update
    lat/lng sesuai posisi baru (beda dari sebelum drag, dibuktikan eksplisit). Tombol GPS (geolocation
    di-mock via Playwright context) → pindah PERSIS ke koordinat mock. Zoom 10× tombol "+" → mentok
    di z20 (maxZoom cap jalan), 0 tile response error (grey-tile TIDAK regresi).
  * EDIT kiosk existing (id=1, lat/lng lama 3.5961349/98.6810119, section Lokasi di luar viewport
    saat load — skenario PERSIS laporan awal user): peta ter-render correctly di koordinat
    TERSIMPAN saat load (3.596174, 98.681002 ≈ match).
  * Screenshot max-zoom: tile penuh warna (nol grey) + kontrol custom fullscreen (kiri-atas) & GPS
    (kanan-atas) ke-render benar dgn ikon SVG masing-masing.
- TODO USER: verifikasi produksi (incognito, biar pasti bukan cache) — buka form owner Kios, paste
  link `maps.app.goo.gl` ASLI, klik "Loncat ke lokasi", pastikan pin BERGERAK.

## ⚠️ INVESTIGASI SEBELUMNYA "PETA OWNER TAK GERAK" — DIGANTIKAN, lihat "MIGRASI PETA OWNER" di atas
> Disimpan sbg histori: kesimpulan "tidak ada bug" di bawah ini TERNYATA KELIRU — akar sebenarnya
> baru ketemu setelah user jalankan diagnostik produksi (lihat section di atas).
- LAPORAN USER: link Google Maps di-tempel di form owner (KioskResource) → notif "Lokasi
  ditemukan!" muncul (server resolve OK) tapi pin peta di sisi owner TIDAK BERGERAK SAMA
  SEKALI, baik sebelum maupun sesudah klik "Loncat ke lokasi". Hipotesis awal: rantai
  event `kiosk-location-jumped` → `map-invalidate-size.blade.php` → `refreshMap` dotswan
  putus (selector/markup vendor beda, `this.map` null saat `flyTo`, atau race timing).
- INVESTIGASI (headed Chromium ASLI via playwright-core, BUKAN headless — instruksi user
  eksplisit karena headless punya timing IntersectionObserver beda): direpro 2x dengan kode
  persis di HEAD (create kiosk kosong, DAN edit kiosk #1 existing dgn lat/lng lama jauh dari
  target, section Lokasi di luar viewport saat load — skenario paling dekat ke laporan user).
  HASIL: `this.map` memang null sebelum interaksi (section di luar viewport → IntersectionObserver
  vendor sudah membongkarnya, sesuai desain fix 1d713c0), TAPI begitu tombol "Loncat" diklik:
  event diterima → `scrollIntoView` → observer membangun ulang `this.map` dengan koordinat yang
  SUDAH ter-`$set` → peta langsung center TEPAT di koordinat target. Screenshot before/after
  membuktikan pin pindah persis (3.6157078, 98.6758666), notif "Lokasi ditemukan!" tampil. Kode
  di `main` (HEAD 4785acb) SUDAH BENAR — kandidat bug (selector salah/this.map null saat flyTo/
  race 500ms/event tak sampai) SEMUA TERBANTAH oleh repro langsung.
- CEK DEPLOY (hipotesis lanjutan: asset build basi di Railway): TERBANTAH JUGA. `public/js/
  dotswan/filament-map-picker/filament-map-picker-scripts.js` (berisi seluruh logic mapPicker/
  refreshMap) di-commit LANGSUNG ke git (bukan hasil `npm run build`/Vite — `.dockerignore`
  cuma exclude `public/build`), jadi ikut ter-`COPY . /app` di Dockerfile apa pun status build
  Vite. Diverifikasi via `curl` ke asset statis produksi (tak perlu login, murni file publik):
  MD5 produksi vs lokal **identik byte-for-byte**, `Last-Modified` produksi (2026-07-06 08:39:28
  UTC = 15:39:28 WIB) pas beberapa detik setelah commit HEAD lokal (15:39:02 WIB) — produksi
  SUDAH redeploy dengan kode paling baru. Kesimpulan: pipeline Railway (Dockerfile builder,
  `npm run build` fresh tiap deploy di stage terpisah + `COPY . /app`) TIDAK bermasalah untuk
  file ini; tak perlu perubahan pipeline.
- KESIMPULAN: tidak ada fix kode yang dieksekusi (tidak ada bug ditemukan). Laporan user
  kemungkinan besar dari SEBELUM fix 1d713c0/47ed5ac ter-deploy hari ini (semua commit terkait
  maps ada di tanggal yang sama). TODO USER: re-test di produksi SEKARANG (reload biasa, tak
  perlu clear cache lagi — asset produksi sudah dikonfirmasi terbaru) dan konfirmasi apakah
  pin sudah bergerak.
- REKOMENDASI ARSITEKTUR (belum dieksekusi, perlu keputusan user): dotswan/filament-map-picker
  sudah 2× jadi sumber bug kelas timing/lifecycle di form owner (grey-tile 1d713c0, lalu
  investigasi ini). Peta operator (Livewire + Leaflet hand-rolled, create-kiosk.blade.php)
  TAK PERNAH kena kelas bug ini karena kontrol penuh (tak ada IntersectionObserver pihak
  ketiga yang bongkar-pasang peta). Opsi: ganti Map::make (dotswan) di KioskResource jadi
  Leaflet hand-rolled seragam dgn operator. Trade-off: effort medium (perlu port draggable
  marker + GPS button + fullscreen + zoom cap + fix grey-tile yang sudah ada ke implementasi
  baru, ~150-250 baris, retest penuh) vs manfaat (hilangkan seluruh kelas bug integrasi vendor
  black-box, kontrol penuh sama seperti operator yang terbukti stabil). TIDAK mendesak karena
  mekanisme dotswan SEKARANG terbukti jalan — cocok jadi item cleanup/hardening sesi depan,
  bukan hotfix.

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
> ⚠️ SEBAGIAN DISUSUL commit bad45b7: benar "tak grey pas scroll-in", TAPI kasus OVER-ZOOM
> belum tercakup. Peta operator KINI diubah juga (Carto + cap maxZoom 20). Lihat section
> "FIX PETA GREY SAAT ZOOM KUAT" di atas.
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

## SERAH TERIMA 3-AKSI (12 Juli 2026) — SELESAI & PUSHED
**File inti:** `app/Livewire/Operator/ActiveTrip.php` + `resources/views/livewire/operator/active-trip.blade.php`
**Flag kios lama:** `app/Support/OpeningBalance.php` (baru) + `KioskResource` (toggle "Kios lama") + `CreateKiosk::afterCreate()`

### 3 AKSI (operator pilih SATU per kunjungan) — `chosenAction`
- **AKSI 1** `tagih_titip` (ada titipan) / `titip` (belum ada) → drop_and_settle / drop_only.
  Input: BS (biji), sisa bagus (biji), titip ulang (mika, default=jatah), uang diterima
  (default=tagihan, boleh override→piutang). Tagihan = (titipan_biji − sisa_bagus − BS) × 800.
- **AKSI 2** `titip_cash` → `cash_sale` (cabang khusus di `persistVisitFromState`): X mika lunas,
  pending lama TAK disentuh (self-settle di delivery cash), jatah tetap. Komisi & omset kehitung.
- **AKSI 3** `cek` → check_only: sisa biji + catatan bebas (`janjiBayar` dipakai ganda: "belum bisa
  bayar" → "Janji bayar: X", alasan lain → catatan apa adanya). 0 transaksi.

### BLOKIR 2-LANGKAH (anti angka-nyasar)
- Rule: AKSI 1, `defaultQty >= 1 && !ubahJatah && drop !== defaultQty` → tolak. Dua lapis: blade
  (tombol `$saveDisabled` + kotak merah) + server (`persistVisitFromState` return null + addError).
- Exempt: `$isCorrection` (correctVisit lolos blokir), kios baru `defaultQty < 1` (netapkan baseline).
- Ubah-jatah SATU-ANGKA: `$applyJatahDrop` (AKSI 1 → default=$drop), `$applyJatahCek` (AKSI 3 →
  default=$jatahBaru). Field `jatahBaru` HANYA dipakai AKSI 3. Auto-peek lama DIHAPUS.

### FLAG KIOS LAMA vs BARU — TANPA KOLOM BARU (keputusan owner)
- "Punya titipan aktif" = ada Delivery pending (`doesntHave('settlement')`) = `$pendingDelivery`.
- Kios lama (migrasi): `OpeningBalance::create($kiosk, $mika)` → 1 delivery konsinyasi pending
  (idempoten; trip migrasi per-owner `firstOrCreate`; first_titip_date di masa lampau biar bukan
  kios-baru komisi). Trigger: toggle "Kios lama" + field mika di Filament create (form-only,
  `dehydrated(false)`). Massal: command `kios:saldo-awal` (sudah ada). **Backfill 9 kios owner
  existing: jalankan `php artisan kios:saldo-awal <file> --owner=<id>` ATAU re-input via toggle —
  verifikasi di PRODUKSI (butuh data DB prod; belum dijalankan di sini).**

### MODAL BS = 633/biji — SUDAH BENAR (tak ada perubahan)
- `OwnerDashboardController` kerugian write-off = mika × `hpp_per_mika` (9.500/mika = 633/biji).
  800/biji itu harga JUAL, bukan modal (brief owner keliru di titik ini). BS input sudah per biji.

### TEST
- 311 PASS (baseline 296). Rewrite: UbahJatahCashTest, ActiveTripAdvancedScenarioTest,
  CashDeliveryTest, KomisiDropBasisTest, ActiveTripActionPickerTest, CorrectVisit{,Ui}Test (setUp
  jatah→null). Baru: `SerahTerima3AksiTest` (AKSI 3, flag kios lama/idempoten, blokir UI, kios baru).

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
