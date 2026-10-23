# UI Inventory & Buku Panduan — dodol-app

> Audit READ-ONLY dari codebase. Semua klaim disertai sitasi `file:baris`. Teks UI (label tombol, judul, pesan, badge) dikutip **PERSIS** dari source — tidak diparafrase.
> Tanggal audit: 2026-10-20.

---

## A. OPERATOR (layar HP)

### A1. Login

- Form login (`resources/views/livewire/pages/auth/login.blade.php:52-69`): field **Email** (`type="email"`, placeholder `"Masukkan email anda..."`), field **Password** (`type="password"`, placeholder `"••••••••"`), checkbox **"Ingat Saya"** (default dicentang — `App\Livewire\Forms\LoginForm.php:24` komentar: "Default true: app internal operator → login persist (tak logout tiap tutup app)").
- Tombol submit: **"Masuk ke Panel"** (`login.blade.php:73`).
- Judul halaman: **"Cemilan Qontas"** / subjudul **"Sistem Distribusi Dodol"** (`login.blade.php:43-44`).
- Email dinormalisasi (trim + lowercase) sebelum validasi dan sebelum attempt — mengatasi spasi dari autocomplete HP (`LoginForm.php:35-41`, `login.blade.php:18-22`). Password **tidak** dinormalisasi (tetap case-sensitive apa adanya).
- **Pesan gagal login** (email tidak terdaftar ATAU password salah — sengaja satu pesan untuk keduanya, tidak membocorkan email mana yang terdaftar): `"Email atau password salah."` (`LoginForm.php:50`).
- **Pesan akun dinonaktifkan** (kredensial benar tapi `is_active=false`): `"Akun Anda dinonaktifkan. Hubungi admin."` (`LoginForm.php:60`).
- **Pesan owner operator dinonaktifkan** (operator yang owner-nya nonaktif — ikut terkunci): `"Akun owner Anda dinonaktifkan. Hubungi admin."` (`LoginForm.php:72`).
- **Pesan rate limit** (>5 percobaan gagal): `"Terlalu banyak percobaan login. Coba lagi dalam {n} detik."` (`LoginForm.php:93`).
- Guard di tiap request terproteksi (`app/Http/Middleware/EnsureUserHasRole.php`), bukan hanya saat login — berlaku juga untuk sesi yang sudah berjalan:
  - Akun nonaktif di tengah sesi → logout otomatis + redirect ke login dengan status: `"Akun nonaktif. Hubungi owner."` (`EnsureUserHasRole.php:26`).
  - Owner operator dinonaktifkan di tengah sesi → logout otomatis + status: `"Akun owner Anda dinonaktifkan. Hubungi admin."` (`EnsureUserHasRole.php:37`).
  - Role tidak sesuai → HTTP 403 dengan pesan: `"Akses ditolak. Role tidak sesuai."` (`EnsureUserHasRole.php:41`).
- Setelah login sukses: session di-regenerate, lalu **full reload** (bukan `wire:navigate`) untuk membersihkan snapshot bfcache lintas akun multi-tenant (`login.blade.php:30-32`).

### A2. Dashboard / Beranda

File: `app/Livewire/Operator/Dashboard.php` + `resources/views/livewire/operator/dashboard.blade.php`.

- Judul sapaan: **"Halo, {nama user}"** dengan tanggal format Indonesia, mis. "Senin, 20 Oktober 2026" (`dashboard.blade.php:4-5`).
- Dua kartu statistik berdampingan:
  - **"Trip Hari Ini"** — jumlah trip yang dibuat operator hari ini (`$tripsToday`, menghitung SEMUA trip hari ini milik operator, termasuk yang sudah diakhiri) (`dashboard.blade.php:11-12`, `Dashboard.php:32-34`).
  - **"Kios Dikunjungi"** — jumlah kunjungan kios aktif (`KioskVisit::active()`) hari ini lintas trip operator tersebut (`dashboard.blade.php:15-16`, `Dashboard.php:36-39`).
- Tombol CTA besar di bawah, kondisional:
  - Jika ADA trip aktif (`started_at` terisi, `ended_at` masih null, hari ini): tombol hijau **"Lanjutkan Trip Aktif"** dengan sub-teks **"Trip #{nomor} hari ini"**, mengarah ke `operator.trip.active` (`dashboard.blade.php:21-31`).
  - Jika TIDAK ada trip aktif: tombol kuning/amber **"Mulai Trip"** dengan sub-teks **"Tap untuk mulai ngantar"**, mengarah ke `operator.trip.start` (`dashboard.blade.php:32-42`).

### A3. Mulai Trip

File: `app/Livewire/Operator/StartTrip.php` + `resources/views/livewire/operator/start-trip.blade.php`.

- Header: link **"Kembali ke Dashboard"**, judul **"Mulai Trip"**, subjudul dinamis: **"Ada trip yang masih berjalan"** (jika ada trip aktif) atau **"Pilih area awal yang akan dikunjungi"** (`start-trip.blade.php:14-23`).
- Banner offline (tampil saat `wire:offline`, berlaku di semua halaman operator yang disentuh audit ini): **"Koneksi internet terputus. Periksa sinyal, lalu coba lagi."**

**Kartu "trip masih berjalan"** (`start-trip.blade.php:32-86`) — ditampilkan SEBAGAI PENGGANTI redirect diam-diam (lihat komentar besar di `StartTrip.php:30-46`: dulu operator pilih area A lalu ditendang ke trip lain tanpa penjelasan — sekarang operator harus lihat & putuskan sendiri):
- Judul kartu: **"Kamu masih punya trip berjalan"**.
- Ringkasan: **"Trip #{nomor} — {nama area / 'Semua Kios'}, mulai {jam}, bawa {n} mika."**
- Tombol **"Lanjutkan Trip →"** — ke trip aktif tersebut.
- Tombol **"Batalkan Trip Ini"** HANYA muncul jika `canCancelActiveTrip()` true (lihat syarat di bawah). Konfirmasi `wire:confirm`: **"Batalkan Trip #{nomor}? Trip ini belum ada kunjungan sama sekali, jadi akan DIHAPUS dan nomornya bisa dipakai lagi. Tidak bisa dibatalkan."** Teks bantuan di bawah tombol: **"Aman: trip ini belum ada kunjungan sama sekali. Trip dihapus dan nomornya dipakai ulang, jadi penomoran tidak melompat."**
- Jika TIDAK bisa dibatalkan (sudah ada aktivitas), teks: **"Trip ini sudah ada aktivitas, jadi tidak bisa dibatalkan. Kalau memang mau berhenti, buka tripnya lalu pakai Akhiri Trip supaya stok & komisi ikut dibukukan."** (`start-trip.blade.php:78-83`).
- Jika percobaan "Mulai Trip" ditekan padahal trip lain masih berjalan: banner merah dengan pesan (`StartTrip.php:322-323`): **"Kamu sudah punya trip berjalan ({area}). Trip baru TIDAK dibuat. Lanjutkan trip itu, atau batalkan dulu kalau salah pilih."**
- Pesan sukses setelah batal (`StartTrip.php:164-165`): **"Trip #{nomor} dibatalkan. Silakan mulai trip baru dengan area yang benar."**

**Kondisi "Batalkan Trip" (canCancelActiveTrip)** — `StartTrip.php:100-112`: trip boleh dibatalkan **hanya jika benar-benar kosong**: 0 kunjungan (`visits`), 0 pengantaran (`deliveries`), 0 komisi (`commissions`). Begitu salah satu ada isinya, satu-satunya jalan adalah membuka trip dan memakai "Akhiri Trip". Pembatalan = **hapus permanen** (`forceDelete`), BUKAN arsip (keputusan owner 30 Juli 2026 — arsip akan tetap memegang nomor trip di index unik dan membuat penomoran melompat).

Form "Mulai Trip" (ketika tidak ada trip aktif):
- Toggle **"Trip Bebas (Semua Kios, Lintas Area)"** — antar lintas cluster tanpa pilih area (`start-trip.blade.php:90-98`).
- Jika bukan Trip Bebas: daftar kartu **area/cluster** yang bisa dipilih, masing-masing menampilkan nama area, jumlah kios aktif (**"{n} kios aktif"**), dot warna urgensi (merah=high/overdue, kuning=medium/warning, hijau=low, abu=empty, cyan=unknown/never), dan pesan urgensi gabungan, contoh: **"{n} telat dikunjungi, {n} hampir telat, {n} belum pernah dikunjungi"** atau **"Semua kios aman"** (`StartTrip.php:254-266`). Jika area kosong: **"Belum ada kios"**.
- Jika tidak ada area aktif sama sekali: **"Belum ada area aktif"** / **"Hubungi owner untuk setup area dulu"** (`start-trip.blade.php:149-150`).
- Error validasi pilih area: **"Pilih area dulu"** / **"Area tidak valid"** (`StartTrip.php:296-297`).
- Input **"Berapa mika yang kamu bawa hari ini?"** (angka, min 0). Error: **"Isi jumlah mika dulu"** (required/min) (`StartTrip.php:298-299`).
- Tombol submit teks dinamis: **"Pilih area dulu"** (disabled, belum pilih area) → **"Isi jumlah mika dulu"** (disabled, qty<1) → **"Mulai Trip Sekarang →"** (aktif) → **"Memproses Trip..."** (loading) (`start-trip.blade.php:196-213`).
- Pesan gagal total (`StartTrip.php:384-385`): **"Trip gagal dibuat. Coba tekan Mulai Trip sekali lagi; kalau tetap gagal, hubungi owner."**
- Penomoran trip harian **unik per OWNER** (lintas semua operator owner itu), bukan per operator (`StartTrip.php:328-348`).

### A4. Layar trip aktif

File: `app/Livewire/Operator/ActiveTrip.php` + `resources/views/livewire/operator/active-trip.blade.php`.

**Header** (`active-trip.blade.php:51-77`):
- Judul: **"Trip #{nomor}"**.
- **"Area awal: {nama area}"** atau **"Area awal: Semua Kios"** (jika Trip Bebas) — ini adalah catatan ASAL, tidak berubah selama trip berjalan.
- Jika operator sedang melihat area LAIN dari area awal: baris tambahan **"👀 Sedang lihat: {nama area yang dilihat}"** — supaya perpindahan area tidak senyap.
- Badge status: **"Aktif"** (hijau) + jam mulai trip (format `H:i`).

**Lihat Area Lain / switching area** (`active-trip.blade.php:81-126`): tombol **"Lihat Area Lain"** membuka/menutup picker (menampilkan nama area yang sedang dilihat di sisi kanan tombol). Saat terbuka: teks **"Pilih area — trip tetap Trip #{nomor}, tidak diakhiri."**, opsi **"Semua Area"** (sub-teks "Semua kedai, dikelompokkan per area") lalu daftar tiap area (dengan tanda **"· area awal"** pada area yang jadi titik start trip, dan jumlah **"{n} kios aktif"**). Pemilihan EKSPLISIT lewat menu ini — bukan via "Urutkan Jarak" (karena cakupan GPS kios baru ~12%).

**Tombol aksi di atas daftar** (`active-trip.blade.php:133-161`):
- Judul section: **"DAFTAR KUNJUNGAN"** (`Daftar Kunjungan`, uppercase via CSS).
- Tombol **"Kios Baru"** → ke form tambah kios.
- Tombol **"Urutkan Jarak"** (toggle ke **"Terdekat Dulu"** saat aktif, atau **"Mencari…"** saat loading GPS) — mengurutkan kios berdasar jarak GPS.

**Search** (`active-trip.blade.php:163-184`): input `search` dengan placeholder **"Cari nama kios…"**, debounce 400ms. Jika hasil terpotong: **"Menampilkan {n} dari {total} kios{' yang cocok' jika sedang search}. Tekan Muat lebih banyak di bawah untuk sisanya."**

**Divider/judul per grup** (`active-trip.blade.php:187-199`): tiap grup area/GPS dipisah garis dengan label grup (`$group['label']`) dan catatan kecil (`$group['note']`) di sisi kanan label — supaya batas area/pengelompokan TERLIHAT, bukan senyap.

**Load more** (`active-trip.blade.php:304-332`):
- Jika daftar kosong karena search: **"Tidak ada kios cocok dengan "{query}"."** / **"Coba kata kunci lain atau kosongkan pencarian."**
- Jika daftar kosong bukan karena search: **"Belum ada kios di area ini."** / **"Coba Lihat Area Lain, atau tambah kios via menu Kios Baru."**
- Jika masih ada sisa kios: tombol **"Muat lebih banyak ({sisa} kios lagi)"** (loading: **"Memuat…"**), dimuat per-batch (`$displayLimit`), TIDAK pernah dipotong diam-diam (komentar eksplisit soal "bug berhenti di Bilal 3").
- Jika semua sudah tampil & tidak sedang search: **"— Semua {total} kios di daftar ini sudah tampil —"**.

**Bottom bar tetap (sticky)** (`active-trip.blade.php:335-350`):
- Tombol kuning **"Jual Cash Cepat"** — penjualan ke pembeli walk-in (bukan kios terdaftar), membuka modal `openWalkInModal`.
- Tombol merah **"Akhiri Trip"** (loading: **"Memuat..."**) — entry point ke modal Akhiri Trip (detail lihat A10).

**Modal "Jual Cash Cepat"** (`active-trip.blade.php:352-400`, ringkas — detail penuh ada di A6/A9 jika relevan):
- Judul modal: **"Jual Cash Cepat"**, sub-teks: **"Penjualan langsung ke pembeli (bukan kios titipan)."**
- Input **"Jumlah Mika"** dengan stepper +/-.
- Ringkasan otomatis: **"Total Harga (Lunas Cash)"** = mika × harga per mika varian AKTIF (bukan konstanta global), dengan rincian **"{n} mika × {units_per_pack} biji × Rp {harga per biji}"**.

### A5. Kartu kedai (kios)

Tiap baris kios di daftar (`active-trip.blade.php:210-300`) menampilkan: nama kios, nama pemilik (atau "—"), dan — jika daftar menjangkau lebih dari satu area — label area tambahan dipisah "·".

**Badge status kunjungan** (salah satu, saling eksklusif):
- `✓ Dikunjungi` (hijau) — kios sudah dikunjungi di trip ini (`$isVisited`).
- `⚠️ Sudah diantar {nama operator} hari ini {jam}` (kuning tegas) — kios sudah diantar TRIP LAIN hari ini (operator lain, atau operator sama di trip kedua), dan belum dikunjungi di trip SEKARANG.
- `Belum` (amber) — default, belum dikunjungi dan tidak ada trip lain hari ini.

**Badge koreksi** (tambahan, bisa muncul berdampingan): `✎ Dikoreksi` (abu) — jika kios ini sudah pernah dikoreksi di trip ini.

**Badge titipan** (satu dari empat kondisi, saling eksklusif, menggantikan tempat badge lama "Ada Titipan"):
- `💵 Cash Only` (kuning tegas) — kios bertipe cash-only.
- `{n} mika` (hijau tegas) — ada titipan berjalan dengan jumlah mika > 0 — angka mika AKTUAL menggantikan badge generik lama.
- `Ada Titipan` (biru) — titipan ada tapi qty tercatat 0 (data lama).
- `📌 Belum pernah dititip` (biru langit/sky, tegas) — kios tipe "booking" (`isBooking()` = belum pernah dikonsinyasi & bukan cash-only).

**Smart Kios Flags** (badge tambahan, bisa lebih dari satu muncul bersamaan):
- `🔴 URGENT` (merah tegas) — flag `urgent`.
- `⚠️ Hampir Telat` (amber) — flag `warning` (hanya jika bukan urgent).
- `⚡ Laku Cepat` (hijau) — flag `fast_mover`.
- `🐢 Laku Lambat` (biru) — flag `slow_mover`.
- `⭐ Kios Baru` (ungu) — flag `new`.

**Baris operator terakhir** (`active-trip.blade.php:279-283`) — tampil jika data `lastOperatorPerKiosk` tersedia untuk kios itu:
`👤 {nama operator} • {label waktu}`

**Tombol aksi di kartu**:
- Jika BELUM dikunjungi: seluruh kartu bisa ditekan (`wire:click="openVisitModal"`) dengan chevron `›` di kanan.
- Jika SUDAH dikunjungi: kartu jadi non-klik (opacity 50%, cursor default), muncul tombol kecil **"Koreksi"** di kanan untuk membuka modal koreksi kunjungan (`openCorrectionModal`).

---

### A6. Modal kunjungan kedai

- Saat koneksi putus, layar trip menampilkan banner: **“Koneksi internet terputus. Periksa sinyal, lalu coba lagi.”** (resources/views/livewire/operator/active-trip.blade.php:46-49)
- Modal berjudul sesuai nama kios, memakai subjudul **“Form Serah Terima”**, dan bila kios cash-only menampilkan badge **“CASH ONLY”**. (resources/views/livewire/operator/active-trip.blade.php:588-597)
- Foto kios bisa ditambah/diganti lewat **“Foto Ulang”**, **“🖼️ Galeri”**, **“📷 Ambil Foto”**, atau **“🖼️ Dari Galeri”**; saat diproses label berubah menjadi **“Menyimpan…”**. (resources/views/livewire/operator/active-trip.blade.php:607-651)
- Status di bagian atas modal tampil sebagai salah satu dari: **“Kedai ini: ada titipan … mika (… biji).”**, **“Kedai ini: cash-only (tak ada titipan).”**, atau **“Kedai ini: belum ada titipan berjalan.”** (resources/views/livewire/operator/active-trip.blade.php:663-678)
- Bila ada catatan, judulnya **“📌 Catatan Kedai”** dan isi `store_note` ditampilkan apa adanya. (resources/views/livewire/operator/active-trip.blade.php:681-687)
- Di layar pertama (sebelum aksi dipilih), bila kios punya koordinat tersimpan, muncul tautan **“Navigasi ke Kios”** yang membuka Google Maps ke titik tersebut. Bila kios **belum punya koordinat**, tombol abu **“Lokasi belum diisi”** menggantikannya (ditambahkan 21 Okt 2026, fix keluhan lapangan — sebelumnya tombol navigasi hilang diam-diam dan operator mengira itu bug). Tombol ini membuka toggle Alpine.js (`x-data="{ showInfo: false }"`), bukan form — operator tidak punya rute untuk mengedit lokasi kios sendiri. Penjelasan yang tampil: **“Kios ini belum punya titik lokasi tersimpan, jadi tombol navigasi tidak muncul. Minta owner melengkapinya lewat menu Kios (panel owner) — tandai pin di peta atau tempel link Google Maps. Kios baru yang kamu daftarkan sendiri lewat ‘Kios Baru’ otomatis diminta lokasi GPS saat itu juga.”** (resources/views/livewire/operator/active-trip.blade.php:852-892)

**Banner dan pelunasan sebelum memilih aksi**

- Bila ada piutang, banner menampilkan **“💰 Piutang lama: Rp …”**, tombol **“Terima Pembayaran”**, input ber-placeholder **“Jumlah diterima (Rp)”**, tombol **“Konfirmasi”**, dan keterangan **“Pelunasan utang lama — tidak masuk omzet/komisi hari ini.”** (resources/views/livewire/operator/active-trip.blade.php:690-715)
- Pembayaran harus lebih dari 0 dan tidak boleh melebihi outstanding; pesan blocking persisnya **“Tidak ada piutang untuk kios ini.”**, **“Jumlah pembayaran harus lebih dari 0.”**, atau **“Melebihi sisa piutang (Rp …).”** Setelah berhasil, toast-nya **“Pembayaran piutang dicatat. Sisa: Rp …”** (app/Livewire/Operator/ActiveTrip.php:972-1019)
- Jika ada titipan lama, ringkasan menampilkan **“Titipan sebelumnya”** dan **“… mika (… biji)”**. Jika pernah ditunda, banner berbunyi **“⏳ Titipan belum tertagih: … mika.”** diikuti janji bayar bila ada. (resources/views/livewire/operator/active-trip.blade.php:867-883)
- Jika titipan baru ditaruh trip lain pada hari yang sama, modal memblokir penagihan dengan teks **“🔒 Kedai ini sudah diantar … hari ini jam …. Titipannya baru ditaruh, belum bisa ditagih. Pilih Titip Cash kalau kedai minta tambahan, atau Lewati.”**; tombol **“💰 Tagih + Titip Ulang”** dan **“🔄 Ganti ke Cash (baru)”** disembunyikan. Guard server memakai tanggal bisnis `trip_date`, bukan sekadar `created_at`. (resources/views/livewire/operator/active-trip.blade.php:885-929; app/Livewire/Operator/ActiveTrip.php:904-932,1912-1926)

**Pilihan aksi saat ada titipan berjalan**

1. **“💰 Tagih + Titip Ulang”** — keterangan **“Siklus normal. Tagih yang laku (jatah dikurangi BS), titip lagi sejumlah jatah.”** (resources/views/livewire/operator/active-trip.blade.php:897-906)
2. **“💵 Titip Cash”** — keterangan **“Naruh dodol ekstra dibayar tunai. Titipan lama & sisanya tetap, ditagih nanti pas siklus normal.”** (resources/views/livewire/operator/active-trip.blade.php:908-913)
3. **“👀 Lewati / Belum Habis”** — keterangan **“Kedai belum habis, belum mau ditambah. Cuma catat sisa, lanjut.”** (resources/views/livewire/operator/active-trip.blade.php:914-919)
4. **“🔄 Ganti ke Cash (baru)”** — keterangan **“Ambil bayaran titipan terakhir. Kedai tetap didatangi, tapi mulai sekarang beli tunai (tidak dititip lagi).”** (resources/views/livewire/operator/active-trip.blade.php:920-929)
5. Pintu terpisah **“⛔ Hentikan Kedai Ini”** membuka alur A7. (resources/views/livewire/operator/active-trip.blade.php:933-937)

**Pilihan aksi saat tidak ada titipan berjalan**

1. **“💵 Titip Cash”** — keterangan **“Naruh dodol dibayar tunai sekarang. Yang biasa dipakai kedai cash-only.”** (resources/views/livewire/operator/active-trip.blade.php:938-946)
2. **“👀 Lewati / Cek Saja”** — keterangan **“Catat kunjungan tanpa transaksi (kedai tutup, cuma mampir, dll).”** (resources/views/livewire/operator/active-trip.blade.php:947-952)
3. **“🔄 Mulai Titipan (konsinyasi)”** — keterangan **“Kedai ini mau mulai dititip dodol (sebelumnya cash aja). Isi berapa mika dititip + jatah seterusnya.”** (resources/views/livewire/operator/active-trip.blade.php:953-958)
4. Pintu terpisah **“⛔ Hentikan Kedai Ini”** tetap tersedia. (resources/views/livewire/operator/active-trip.blade.php:961-965)

**AKSI 1 — “💰 Tagih + Titip Ulang”**

- Bagian **“Titipan Sebelumnya”** memuat field **“Sisa Bagus (Biji)”**, **“Barang Sisa / BS (Biji)”** dengan keterangan **“(dodol rusak/basi yang diretur)”**, ringkasan **“Laku (Terjual):”**, **“Total Tagihan:”**, dan field **“Uang Diterima (Rp)”**. (resources/views/livewire/operator/active-trip.blade.php:1005-1047)
- Formula server: `terjual = maks(0, total biji titipan lama − sisa bagus − BS)` dan `tagihan = terjual × harga_per_biji` dari snapshot titipan lama. Jika total retur melebihi titipan, blocking-nya **“Total retur melebihi jumlah titipan sebelumnya.”**; uang negatif ditolak dengan **“Uang diterima tidak boleh negatif.”** (app/Livewire/Operator/ActiveTrip.php:1444-1455,2171-2188)
- Jika uang diterima kurang dari tagihan, muncul **“Kurang Rp … → jadi piutang.”**, field **“Janji Bayar (kapan?)”**, dan placeholder **“cth: nanti sore / besok / pengantaran berikutnya”**. (resources/views/livewire/operator/active-trip.blade.php:1049-1060)
- Bagian **“Titip Ulang (Mika)”** memakai tombol minus/plus dan menampilkan **“Jatah biasa kedai ini … mika.”** (resources/views/livewire/operator/active-trip.blade.php:1064-1078)
- Blokir dua langkah muncul bila jumlah titip berbeda dari jatah tanpa konfirmasi: **“🚫 Titip harus sama dengan jatah (…).”**, lalu arahan **“Mau ubah jatah kedai ini? Centang Ubah jatah permanen di bawah.”**, **“Mau naruh ekstra dibayar tunai? ← Ganti Aksi → pilih Titip Cash.”**, dan **“Salah ketik? Betulkan jadi ….”** (resources/views/livewire/operator/active-trip.blade.php:1080-1092)
- Guard server mengulang blocking dengan pesan **“Titip harus sama dengan jatah (…). Mau ubah jatah kedai ini? Centang \"Ubah jatah permanen\" dulu. Mau naruh ekstra dibayar tunai? Pakai aksi \"Titip Cash\". Salah ketik? Betulkan jadi ….”** (app/Livewire/Operator/ActiveTrip.php:2122-2139)
- Checkbox-nya berbunyi **“Ubah jatah permanen kedai ini (naik/turun)”** dengan keterangan **“Ganti jatah kedai ini buat seterusnya. Angka titip di atas jadi jatah baru.”** Saat dicentang, tampil **“Jatah kedai ini jadi … mika seterusnya.”** dan **“ℹ️ Tagihan hari ini tetap dari titipan LAMA — tak terpengaruh jatah baru.”** Jadi satu angka titip sekaligus menjadi jatah baru; tidak ada field jatah kedua. (resources/views/livewire/operator/active-trip.blade.php:1096-1111)
- Jika jatah baru kurang dari 1, blocking server adalah **“Jatah baru minimal 1 mika.”** (app/Livewire/Operator/ActiveTrip.php:2141-2145)
- Opsi terlipat **“⚙️ Opsi Khusus”** berisi checkbox **“Ada mika Dodol Sisa dari kios lain ikut dititip”**, field **“Jumlah mika Dodol Sisa yang ikut (mika)”**, serta ringkasan **“Total titip ke kios ini: … mika (… baru + … Dodol Sisa)”**. (resources/views/livewire/operator/active-trip.blade.php:1271-1302)

**AKSI 2 — “💵 Titip Cash”**

- Bila masih ada titipan lama, banner menegaskan **“ℹ️ Titipan lama … mika & sisanya TIDAK disentuh — ditagih nanti pas siklus normal.”** (resources/views/livewire/operator/active-trip.blade.php:1151-1154)
- Field utamanya **“Naruh Cash (Mika)”**; helper-nya **“Bebas naruh berapa saja (tak terikat jatah). Jatah kedai TETAP … mika.”** (resources/views/livewire/operator/active-trip.blade.php:1156-1163)
- Field BS bernama **“Barang Sisa / BS (Biji)”** dengan keterangan **“(biji rusak/tak layak jual — kedai tidak bayar, jadi kerugian owner)”**. Jika BS terlalu besar, tampil **“BS tidak boleh melebihi biji yang ditaruh (…).”** (resources/views/livewire/operator/active-trip.blade.php:1166-1177)
- Ringkasan read-only berisi **“Biji ditaruh”**, bila perlu **“BS (kerugian owner)”** dan **“Dibayar kedai”**, kemudian **“Cash Diterima”**. Formula yang ditampilkan adalah **“(… − …) biji × Rp …. Jatah kedai TETAP … mika.”**; server menghitung `(biji ditaruh − BS) × harga per biji` dari varian aktif, sementara jumlah mika penuh tetap menjadi delivery. (resources/views/livewire/operator/active-trip.blade.php:1180-1205; app/Livewire/Operator/ActiveTrip.php:2000-2039)
- Blocking server persisnya **“Jumlah mika cash harus lebih dari 0.”** dan **“BS (biji) tidak boleh melebihi biji yang ditaruh (…).”** (app/Livewire/Operator/ActiveTrip.php:2013-2035)
- Pesan suksesnya **“Titip cash berhasil disimpan.”** atau **“Titip cash disimpan. Cash Rp … (… biji BS jadi kerugian owner).”** (app/Livewire/Operator/ActiveTrip.php:2096-2098)

**AKSI 3 — “👀 Lewati / Belum Habis”**

- Field **“Alasan Kunjungan”** menyediakan **“🔒 Kios Tutup”**, **“⏳ Minta Tunggu”**, **“📦 Dodol Masih Ada”**, dan hanya bila ada titipan, **“💰 Belum Bisa Bayar”**. (resources/views/livewire/operator/active-trip.blade.php:1209-1232)
- Untuk **“💰 Belum Bisa Bayar”**, muncul field **“Janji Bayar (kapan?)”**, placeholder **“cth: nanti sore / besok / pengantaran berikutnya”**, dan keterangan **“Titipan TETAP tercatat (belum lunas) — ditagih di kunjungan berikutnya.”** (resources/views/livewire/operator/active-trip.blade.php:1235-1245)
- Field berikutnya **“Sisa Dodol di Kedai (Biji)”** ber-placeholder **“0”** dan helper **“Isi 0 kalau tidak tahu atau kedai tutup. Data ini dipakai buat perbaikan sistem ke depan.”** (resources/views/livewire/operator/active-trip.blade.php:1247-1251)
- Untuk alasan selain belum-bisa-bayar, tersedia **“Catatan (opsional)”** dengan placeholder **“cth: pemilik lagi sepi, minggu depan mampir lagi”**. (resources/views/livewire/operator/active-trip.blade.php:1253-1263)

**Aksi mandiri lainnya**

- **“🔄 Ganti ke Cash (baru)”** memakai field penagihan yang sama dengan AKSI 1 tetapi tanpa titip ulang. Banner-nya: **“🔄 Tagih titipan TERAKHIR — hitung BS, ambil uang. Setelah ini kedai TIDAK dititip lagi, tapi tetap aktif & tetap dikunjungi (mode beli tunai).”** Pesan sukses: **“Titipan terakhir ditagih. Kedai tetap aktif, mulai sekarang mode cash (beli tunai).”** (resources/views/livewire/operator/active-trip.blade.php:1005-1011; app/Livewire/Operator/ActiveTrip.php:2232-2236,2336-2338)
- **“🔄 Mulai Titipan (konsinyasi)”** memiliki field **“Mika Dititip Sekarang”** dengan helper **“Berapa mika yang kamu titipkan ke kedai ini sekarang.”**, lalu **“Jatah Seterusnya (Mika)”** dengan helper **“Jatah tetap kedai ini ke depan. Kunjungan berikutnya otomatis muncul \"Tagih + Titip Ulang\".”** (resources/views/livewire/operator/active-trip.blade.php:1115-1132)
- Blocking Mulai Titipan: **“Isi berapa mika yang dititip (minimal 1).”** dan **“Isi jatah seterusnya (minimal 1 mika).”** Pesan sukses: **“Titipan dimulai: … mika dititip, jatah seterusnya … mika. Kunjungan berikutnya bisa Tagih + Titip Ulang.”** (app/Livewire/Operator/ActiveTrip.php:2110-2119,2339-2342)

**Tombol simpan dan pesan umum**

- Tombol bawah dapat berlabel **“Betulkan titip = jatah dulu”**, **“Betulkan BS dulu”**, **“Isi jumlah titipan dulu”**, **“Isi jumlah cash dulu”**, **“Isi jumlah mika dulu”**, **“Isi jatah seterusnya dulu”**, atau **“Simpan Kunjungan”** sesuai kondisi; status prosesnya **“Menyimpan...”** (resources/views/livewire/operator/active-trip.blade.php:1307-1350)
- Bila AKSI 1 belum berisi titip ulang, tampil arahan **“Kalau cuma ambil bayaran tanpa titip baru, pakai ⛔ Hentikan Kedai.”** (resources/views/livewire/operator/active-trip.blade.php:1340-1343)
- Guard umum menampilkan **“Kios tidak valid atau bukan milik Anda. Tutup form dan coba lagi.”**, **“Data titipan tidak cocok dengan kios. Tutup form dan coba lagi.”**, dan untuk submit ganda dalam 10 detik, **“Kunjungan sudah tersimpan.”** (app/Livewire/Operator/ActiveTrip.php:1583-1607)
- Pesan sukses normal adalah **“Kunjungan berhasil disimpan.”**; jika jatah berubah menjadi **“Kunjungan disimpan. Jatah kios diperbarui ke … mika.”** (app/Livewire/Operator/ActiveTrip.php:2343-2346)

### A7. Hentikan Kedai

- Tombol **“⛔ Hentikan Kedai Ini”** membuka layar **“⛔ Hentikan Kedai …”**, dengan **“← Batal”** dan petunjuk **“Pilih cara menghentikan kedai”**. (resources/views/livewire/operator/active-trip.blade.php:719-732)
- **“🧾 Stop + Tagih Terakhir”** hanya tersedia bila ada titipan aktif. Keterangannya: **“Pemilik berhenti baik-baik — catat dodol yang laku, sisa & dodol basi, lalu uang terakhir. Kedai berhenti permanen.”** (resources/views/livewire/operator/active-trip.blade.php:733-739; app/Livewire/Operator/ActiveTrip.php:1228-1249)
- Form Stop + Tagih memuat **“Titipan Sebelumnya”**, **“Sisa Bagus (Biji)”**, **“Dodol Sisa/Basi (Biji)”**, **“(dodol rusak/basi yang diretur)”**, **“Terjual:”**, **“Total Tagihan:”**, dan **“Uang Diterima (Rp)”**. (resources/views/livewire/operator/active-trip.blade.php:758-792)
- **“🚫 Stop Tanpa Tagih”** selalu tersedia. Keterangannya: **“Kedai kabur / tak bisa ditagih — sisa dodol dicatat sebagai kerugian (uang Rp 0), lalu kedai berhenti.”** Jika ada titipan, ringkasannya **“⚠️ Titipan … mika (… biji) akan dicatat sebagai kerugian — semua dianggap dodol basi/hilang, uang Rp 0. Pembukuan tetap jujur (titipan tidak menggantung).”**; tanpa titipan: **“Kedai ini tidak punya titipan aktif — langsung dihentikan tanpa kerugian.”** (resources/views/livewire/operator/active-trip.blade.php:740-745,795-803)
- Semua jalur wajib memilih **“Alasan berhenti:”**. Pilihannya persis: **“Pemilik minta berhenti sementara”**, **“Kedai tutup permanen”**, **“Penjualan kurang jalan”**, **“Pindah lokasi”**, dan **“Alasan lain”**. (resources/views/livewire/operator/active-trip.blade.php:806-819; app/Models/Kiosk.php:72-81)
- Jika alasan belum dipilih, pesan blocking adalah **“Pilih alasan dulu”**. (app/Livewire/Operator/ActiveTrip.php:1275-1285)
- Setelah tombol **“Lanjut Hentikan Kedai”**, gerbang kedua menampilkan **“⚠️ Kedai ini akan DIHENTIKAN permanen & hilang dari daftar trip. Angka yang kamu isi FINAL — tidak bisa dikoreksi dari sini lagi. Yakin?”** dengan tombol **“Batal”**, **“Ya, Hentikan Permanen”**, dan status **“Memproses...”** (resources/views/livewire/operator/active-trip.blade.php:826-846)
- Stop + Tagih sukses menampilkan **“Tagihan terakhir tercatat. Kedai … dihentikan.”** Stop Tanpa Tagih menampilkan **“Kedai … dihentikan. Sisa dodol dicatat sebagai kerugian.”** atau, bila tanpa titipan, **“Kedai … dihentikan.”** (app/Livewire/Operator/ActiveTrip.php:1360-1364,1428-1434)
- Kegagalan server menampilkan **“Gagal menghentikan kedai. Coba lagi.”**; mismatch data memakai **“Kios tidak punya titipan yang cocok. Tutup form dan coba lagi.”** atau **“Data titipan tidak cocok dengan kios. Tutup form dan coba lagi.”** (app/Livewire/Operator/ActiveTrip.php:1307-1357,1372-1425)

### A8. Koreksi kunjungan

- Tombol **“Koreksi”** hanya tampil pada kartu kios yang sudah dikunjungi; kunjungan yang telah dikoreksi mendapat badge **“✎ Dikoreksi”**. (resources/views/livewire/operator/active-trip.blade.php:233-245,285-298)
- Modal berjudul **“Koreksi Kunjungan”** dan subjudul **“… — perbaiki angka yang salah.”** (resources/views/livewire/operator/active-trip.blade.php:417-437)
- Jika kunjungan awal punya drop, field yang ditampilkan adalah **“Jumlah Mika”**. Jika punya penagihan, bagian **“Penagihan Titipan”** menampilkan **“Sisa Bagus (Biji)”**, **“Dodol Sisa/Basi (Biji)”**, dan **“Uang Diterima (Rp)”**. Tombolnya **“Simpan Koreksi”**, **“Menyimpan...”**, dan **“Batal”**. (resources/views/livewire/operator/active-trip.blade.php:440-485)
- Koreksi hanya memperbaiki angka: drop mika, retur fresh/expired, dan uang diterima; bukan mengganti kios, aksi, BS, atau default kios. Mekanismenya menghapus transaksi lama yang terkait, menandai visit lama `corrected_at`, lalu menulis ulang visit baru yang tertaut ke visit lama. (app/Livewire/Operator/ActiveTrip.php:1749-1757,1839-1872)
- Koreksi ditolak bila tidak ada visit aktif (**“Belum ada kunjungan aktif ke kios ini.”**), trip sudah selesai (**“Trip sudah diakhiri, koreksi tidak bisa dilakukan.”**), walk-in (**“Penjualan walk-in tidak bisa dikoreksi.”**), visit mengubah jatah/default (**“Kunjungan yang mengubah default kios tidak bisa dikoreksi.”**), data lama tidak punya linkage (**“Kunjungan ini dibuat sebelum fitur koreksi dan tidak bisa dikoreksi.”**), atau tidak punya angka (**“Kunjungan ini tidak punya angka untuk dikoreksi.”**). (app/Livewire/Operator/ActiveTrip.php:1627-1693)
- Saat submit, koreksi juga ditolak jika visit tidak berasal dari trip ini (**“Kunjungan tidak ditemukan pada trip ini.”**), sudah pernah dikoreksi (**“Kunjungan ini sudah dikoreksi sebelumnya.”**), atau bukan kunjungan terakhir ke kios tersebut (**“Hanya kunjungan terakhir ke kios ini yang bisa dikoreksi.”**). (app/Livewire/Operator/ActiveTrip.php:1758-1804)
- Jika proses reversal gagal, pesannya **“Gagal mengoreksi kunjungan. Coba lagi.”**; jika berhasil, **“Kunjungan berhasil dikoreksi.”** (app/Livewire/Operator/ActiveTrip.php:1873-1884)

### A9. Kios Baru

- Halaman berjudul **“Kios Baru”** dengan subjudul **“Daftarkan kios baru langsung dari lapangan”**. Tautan kembali berbunyi **“Kembali ke Trip Aktif”** bila ada trip, atau **“Kembali ke Dashboard”** bila tidak. (resources/views/livewire/operator/create-kiosk.blade.php:7-28)
- Field form: **“Nama Kios *”** (placeholder **“Contoh: Kios Bu Sri”**), **“Nama Pemilik *”** (placeholder **“Contoh: Sri Wahyuni”**), **“Telepon”** (placeholder **“Opsional”**), **“Area *”** dengan opsi awal **“— Pilih area —”**, **“Jenis Kedai *”**, **“Catatan Kedai (opsional)”**, **“Lokasi Kios (opsional)”**, dan **“Foto Kios (opsional)”**. (resources/views/livewire/operator/create-kiosk.blade.php:30-130,165-187,253-275)
- Pesan validasi khusus yang didefinisikan server: **“Nama kios wajib diisi”**, **“Nama pemilik wajib diisi”**, **“Pilih area dulu”**, **“Area tidak valid”**, **“Isi jatah (mika biasa dititip)”**, dan **“Minimal 1 mika”**. (app/Livewire/Operator/CreateKiosk.php:144-175)

**Tiga jenis kedai dan hubungan dengan trip aktif**

1. **“🏪 Kedai Konsinyasi (titip sekarang)”** — **“Naruh dodol sekarang dari stok trip. Dihitung sebagai titipan berjalan + komisi.”** Dengan trip aktif, muncul **“Jatah Titipan (mika ditaruh sekarang) *”** dan helper **“Berapa mika yang kamu taruh sekarang dari stok trip. Ini jadi titipan berjalan + dihitung komisi. Kamu yang cek sisanya pas kunjungan berikutnya.”** (resources/views/livewire/operator/create-kiosk.blade.php:105-111,132-154)
2. Tanpa trip aktif, pilihan konsinyasi tidak mengeluarkan stok dan disimpan sebagai booking. Banner persisnya **“Mau menitipkan dodol? Mulai trip dulu ya.”** dan **“Tanpa trip aktif, kedai ini disimpan sebagai booking (identitas saja). Nanti pas trip jalan dan kamu kunjungi, baru bisa naruh dodol / cash.”** (resources/views/livewire/operator/create-kiosk.blade.php:156-163; app/Livewire/Operator/CreateKiosk.php:132-142,193-197)
3. **“💵 Kedai Cash-Only (beli putus)”** — **“Beli tunai tiap kali, jumlah suka-suka. Tak ada titipan & tak ada jatah.”** (resources/views/livewire/operator/create-kiosk.blade.php:112-118)
4. **“📌 Kedai Booking (belum ada dodol)”** — **“Catat identitasnya dulu. Nanti pas dikunjungi, tentukan mau dititip atau cash.”** Booking secara model berarti bukan cash-only dan `default_qty_mika` masih `null`. (resources/views/livewire/operator/create-kiosk.blade.php:119-125; app/Models/Kiosk.php:95-106)
- **“Catatan Kedai (opsional)”** memakai placeholder **“cth: Cash-only, biasa minta 5 mika / Suka-suka, kadang banyak kadang dikit”** dan helper **“Kebiasaan kedai ini. Tampil menonjol biar operator berikutnya tahu.”** (resources/views/livewire/operator/create-kiosk.blade.php:165-180)

**Lokasi, link Maps, dan GPS**

- Petunjuk lokasi: **“Klik titik di peta atau pakai tombol GPS di bawah”**. Input Maps memakai placeholder **“Tempel link atau koordinat Google Maps”** dan tombol **“Loncat”**/status **“⏳”**. Jika berhasil, toast-nya **“📍 Lokasi ditemukan!”** (resources/views/livewire/operator/create-kiosk.blade.php:183-213,498-507)
- Error parser persis: **“Tempel link atau koordinat Google Maps dulu.”**, **“Link pendek tidak bisa dibaca. Buka dulu link-nya di browser, lalu tempel link panjang atau koordinatnya di sini.”**, dan **“Format tidak dikenali. Coba tempel koordinat langsung, contoh: 3.5896, 98.6739”**. (app/Livewire/Operator/CreateKiosk.php:54-79)
- Tombol GPS adalah **“📍 Pakai Lokasi Saya (GPS)”** dan saat mencari berubah menjadi **“⏳ Mencari lokasi...”**. Jika tidak ada titik, tampil **“Belum ada lokasi dipilih”**; koordinat yang terpilih ditampilkan sebagai **“📍 …, …”**. (resources/views/livewire/operator/create-kiosk.blade.php:215-250,429-450)
- GPS diberi timeout 15 detik dan otomatis mencoba sekali lagi dengan akurasi rendah bila gagal selain izin ditolak. Auto-GPS gagal secara senyap; pesan hanya muncul setelah tombol manual ditekan. (resources/views/livewire/operator/create-kiosk.blade.php:418-468)
- Pesan GPS persis: **“GPS tidak didukung browser ini.”**, **“Gagal mendapatkan lokasi. Coba lagi.”**, **“Izin lokasi ditolak. Buka ikon gembok di address bar > Izin > Lokasi > Izinkan, lalu coba lagi. Kamu tetap bisa simpan kios tanpa lokasi atau tandai manual di peta.”**, **“Sinyal GPS tidak didapat. Coba lagi di tempat terbuka, atau tandai lokasi manual di peta.”**, dan **“Waktu habis mencari lokasi. Coba lagi, atau tandai lokasi manual di peta.”** (resources/views/livewire/operator/create-kiosk.blade.php:429-462)
- Bila tile gagal, tampil **“⚠️ Gambar peta gagal dimuat (jaringan). Kamu masih bisa menandai lokasi lewat tombol GPS atau tempel link Google Maps, atau simpan kios tanpa lokasi.”** (resources/views/livewire/operator/create-kiosk.blade.php:221-229)
- Setiap titik yang didapat dari GPS menampilkan **“Akurasi ±… meter”** di bawah peta, plus lingkaran radius akurasi biru (`L.circle`) digambar di sekitar pin. Akurasi lebih dari 50 meter dianggap kurang akurat dan memicu peringatan kuning eksplisit: **“⚠️ Lokasi kurang akurat (±… m). Pastikan GPS mode akurasi tinggi, coba di luar ruangan, atau geser pin ke titik yang benar.”** Keduanya hilang lagi begitu titik dipindah manual lewat klik peta (tanpa data akurasi). (resources/views/livewire/operator/create-kiosk.blade.php:540-591)

**Foto dan HEIC**

- Foto dapat dipilih melalui **“📷 Ambil Foto”** atau **“🖼️ Dari Galeri”**. Statusnya **“Menyiapkan foto…”** dan **“Mengunggah foto…”**. (resources/views/livewire/operator/create-kiosk.blade.php:277-302)
- Bila browser tidak bisa membuat preview, teksnya **“✅ Foto terpilih (…). Pratinjau tak tersedia untuk format ini — foto dikonversi otomatis saat disimpan.”** (resources/views/livewire/operator/create-kiosk.blade.php:303-323)
- Browser mengecilkan gambar ke sisi terpanjang 1600 px, JPEG kualitas 0,8; file yang sudah kecil dapat dilewati. HEIC sengaja dikirim mentah karena Chrome Android tidak dapat mendekodenya; kegagalan kompresi lain juga mengembalikan file asli agar lapisan server tetap bisa memprosesnya. (resources/js/kiosk-photo.js:18-25,51-67,69-111)
- Server menerima JPG, PNG, WEBP, HEIC, dan HEIF dengan plafon 20 MB; pesan validasinya **“Format foto tidak didukung. Pakai JPG, PNG, atau WEBP.”**, **“Foto terlalu besar (maksimal 20MB). Coba foto ulang.”**, dan **“File foto tidak valid.”** (app/Support/KioskPhoto.php:39-53,70-86)
- HEIC dideteksi dari isi file, bukan hanya ekstensi/MIME. Jika Imagick + delegate HEIF tersedia, HEIC dikonversi menjadi JPG, orientasi diterapkan, metadata dibuang, lalu foto diperkecil; HEIC mentah tidak pernah disimpan. (app/Support/HeicConverter.php:20-23,32-68,77-101; app/Support/KioskPhoto.php:117-155)
- Jika server tidak mendukung konversi HEIC, pesan blocking persisnya **“Format HEIC belum didukung di server ini. Ubah setelan kamera HP ke JPG dulu — iPhone: Settings → Camera → Formats → \"Most Compatible\". Android (Poco/Xiaomi): Kamera → Setelan → Format foto → JPEG.”** (app/Support/KioskPhoto.php:89-115; app/Livewire/Operator/CreateKiosk.php:177-183)
- Tombol akhir adalah **“Simpan Kios”** dengan status **“Menyimpan...”**. Pesan hasilnya: **“Kios baru berhasil ditambahkan.”**, **“Kios tersimpan, tapi titipan belum tercatat (belum ada varian produk aktif). Hubungi owner untuk cek Master Data → Produk.”**, atau **“Kios tersimpan, tapi fotonya gagal diproses. Buka kios ini lalu unggah ulang fotonya.”** (resources/views/livewire/operator/create-kiosk.blade.php:329-345; app/Livewire/Operator/CreateKiosk.php:232-250)

### A10. Akhiri Trip

- Modal berjudul **“Akhiri Trip”** dengan petunjuk **“Pastikan ringkasan di bawah sudah sesuai.”** (resources/views/livewire/operator/active-trip.blade.php:491-500)
- Ringkasan yang dilihat OPERATOR berisi persis: **“Total Kios Dikunjungi”**, **“— Kios Lama”**, **“— Kios Baru”**, **“Mika Dibawa”**, **“Total Titip”**, **“Sisa di Motor”**, **“Mika Terjual (biji ÷ 15)”**, **“Mika Kios Baru”**, **“Mika Cash”** (hanya bila lebih dari 0), dan **“Omset (Cash Diterima)”**. (resources/views/livewire/operator/active-trip.blade.php:502-550)
- **Komisi TIDAK ditampilkan kepada operator.** Payload `tripSummary` hanya mengirim angka ringkasan di atas dan secara eksplisit tidak mengirim HPP, untung kotor, komisi reguler/kios baru/total, maupun untung bersih owner. (app/Livewire/Operator/ActiveTrip.php:2475-2491)
- Komisi tetap dihitung dan disimpan di server ketika trip diakhiri, memakai `komisi_rian`. Basis komisinya terpisah dari mika terjual: mika DROP yang diletakkan, mengecualikan redistribusi BS; formula accessor adalah `mika_komisi × tarif komisi per mika`. Informasi ini bukan bagian UI operator. (app/Livewire/Operator/ActiveTrip.php:2518-2553; app/Models/Trip.php:270-292)
- Di bawah **“Alasan Mengakhiri”**, daftar alasan persisnya: **“Stok Habis”**, **“Target Tercapai”**, **“Sakit”**, **“Keperluan Mendadak”**, dan **“Lainnya”**. Nilai valid server adalah `stock_habis`, `target_done`, `sakit`, `urgent_personal`, dan `other`. (resources/views/livewire/operator/active-trip.blade.php:552-565; app/Livewire/Operator/ActiveTrip.php:29)
- Tombolnya **“Batal”**, **“Konfirmasi Akhiri Trip”**, dan status **“Mengakhiri...”**. Tanpa alasan valid, pesannya **“Pilih alasan mengakhiri trip.”**; jika sudah berakhir, **“Trip sudah diakhiri.”**; suksesnya **“Trip berhasil diakhiri.”** (resources/views/livewire/operator/active-trip.blade.php:569-576; app/Livewire/Operator/ActiveTrip.php:2504-2515,2556-2558)

### A11. Profil operator

- Halaman wrapper berbahasa Indonesia: judul **“Profil Saya”** dan subjudul **“Kelola informasi akun kamu”**. Halaman memuat dua kartu: form informasi profil dan form ganti password. (resources/views/livewire/operator/profile.blade.php:1-18; app/Livewire/Operator/Profile.php:8-15)
- Form bawaan Breeze/Volt masih berbahasa Inggris: **“Profile Information”**, **“Update your account's profile information and email address.”**, field **“Name”** dan **“Email”**, tombol **“Save”**, serta pesan **“Saved.”** (resources/views/livewire/operator/update-profile-form.blade.php:65-88,109-116)
- Bila verifikasi email berlaku, teks Inggris yang tampil adalah **“Your email address is unverified.”**, **“Click here to re-send the verification email.”**, dan **“A new verification link has been sent to your email address.”** (resources/views/livewire/operator/update-profile-form.blade.php:90-105)
- Form password juga masih berbahasa Inggris: **“Update Password”**, **“Ensure your account is using a long, random password to stay secure.”**, field **“Current Password”**, **“New Password”**, **“Confirm Password”**, tombol **“Save”**, dan pesan **“Saved.”** (resources/views/livewire/operator/update-password-form.blade.php:41-81)
- Form profil menyimpan nama/email dan mengosongkan `email_verified_at` bila email berubah; form password mewajibkan password saat ini, aturan password default Laravel, serta konfirmasi password. (resources/views/livewire/operator/update-profile-form.blade.php:17-44; resources/views/livewire/operator/update-password-form.blade.php:15-38)

---

## B. OWNER (panel)

### B1. Login & navigasi

**Arsitektur dual-UI (terverifikasi dari source).** Sisi Owner terdiri dari DUA UI yang berbeda, bukan satu:

1. **Dashboard Blade/Livewire custom di `/owner/*`** — ini adalah UI sehari-hari yang sesungguhnya dipakai owner. Route-route-nya didefinisikan di `routes/web.php:60-108` dengan prefix `owner` dan middleware `role:owner` (untuk dashboard/settings) atau `role:owner,super_admin` (untuk trip/laporan):
   - `GET /owner/dashboard` → `OwnerDashboardController::index` (`routes/web.php:64-65`)
   - `POST /owner/kiosks/{kiosk}/reactivate` → `OwnerDashboardController::reactivateKiosk` (`routes/web.php:67-68`)
   - `GET/PUT /owner/settings` (`routes/web.php:70-73`)
   - `GET /owner/trips`, `GET /owner/trips/{trip}`, `DELETE /owner/trips/{trip}`, `POST /owner/trips/{trip}/restore`, export PDF/Excel (`routes/web.php:82-107`)
   - `GET /owner/reports/monthly` + export PDF/Excel (`routes/web.php:102-107`)

   Layout-nya adalah `resources/views/layouts/owner.blade.php` — sidebar statis + header, BUKAN komponen Filament. Judul tab halaman di layout ini: `@yield('title', 'Owner')` (`resources/views/layouts/owner.blade.php:10`), dan sub-judul di sidebar: **"Panel Owner"** (`resources/views/layouts/owner.blade.php:36`).

2. **Panel Filament di `/owner-panel`** — didaftarkan oleh `App\Providers\Filament\OwnerPanelProvider` dengan `->id('owner')` dan `->path('owner-panel')` (`app/Providers/Filament/OwnerPanelProvider.php:40-41`). Panel ini mendaftarkan resource: `KioskResource`, `ClusterResource`, `SupplierResource`, `ProductResource`, `ProductVariantResource`, `ProcurementBatchResource`, `OperatorResource` (`app/Providers/Filament/OwnerPanelProvider.php:48-56`), brand name **"Cemilan Qontas — Owner"**, warna primer Amber, dark mode dimatikan (`app/Providers/Filament/OwnerPanelProvider.php:43-47`).

   Panel ini juga mendaftarkan SATU page: `\App\Filament\Pages\Dashboard::class` (`app/Providers/Filament/OwnerPanelProvider.php:57-59`). Isi page tersebut (`app/Filament/Pages/Dashboard.php:7-13`):
   ```php
   class Dashboard extends BaseDashboard
   {
       public function mount()
       {
           return redirect()->route('owner.dashboard');
       }
   }
   ```
   **Ini adalah dead-end redirect** — begitu owner mengakses `/owner-panel` (yang me-resolve ke Dashboard page bawaan Filament sebagai halaman index panel), `mount()` langsung melempar balik ke `route('owner.dashboard')`, yaitu dashboard Blade di poin 1. Owner **TIDAK PERNAH benar-benar "masuk" ke shell/dashboard Filament secara utuh** — satu-satunya cara owner menyentuh panel Filament adalah lewat tautan sidebar Blade yang menembak langsung ke URL index resource tertentu (contoh: `filament.owner.resources.kiosks.index`), bukan lewat navigasi di dalam panel itu sendiri. Satu-satunya elemen navigasi yang didaftarkan khusus di dalam panel adalah item kembali: `NavigationItem::make('Kembali ke Dashboard')` yang mengarah ke `route('owner.dashboard')`, dengan ikon `heroicon-o-arrow-left`, `sort(-1)` (`app/Providers/Filament/OwnerPanelProvider.php:60-65`) — yaitu tombol untuk LOMPAT BALIK ke dashboard Blade dari dalam resource Filament, memperkuat bahwa panel Filament hanya dipakai sebagai "halaman kerja CRUD data master" yang disisipkan di antara kunjungan ke dashboard Blade, bukan sebagai home utama.

   Guard akses panel: `authMiddleware([Authenticate::class, EnsureUserHasRole::class.':owner'])` (`app/Providers/Filament/OwnerPanelProvider.php:77-80`).

**Menu sidebar dashboard Blade** — dikutip PERSIS dari `resources/views/layouts/owner.blade.php:41-50` (array `$nav`, label ditampilkan sebagai teks link):

| Label (verbatim) | Route target |
|---|---|
| `Dashboard` | `owner.dashboard` |
| `Riwayat Trip` | `owner.trips.index` |
| `Manajemen Kios` | `filament.owner.resources.kiosks.index` |
| `Manajemen Area` | `filament.owner.resources.clusters.index` |
| `Manajemen Supplier` | `filament.owner.resources.suppliers.index` |
| `Manajemen Anggota` | `filament.owner.resources.operators.index` |
| `Stok Masuk` | `filament.owner.resources.procurement-batches.index` |
| `Laporan & Analitik` | `owner.reports.monthly` |
| `Settings` | `owner.settings` |

Perhatikan: lima dari sembilan item (Manajemen Kios, Manajemen Area, Manajemen Supplier, Manajemen Anggota, Stok Masuk) menembak LANGSUNG ke halaman index resource Filament — ini konfirmasi konkret bahwa "masuk ke panel Filament" bagi owner artinya "diarahkan ke satu resource tertentu dari sidebar Blade", bukan membuka panel Filament sebagai aplikasi tersendiri.

Header dashboard Blade juga punya dropdown akun berisi link **"Profil"** (`resources/views/layouts/owner.blade.php:88`, ke route `profile`) dan tombol **"Logout"** (`resources/views/layouts/owner.blade.php:91`, form POST ke route `logout`). Nama bisnis owner (atau fallback "Cemilan Qontas") ditampilkan di pojok kiri atas sidebar sebagai link ke `owner.dashboard` (`resources/views/layouts/owner.blade.php:33-35`).

Di dashboard Blade sendiri ada pula tombol jembatan ke panel Filament: **"Buka Panel Manajemen Data"**, mengarah ke `filament.owner.resources.kiosks.index` (`resources/views/owner/dashboard.blade.php:490-493`).

Login memakai form Livewire (bukan endpoint POST biasa), sesuai catatan arsitektur proyek — tidak ada file login khusus owner yang diperiksa dalam audit ini di luar middleware `role:owner` yang menggerbangi seluruh grup route `/owner/*` (`routes/web.php:60`) dan middleware `auth`/`verified`/`no-store` yang dipasang di hampir semua grup (`routes/web.php:53,60,77,110`).

---

### B2. Dashboard

Dashboard Blade dirender oleh `OwnerDashboardController::index()` (`app/Http/Controllers/OwnerDashboardController.php:19-360`) ke view `resources/views/owner/dashboard.blade.php`. Judul halaman: **"Dashboard Owner"** (`resources/views/owner/dashboard.blade.php:7`), sapaan **"Selamat datang, {nama}."** (`resources/views/owner/dashboard.blade.php:8`).

**Baris 1 — 4 kartu statistik master data** (`resources/views/owner/dashboard.blade.php:17-34`, nilai dari `$stats` di `app/Http/Controllers/OwnerDashboardController.php:32-37`):
- **"Total Kios"** — jumlah kios aktif/semua milik owner (via cluster.owner_id), dikecualikan kios sentinel walk-in.
- **"Total Area"** — jumlah cluster milik owner (dikecualikan sentinel).
- **"Total Supplier"** — jumlah supplier milik owner.
- **"Total Operator"** — jumlah user `role=operator` milik owner.

Jika semua statistik ini 0, muncul pesan: **"Database masih kosong. Mulai dengan input area & kios dari menu sidebar."** (`resources/views/owner/dashboard.blade.php:36-40`).

**Baris 2 — 5 kartu widget operasional** (`resources/views/owner/dashboard.blade.php:43-108`):
- **"Omset Hari Ini"** — `Settlement::where('visit_date', today())->sum('amount_paid')` discope ke owner lewat `delivery.kiosk.cluster.owner_id` (`app/Http/Controllers/OwnerDashboardController.php:39-44`, blade baris 50-54). Artinya: total uang yang benar-benar MASUK (dibayar) dari penagihan hari ini.
- **"Kios Perlu Dikunjungi"** — `$overdueCount`: kios aktif yang belum pernah dikunjungi, ATAU kunjungan terakhirnya sudah melewati `target_visit_interval_days` kios (atau default owner) (`app/Http/Controllers/OwnerDashboardController.php:46-72`, blade baris 57-68). Merah jika >0, kuning jika 0.
- **"Belum Bayar"** — `totalOutstanding`: `SUM(amount_due - amount_paid)` dari settlement berstatus `pending`, discope owner (`app/Http/Controllers/OwnerDashboardController.php:74-78`, blade baris 70-81). Ini piutang rupiah gabungan.
- **"Total Stok"** — `totalStokTersisa`: total mika sisa di SEMUA batch stok masuk (procurement batch) milik owner (`app/Http/Controllers/OwnerDashboardController.php:219-240`, blade baris 83-94).
- **"Untung Bersih Hari Ini"** — sum `untung_bersih_owner` (accessor Trip) dari trip owner yang SUDAH SELESAI (`ended_at` terisi) dengan `trip_date` = hari ini (`app/Http/Controllers/OwnerDashboardController.php:116-123`, blade baris 96-107).

**"Ringkasan Bulan Ini"** (`resources/views/owner/dashboard.blade.php:124-174`, judul h3 persis "Ringkasan Bulan Ini", periode ditampilkan dengan format mis. "Oktober 2026 (1–20 Okt)" dari `$ringkasanPeriode`, `app/Http/Controllers/OwnerDashboardController.php:173-175`). Tiga kartu, HANYA menghitung trip yang SUDAH SELESAI bulan berjalan (rumus identik `MonthlyReportController`, lihat method `monthlySummary()` `app/Http/Controllers/OwnerDashboardController.php:379-435`):
- **"💰 Omset"** — `SUM(settlements.amount_paid)` atas delivery yang di-settle kunjungan aktif trip bulan ini.
- **"📈 Untung Bersih"** — `untung_kotor − komisi`; blade menampilkan catatan kecil: **"Omset − HPP − komisi. Kerugian BS ditampilkan terpisah."** (`resources/views/owner/dashboard.blade.php:149-151`).
- **"👷 Komisi Operator"** — `dropMika × komisi_per_mika` (basis DROP, bukan basis laku). Jika owner punya >1 operator aktif bulan ini, breakdown per-operator ditampilkan di bawah kartu (`resources/views/owner/dashboard.blade.php:162-171`, data dari `$komisiPerOperator`, `app/Http/Controllers/OwnerDashboardController.php:154-171`).

Setiap kartu ringkasan punya indikator delta vs bulan lalu berupa simbol **▲ /▼** dan persentase, atau **"—"** kalau bulan lalu 0 (tak ada basis banding) — label **"vs bulan lalu"** (`resources/views/owner/dashboard.blade.php:112-123,138,148,161`; logika di `app/Http/Controllers/OwnerDashboardController.php:140-150`).

**"Stok Per Batch"** (h3, `resources/views/owner/dashboard.blade.php:180`, hanya tampil jika ada batch) — per batch menampilkan "Batch {tanggal beli}", total mika awal, sisa stok, dan label **"Habis"** (merah, stok ≤0) atau **"Hampir Habis"** (kuning, stok ≤10) (`resources/views/owner/dashboard.blade.php:183-206`; flag dihitung di `app/Http/Controllers/OwnerDashboardController.php:223-237`).

**"Prediksi Dodol Habis"** (h3, `resources/views/owner/dashboard.blade.php:213`, subjudul **"Dari cek sisa dodol terakhir oleh operator di kios"**) — menampilkan hingga 5 kios dengan hasil cek sisa biji terbaru dari operator, plus teks prediksi (mis. "N hari") atau **"Data belum cukup"** jika data kurang (`resources/views/owner/dashboard.blade.php:210-232`; sumber `$prediksiKios`, `app/Http/Controllers/OwnerDashboardController.php:242-256`). Section tersembunyi total jika kosong.

**"💰 Belum Bayar (per kios)"** (h3, `resources/views/owner/dashboard.blade.php:238`, subjudul **"Piutang rupiah dari penagihan yang belum lunas — per kios + janji bayar"**) — daftar hingga 20 kios dengan piutang rupiah terbesar (hanya yang >0), plus catatan janji bayar jika ada (`resources/views/owner/dashboard.blade.php:235-255`; sumber `$belumBayarPerKios`, `app/Http/Controllers/OwnerDashboardController.php:312-333`).

**"⏳ Titipan Tertunda"** (h3, `resources/views/owner/dashboard.blade.php:261`, subjudul **"Kios yang pemiliknya belum bisa bayar — titipan tetap berjalan (ditagih kunjungan berikutnya)"**) — kios yang punya delivery pending DAN ditandai `alasan_check = belum_bisa_bayar` pada kunjungan cek terakhirnya, ditampilkan dalam satuan **mika** (bukan rupiah) — hingga 20 baris, note janji bayar atau **"Tanpa catatan janji bayar"** (`resources/views/owner/dashboard.blade.php:258-278`; sumber `app/Http/Controllers/OwnerDashboardController.php:274-310`).

**"Kios Berhenti"** (h3, `resources/views/owner/dashboard.blade.php:284`, subjudul **"Kios yang dihentikan titipannya — bisa diaktifkan kembali"**) — hingga 20 kios non-aktif terbaru, dengan alasan stop, tanggal stop, dan "(oleh {stopped_by})" (`resources/views/owner/dashboard.blade.php:280-329`; sumber `app/Http/Controllers/OwnerDashboardController.php:258-272`). Tombol **"Aktifkan Kembali"** per kios (form POST ke `owner.kiosks.reactivate`, `resources/views/owner/dashboard.blade.php:318-324`) memanggil `OwnerDashboardController::reactivateKiosk()` yang mereset `is_active=true`, `stopped_at/stop_reason/stopped_by=null`, dengan guard kepemilikan `abort_unless($kiosk->owner_id === auth()->id(), 403)` (`app/Http/Controllers/OwnerDashboardController.php:441-453`), dan menampilkan flash message **"Kios {name} diaktifkan kembali."**.

Di atas daftar kios berhenti, jika ada kerugian write-off bulan ini, muncul kotak merah: **"Kerugian titipan bulan ini (stop tanpa tagih): {mika} mika ({biji} biji) ≈ Rp {nilai}"** dengan sub-teks **"Dodol di kedai yang kabur/tak tertagih — dicatat sebagai kerugian (modal × HPP)."** (`resources/views/owner/dashboard.blade.php:286-295`). Dihitung dari settlement `is_writeoff=true` bulan ini, nilai = biji hilang ÷ unit-per-pack × HPP snapshot per baris delivery (fallback HPP owner jika snapshot NULL) (`app/Http/Controllers/OwnerDashboardController.php:80-114`).

**Laporan Trip Real-Time (Live)** — komponen Livewire `<livewire:owner.live-trip-progress />` (`resources/views/owner/dashboard.blade.php:332`), file `app/Livewire/Owner/LiveTripProgress.php`, di-poll tiap 30 detik (`wire:poll.30s`, `resources/views/livewire/owner/live-trip-progress.blade.php:1`). Header: **"Laporan Trip Real-Time (Live)"** dengan badge **"Live Polling (30s)"** (`resources/views/livewire/owner/live-trip-progress.blade.php:4-9`). Untuk setiap trip yang masih berjalan (`ended_at` NULL): **"Trip #{N} — {operator}"**, baris info **"Area: … | Mulai: … | Bawa: … mika"**, **"Omset Sementara"**; progress bar dengan teks **"{operator} sudah visit {X} dari {Y} kios"** + persentase (`app/Livewire/Owner/LiveTripProgress.php:57-127`, blade baris 25-52) — denominator Y dihitung dari kios di area awal + SEMUA area yang sudah disentuh trip ini (bukan cuma area awal), supaya progres tak pernah "melompat" aneh saat operator menyeberang area (komentar kode, `app/Livewire/Owner/LiveTripProgress.php:61-77`). Angka pembilang kios tervisit JUGA memasukkan kios yang sudah dikunjungi trip LAIN milik owner hari ini, agar "belum dikunjungi" tidak keliru menghitung kios yang sebenarnya sudah didatangi operator lain (`app/Livewire/Owner/LiveTripProgress.php:43-55,109-115`). Tiga kotak running total: **"Mika Dititip"**, **"Omset"**, **"Komisi Sejauh Ini"** (`resources/views/livewire/owner/live-trip-progress.blade.php:55-68`). Dua kolom daftar: **"Kios Sudah Dikunjungi (N)"** (dengan jam, nama kios, badge aksi kunjungan — label dari `TripExportController::visitActionLabel()`, dan rincian "Titip: X mika" / "Tagih: Rp Y (Terjual: Z biji)") dan **"Kios Belum Dikunjungi (N)"** (`resources/views/livewire/owner/live-trip-progress.blade.php:71-127`). State kosong: **"Tidak ada trip aktif saat ini."** + **"Status dan aktivitas Rian akan muncul di sini secara real-time begitu ia memulai trip."** (`resources/views/livewire/owner/live-trip-progress.blade.php:130-133`) — catatan: teks placeholder ini hardcode nama "Rian" meski komponennya generik untuk operator manapun.

**"Laporan Akhir Trip Terbaru (Selesai)"** (h2, `resources/views/owner/dashboard.blade.php:336-338`) — menampilkan 5 trip SELESAI terbaru milik owner (`app/Http/Controllers/OwnerDashboardController.php:204-216`, agregat dibatch via `TripAggregator::for()` bukan accessor per-baris supaya tidak N+1). Untuk tiap trip: badge **"Selesai"**, judul **"Trip #{N} — {operator}"**, baris **"Tanggal Trip: … | Area: … | Waktu: {mulai} - {selesai}"**, dan jika ada alasan selesai: **"| Alasan Selesai: {label}"** dengan mapping (`resources/views/owner/dashboard.blade.php:372-381`): `stock_habis`→**Stok Habis**, `target_done`→**Target Selesai**, `sakit`→**Sakit**, `urgent_personal`→**Urusan Pribadi Mendesak**, default→**Lainnya**. Di kanan: **"Omset Akhir"** + tombol **"PDF"**/**"XLS"** (export) dan **"Arsipkan"** (confirm: *"Arsipkan Trip #{N}? Trip & datanya disembunyikan dari laporan, tapi bisa dipulihkan."*) (`resources/views/owner/dashboard.blade.php:385-400`).

Grid fisik per trip: **"Kios Dikunjungi"**, **"Kios Lama"**, **"Kios Baru"**, **"Total Mika Dititip"** (+biji) (`resources/views/owner/dashboard.blade.php:405-423`). Grid finansial: **"Mika Terjual"** (+biji), **"Mika Kios Baru (Titip)"** (+biji), **"Modal Dodol"**, **"Keuntungan"** (`resources/views/owner/dashboard.blade.php:426-445`). Grid komisi (latar amber): **"Mika Komisi (Drop)"** (+biji), **"Komisi {operator}"**, **"Untung Bersih Owner"** (`resources/views/owner/dashboard.blade.php:447-461`). Kosong → **"Belum ada trip yang diselesaikan."** (`resources/views/owner/dashboard.blade.php:463-466`). Link bawah: **"Riwayat Semua Trip →"** dan **"Lihat Laporan Bulanan →"** (`resources/views/owner/dashboard.blade.php:469-478`).

**"Tren Omset (30 Hari Terakhir)"** (h2, `resources/views/owner/dashboard.blade.php:483`) — grafik garis Chart.js dari total omset harian 30 hari terakhir (`app/Http/Controllers/OwnerDashboardController.php:177-195`, blade baris 497-576).

Terakhir, tombol **"Buka Panel Manajemen Data"** menutup halaman, mengarah ke resource Kios di panel Filament (`resources/views/owner/dashboard.blade.php:489-494`).

---

### B3. Riwayat Trip

Route `owner.trips.index` (`routes/web.php:82-83`) → `TripHistoryController::index()` (`app/Http/Controllers/Owner/TripHistoryController.php:23-90`) → view `resources/views/owner/trips/index.blade.php`. Judul halaman: **"Riwayat Trip"**, subjudul **"Semua trip yang sudah selesai — filter, buka detail, kelola arsip."** (`resources/views/owner/trips/index.blade.php:7-8`).

**Form filter** (GET, server-side) (`resources/views/owner/trips/index.blade.php:18-49`):
- **"Status"** select dengan opsi **"Aktif"**, **"Diarsip"**, **"Semua"** (`resources/views/owner/trips/index.blade.php:22-26`). Logika: `aktif` = default (global scope SoftDeletes menyembunyikan trip terarsip), `diarsip` = `onlyTrashed()`, `semua` = `withTrashed()` (`app/Http/Controllers/Owner/TripHistoryController.php:36-41`).
- **"Operator"** select, opsi **"Semua operator"** + daftar operator milik owner (`resources/views/owner/trips/index.blade.php:28-35`).
- **"Dari tanggal"** / **"Sampai tanggal"** input tanggal (`resources/views/owner/trips/index.blade.php:37-43`), memfilter `trip_date`.
- Tombol **"Terapkan"** dan **"Reset"** (`resources/views/owner/trips/index.blade.php:46-47`).

**Tabel** kolom verbatim (`resources/views/owner/trips/index.blade.php:57-66`): **Tanggal**, **Operator**, **Area**, **Kios**, **Mika Diantar**, **Omset**, **Komisi**, **Untung Bersih**, **Status**, **Aksi**.

Isi baris (`resources/views/owner/trips/index.blade.php:70-136`): tanggal trip (link ke detail) + sub-teks "Trip #{N}"; nama operator; label area (dengan badge **"lintas"** kalau trip menyeberang area, teks sky-blue, `resources/views/owner/trips/index.blade.php:86-91`); jumlah kios, mika diantar (+biji), omset, komisi, untung bersih — SEMUA dari agregat batch (`TripHistoryController::aggregatesFor()` → `TripAggregator::for()`, `app/Http/Controllers/Owner/TripHistoryController.php:92-116`, bukan N+1 per baris); badge status **"Diarsip"** (abu) atau **"Aktif"** (hijau) (`resources/views/owner/trips/index.blade.php:100-106`); kolom Aksi berisi tombol **"Detail"** (link), lalu bila trip terarsip tombol **"Pulihkan"** (POST `owner.trips.restore`, confirm: *"Pulihkan trip ini? Akan kembali dihitung di laporan."*), atau bila masih aktif tombol **"Arsipkan"** (DELETE `owner.trips.destroy`, confirm: *"Arsipkan Trip #{N}? Trip & datanya disembunyikan dari laporan, tapi bisa dipulihkan."*) (`resources/views/owner/trips/index.blade.php:107-128`). Kosong → **"Tidak ada trip yang cocok dengan filter."** (`resources/views/owner/trips/index.blade.php:130-135`). Pagination `$trips->links()` (`resources/views/owner/trips/index.blade.php:143`).

**Detail trip** (`TripHistoryController::show()`, `app/Http/Controllers/Owner/TripHistoryController.php:123-168` → `resources/views/owner/trips/show.blade.php`): link kembali **"← Kembali ke Riwayat Trip"** (`resources/views/owner/trips/show.blade.php:7-8`). Header **"Trip {tanggal}"** + badge **"Diarsip"**/**"Aktif"**; baris **"Trip #{N} · Operator: {nama} · Area: {label}"**; jika area menyeberang: **"Trip ini menyeberang area — kunjungan tercatat di {N} area, semuanya dalam SATU trip (komisi & stok tetap dihitung sebagai satu trip)."** (`resources/views/owner/trips/show.blade.php:17-42`). Tombol aksi: **"Export PDF"**, **"Export Excel"**, dan **"Pulihkan"**/**"Arsipkan"** (sama seperti index) (`resources/views/owner/trips/show.blade.php:44-63`).

Grid **"Mika Dibawa"**, **"Mika Diantar"** (+biji), **"Mika Sisa"**, **"Mika Terjual"** (+biji) (`resources/views/owner/trips/show.blade.php:68-87`). Grid **"Omset"**, **"Modal Dodol (HPP)"**, **"Untung Kotor"**, **"Mika Komisi (Drop)"** (+biji) (`resources/views/owner/trips/show.blade.php:90-108`). Grid amber **"Komisi {operator}"** / **"Untung Bersih Owner"** (`resources/views/owner/trips/show.blade.php:110-119`).

Tabel **"Kunjungan Kios ({N})"** kolom: **Kios**, **Aksi**, **Waktu**, **Mika Titip**, **BS (biji)**, **Uang** (`resources/views/owner/trips/show.blade.php:122-156`). Label aksi kunjungan dari `TripExportController::visitActionLabel()` (`app/Http/Controllers/Owner/TripExportController.php:65-75`): `drop_and_settle`→**Tagih + Titip Baru**, `drop_only`→**Titip Baru**, `settle_only`→**Tagih Saja**, `check_only`→**Cek Saja**, `cash_sale`→**Jual Cash**.

**Arsipkan/Pulihkan** (`app/Http/Controllers/Owner/TripDeleteController.php`): `destroy()` memanggil `$trip->delete()` — ini SOFT DELETE (trait `SoftDeletes` pada model `Trip`), hanya mengisi `deleted_at`, data terkait (`kiosk_visits`, `deliveries`, `settlements`, `delivery_origins`, `commissions`) TIDAK ikut terhapus karena tidak ada DB DELETE/FK cascade (komentar kode, `app/Http/Controllers/Owner/TripDeleteController.php:19-27`). `restore()` mengosongkan `deleted_at` (`app/Http/Controllers/Owner/TripDeleteController.php:35-45`). Hard delete permanen hanya lewat command Artisan `trip:force-delete`, tidak tersedia di UI. Otorisasi kedua aksi: `abort_unless($trip->owner_id === auth()->id() || auth()->user()->isSuperAdmin(), 403)` (`app/Http/Controllers/Owner/TripDeleteController.php:14-17,37-40`).

**Export** (`app/Http/Controllers/Owner/TripExportController.php`): otorisasi sama (`authorizeTrip()`, baris 15-21). PDF via `Pdf::loadView('exports.trip-report-pdf', ...)` (baris 77-84). Excel via `TripReportExport` (`app/Exports/TripReportExport.php`) dengan 2 sheet:
- Sheet **"Ringkasan"**, headings `['Keterangan', 'Nilai']` (`app/Exports/TripReportExport.php:39-42`), berisi baris: Tanggal Trip, Operator, Cluster, Mika Dibawa, Mika Di-drop, Biji Di-drop, Mika Sisa, Omset, HPP, Untung Kotor, Mika Komisi (Drop), Biji Komisi (Drop), "Komisi {operator}", Untung Bersih Owner (`app/Exports/TripReportExport.php:49-64`).
- Sheet **"Detail Kunjungan"**, headings `['Kios', 'Aksi', 'Waktu']` (`app/Exports/TripReportExport.php:80-83`).

---

### B4. Laporan Bulanan

Route `owner.reports.monthly` + export (`routes/web.php:102-107`) → `MonthlyReportController` (`app/Http/Controllers/Owner/MonthlyReportController.php`). Method statis `buildMonthlyData($month, $ownerId)` (baris 24-161) dipakai BERSAMA oleh halaman web, export PDF, dan export Excel — supaya angka tidak pernah beda antar format.

Halaman web (`resources/views/owner/reports/monthly.blade.php`): judul **"Laporan Bulanan"**, subjudul nama bulan-tahun terformat (mis. "Oktober 2026") (`resources/views/owner/reports/monthly.blade.php:7-11`). Form pemilih bulan: label **"Pilih Bulan"**, input `type="month"`, tombol **"Tampilkan"** (`resources/views/owner/reports/monthly.blade.php:14-23`). Tombol export: **"Export PDF"**, **"Export Excel"** (`resources/views/owner/reports/monthly.blade.php:26-35`).

4 kartu ringkasan atas (`resources/views/owner/reports/monthly.blade.php:38-55`):
- **"Kios Di-settle"** — jumlah kios unik yang ada transaksi settlement bulan ini (`analisisKios.total_kios_settled`, `app/Http/Controllers/Owner/MonthlyReportController.php:84-92,152`).
- **"Kios Baru"** — kios dengan `first_titip_date` di bulan ini DAN sudah punya minimal satu delivery yang ter-settle (bukan sekadar seed saldo awal) (`app/Http/Controllers/Owner/MonthlyReportController.php:109-117`).
- **"Total Omset"** — total `amount_paid` dari semua trip selesai bulan ini (dari `TripAggregator`, dirangkum di `$totals['omset']`).
- **"Untung Bersih"** — total `untung_bersih` semua trip selesai bulan ini.

3 kartu baris berikut (`resources/views/owner/reports/monthly.blade.php:57-72`):
- **"Total Mika Diantar"** (+biji) — total `qty_delivered` semua delivery trip bulan ini (`app/Http/Controllers/Owner/MonthlyReportController.php:124-134`).
- **"Modal Mika"** — `total_mika_diantar × harga_mika` (harga_mika dari setting owner, fallback 200) (`app/Http/Controllers/Owner/MonthlyReportController.php:120,136`).
- **"Rekap Komisi Operator"** — `SUM(commission_amount)` dari model `Commission` untuk trip bulan ini (`app/Http/Controllers/Owner/MonthlyReportController.php:138-142`).

Tabel **"Rekap Per Trip"** (`resources/views/owner/reports/monthly.blade.php:75-125`) kolom: **Tanggal**, **Operator**, **Kios**, **Mika Dititip** (+biji), **Omset**, **Komisi**, **Untung Bersih**, dengan baris footer **"TOTAL"** (`resources/views/owner/reports/monthly.blade.php:110-122`). Setiap baris dibangun per-trip (`$rows`, `app/Http/Controllers/Owner/MonthlyReportController.php:47-59`): `kios_dikunjungi` = jumlah kunjungan (SEMUA, bukan hanya aktif), `mika_drop`/`biji_drop` = sum delivery, `omset`/`komisi`/`untung_bersih` dari agregat batch `TripAggregator`. Kosong → **"Belum ada trip selesai di bulan ini."** (baris 106-108).

Catatan: tabel **"Analisis Frekuensi Kios"** (dari `analisisKios.frekuensi`) TIDAK ditampilkan di halaman web ini — hanya dikirim sebagai data untuk export PDF dan Excel (lihat di bawah). Di halaman web, hanya ringkasan (`total_kios_settled`, `kios_baru`) yang tampil sebagai kartu.

**Export PDF** (`resources/views/exports/monthly-report-pdf.blade.php`): header nama bisnis + **"Laporan Bulanan — {Bulan Tahun}"** (baris 26-27). Kartu: **"Total Kios Di-settle"**, **"Kios Baru Bulan Ini"**, **"Total Omset"**, **"Untung Bersih"** (baris 30-36). Tabel **"Rekap Per Trip"** sama seperti web + baris TOTAL (baris 39-66). Tabel **"Omset Harian"** berupa bar chart horizontal sederhana per tanggal (baris 68-79, hanya jika ada data). Tabel **"Analisis Frekuensi Kios"** kolom **Nama Kios**, **Area**, **Kunjungan**, **Ditagih** (baris 81-94) — INI satu-satunya tempat data frekuensi kunjungan per kios ditampilkan ke owner secara visual. Footer: **"Digenerate oleh sistem dodol-app · {tanggal jam}"** (baris 96-98).

**Export Excel** (`app/Exports/MonthlyReportExport.php`), 2 sheet:
- Sheet **"Rekap Trip"**, headings `['Tanggal', 'Operator', 'Kios Dikunjungi', 'Mika Drop', 'Biji Drop', 'Omset', 'Komisi', 'Untung Bersih']` + baris TOTAL (`app/Exports/MonthlyReportExport.php:38-60`).
- Sheet **"Analisis Kios"**, headings `['Nama Kios', 'Cluster', 'Jumlah Kunjungan', 'Jumlah Settle']` (`app/Exports/MonthlyReportExport.php:75-89`).

---

### B5. Manajemen Kios

Diakses lewat sidebar Blade label **"Manajemen Kios"** → route Filament `filament.owner.resources.kiosks.index` (`resources/views/layouts/owner.blade.php:43`), menuju `App\Filament\Resources\KioskResource` (terdaftar di panel owner, `app/Providers/Filament/OwnerPanelProvider.php:49`). Properti resource: `navigationLabel = 'Kios'`, `navigationGroup = 'Master Data'`, `modelLabel`/`pluralModelLabel = 'Kios'` (`app/Filament/Resources/KioskResource.php:31,33,37,39`).

**Kolom tabel** (verbatim label, `app/Filament/Resources/KioskResource.php:450-603`):
- Foto (ImageColumn, label kosong) — pakai `Kiosk::photo_url` (proxy same-origin, bukan R2 langsung), placeholder SVG kalau belum ada foto, auto-retry sekali kalau gagal load (`app/Filament/Resources/KioskResource.php:451-469`).
- **"Urutan"** — `TextInputColumn` angka yang bisa diketik langsung untuk mengubah urutan kios dalam areanya (`Kiosk::reorderWithinCluster`, anti-duplikat — kios lain otomatis tergeser) (`app/Filament/Resources/KioskResource.php:471-484`).
- **"Nama Kios"** — searchable, sortable, bold, dengan deskripsi = nama pemilik kedai (`app/Filament/Resources/KioskResource.php:486-491`).
- **"Area"** — `cluster.name`, badge warna primary (`app/Filament/Resources/KioskResource.php:493-498`).
- **"Titipan"** — badge dinamis: **"Cash Only"** (kuning) untuk kedai cash-only; **"{N} mika"** (hijau) untuk konsinyasi dengan titipan berjalan; **"Booking"** (abu) untuk kedai booking belum ada dodol; **"Belum ada titipan"** (merah, dianggap anomali) untuk konsinyasi tanpa titipan (`app/Filament/Resources/KioskResource.php:506-550`).
- **"Alamat"** — `location_description`, truncate 50 char, disembunyikan default (`app/Filament/Resources/KioskResource.php:552-556`).
- **"Status"** — badge **"Aktif"**/**"Stop"** (`app/Filament/Resources/KioskResource.php:558-563`).
- **"Tunai"** — icon boolean `is_cash_only`, disembunyikan default (`app/Filament/Resources/KioskResource.php:565-568`).
- **"Jadwal"** — `target_visit_interval_days` + " hari", disembunyikan default (`app/Filament/Resources/KioskResource.php:570-574`).
- **"Laris"** — `fast_mover_threshold_days` + " hari", disembunyikan default (`app/Filament/Resources/KioskResource.php:576-580`).
- **"GPS"** — link **"Maps"** (atau "—") membuka `maps_url` di tab baru (`app/Filament/Resources/KioskResource.php:582-589`).
- **"Pertama Titip"** — `first_titip_date`, disembunyikan default (`app/Filament/Resources/KioskResource.php:591-596`).
- **"Dibuat"** — `created_at`, disembunyikan default (`app/Filament/Resources/KioskResource.php:598-602`).

**Urutan default & reorder/drag** (`app/Filament/Resources/KioskResource.php:604-622`): tabel diurutkan per-AREA dulu (nama cluster), lalu `sort_order` (NULL turun ke bawah dalam area-nya), lalu nama — bukan abjad global, mencerminkan rute kunjungan fisik operator per area. **Drag reorder** (`->reorderable('sort_order', ...)`) HANYA aktif (tombol drag HANYA muncul) ketika filter **"Area"** di tabel sedang memilih TEPAT SATU area — kalau tidak, tombol drag disembunyikan total, karena mode reorder native Filament akan mematikan grouping/sort custom dan bisa menomori ulang lintas-area kalau dipaksa aktif tanpa filter tunggal (komentar kode, `app/Filament/Resources/KioskResource.php:615-622`).

**Filter tabel** (`app/Filament/Resources/KioskResource.php:623-662`): **"Area"** (multi-select, scoped ke cluster milik owner kecuali super admin), **"Status"** (ternary, trueLabel **"Aktif"**, falseLabel **"Stop"**), **"GPS"** (ternary, trueLabel **"Ada GPS"**, falseLabel **"Belum ada GPS"**), **"Titipan Berjalan"** (ternary, trueLabel **"Ada titipan"**, falseLabel **"Belum ada titipan"** — dipertahankan untuk monitoring anomali, bukan sekadar alat backfill).

**Aksi per baris** (`app/Filament/Resources/KioskResource.php:664-713`):
- **"Stop Titipan"** (danger, hanya tampil jika kios aktif) — modal konfirmasi **"Stop Titipan Kios"**, deskripsi **"Kios akan dihentikan titipannya dan tidak muncul di daftar kunjungan trip. Data historis tetap ada."**, form wajib pilih **"Alasan Stop"** (opsi dari `Kiosk::STOP_REASONS`). Mengisi `is_active=false`, `stopped_at=now()`, `stop_reason`, `stopped_by='owner'`.
- **"Aktifkan Kembali"** (success, hanya tampil jika kios TIDAK aktif) — modal **"Aktifkan Kembali Kios"**, deskripsi **"Kios akan kembali muncul di daftar kunjungan trip."** Mengosongkan `stopped_at/stop_reason/stopped_by`, set `is_active=true`.
- `ViewAction`, `EditAction`, `DeleteAction` (dengan konfirmasi) standar Filament.
- Bulk action: `DeleteBulkAction` (dengan konfirmasi) (`app/Filament/Resources/KioskResource.php:715-719`).

**Field form** (`app/Filament/Resources/KioskResource.php:47-444`), dibagi per Section:
- **"Informasi Dasar"**: **"Nama Kios"** (wajib); **"Area"** (select relationship `cluster_id`, wajib, hanya cluster aktif milik owner kecuali super admin, bisa buat area baru inline lewat `createOptionForm` dengan field **"Nama Area"** + **"Deskripsi"**); **"Nama Pemilik"**; **"Catatan Kedai"** (textarea `store_note`, helper: *"Kebiasaan/karakteristik kedai. Tampil menonjol ke operator saat buka kedai."*); **"Nomor Telepon"**; **"Tanggal Pertama Titip"** (helper: *"Optional. Untuk hitung umur kios sebagai customer. Kosongkan untuk auto-set saat delivery pertama."*).
- **"Jenis Kedai & Jatah Titipan"** — deskripsi section verbatim: *"Konsinyasi: dodol dititip, dibayar pas laku. Cash-only: beli putus tiap kali (tak ada titipan/jatah). Jatah titipan sekaligus jadi titipan awal — operator yang cek sisa fisiknya di lapangan."* Radio **"Jenis Kedai"** (hanya saat create) dengan opsi: **"🏪 Kedai Konsinyasi (titipan)"**, **"💵 Kedai Cash-Only (beli putus)"**, **"📌 Kedai Booking (belum ada dodol)"**, masing-masing dengan deskripsi penjelas. Field **"Jatah Titipan (mika per kunjungan)"** (numerik, default dari setting owner, min 1 maks 100, suffix "mika").
- **"Lokasi"** (collapsible): **"Alamat Lengkap"** (textarea); **"Tempel Link / Koordinat Google Maps"** (field bantu, tidak disimpan) dengan tombol aksi "loncat ke lokasi" yang mem-parsing koordinat/link Maps (termasuk link pendek via resolver) — notifikasi: *"Tempel link atau koordinat dulu."* (warning), *"Link pendek tidak bisa dibaca"* / *"Buka dulu link-nya di browser, lalu tempel link panjang atau koordinatnya di sini."* (danger), *"Format tidak dikenali"* / *"Coba tempel koordinat langsung, contoh: 3.5896, 98.6739"* (danger), *"Lokasi ditemukan!"* (success); lalu peta **"Lokasi Kios"** (Leaflet custom, klik untuk pindah titik, tile OpenStreetMap sejak perbaikan 20 Okt 2026 — sebelumnya CARTO Voyager yang mewajibkan API key di zoom dekat, akar bug "peta hilang").
- **"Foto & Konfigurasi"** (collapsible): **"Foto Kios"** (upload gambar + editor, auto-resize ke sisi maks tertentu, menerima HEIC yang dikonversi ke JPG di server, helper: *"Foto besar dari kamera HP tak masalah — dikecilkan otomatis. Format: JPG, PNG, WEBP, HEIC. Opsional."*) plus tombol kamera; **"Peringatan kalau belum dikunjungi (hari)"** (default 14); **"Idealnya dikunjungi tiap berapa hari (hari)"** (default 10); **"Batas kios laris (hari)"** (default 7, opsional, helper: *"Kalau dodol biasanya habis di bawah sekian hari, kios ini dianggap laris. Kosongkan kalau tidak dipantau."*); Toggle **"Status Aktif"** (default aktif); Toggle **"Kios Bayar Tunai Langsung (Cash-Only)"** (hanya tampil saat edit).
- **"Riwayat Stop Titipan"** (hanya tampil jika kios sedang berhenti): placeholder **"Tanggal Stop"**, **"Alasan"**, **"Dihentikan Oleh"**.

Scoping data: `getEloquentQuery()` selalu mengecualikan kios sentinel walk-in (untuk semua role termasuk super admin), menambahkan subquery `withSum` untuk kolom Titipan, dan membatasi ke kios milik cluster owner yang login (kecuali super admin) (`app/Filament/Resources/KioskResource.php:723-744`).

**Manajemen Area (Cluster)** — menu sidebar **"Manajemen Area"** (`resources/views/layouts/owner.blade.php:44`) membuka `ClusterResource` (`navigationLabel = 'Area'`, `modelLabel = 'Area'`, `app/Filament/Resources/ClusterResource.php:21,27`). Form: **"Nama Area"** (wajib, unik), **"Deskripsi"** (textarea), Toggle **"Status Aktif"** (helper: *"Nonaktifkan kalau cluster ini sudah tidak digunakan"*) (`app/Filament/Resources/ClusterResource.php:35-52`). Tabel kolom: **"Nama Area"**, **"Deskripsi"**, **"Aktif"** (icon boolean), **"Total Kios"** (badge hitungan relasi `kiosks`), **"Dibuat"** (`app/Filament/Resources/ClusterResource.php:60-87`). Filter **"Status"** (ternary, trueLabel **"Hanya Aktif"**, falseLabel **"Hanya Nonaktif"**). Aksi View/Edit/Delete standar + bulk delete. Scoping sama pola dengan Kios: cluster sentinel walk-in selalu disembunyikan, dibatasi `owner_id` kecuali super admin (`app/Filament/Resources/ClusterResource.php:111-123`).

Detail Supplier dan Produk tidak dibahas mendalam di bagian ini (dibahas oleh bagian lain dari dokumentasi), namun keduanya diakses dari sidebar yang sama: **"Manajemen Supplier"** → `filament.owner.resources.suppliers.index`, dan "Stok Masuk" → `filament.owner.resources.procurement-batches.index` (`resources/views/layouts/owner.blade.php:45,47`).

### B6. Area / Supplier / Stok Masuk

#### B6.1 Area (`ClusterResource`)

Navigasi: **Master Data › Area**
Sumber: `app/Filament/Resources/ClusterResource.php`

**Form (Tambah / Edit):**

| Field | Tipe | Label UI (verbatim) | Keterangan |
|---|---|---|---|
| `name` | TextInput | `Nama Area` | Required, maxLength 100, unique, placeholder `Contoh: Marelan, Tembung, Pasar 4` |
| `notes` | Textarea | `Deskripsi` | Optional, rows 3, maxLength 500, placeholder `Catatan tentang cluster ini (optional)`, columnSpanFull |
| `toggle is_active` | Toggle | `Status Aktif` | Default `true`, helperText `Nonaktifkan kalau cluster ini sudah tidak digunakan` |

**Kolom Tabel:**

| Kolom | Label (verbatim) |
|---|---|
| `name` | `Nama Area` (bold, searchable, sortable) |
| `notes` | `Deskripsi` (limit 50, wrap, toggleable) |
| `is_active` | `Aktif` (icon boolean, sortable) |
| `kiosks_count` | `Total Kios` (counts via `kiosks` relation, badge, alignCenter) |
| `created_at` | `Dibuat` (d M Y, toggleable, hidden by default) |

**Filter:** TernaryFilter `Status` — opsi `Semua` / `Hanya Aktif` / `Hanya Nonaktif`. (`ClusterResource.php:91–96`)

**Aksi baris:** View, Edit, Delete (requiresConfirmation). Bulk: DeleteBulkAction (requiresConfirmation). (`ClusterResource.php:97–108`)

**Multi-tenant & Scope:**
- Cluster sentinel walk-in (`name` berawalan `__walkin_owner_`) disembunyikan dari panel — termasuk super admin — via `->excludeWalkInSentinel()`. (`ClusterResource.php:115–116`)
- Super admin: lihat semua cluster. Owner: hanya cluster miliknya (`where('owner_id', auth()->id())`). (`ClusterResource.php:118–122`)

**Halaman:** index `/`, create `/create`, edit `/{record}/edit`.

---

#### B6.2 Supplier (`SupplierResource`)

Navigasi: **Master Data › Supplier**
Sumber: `app/Filament/Resources/SupplierResource.php`

**Form:**

| Field | Tipe | Label (verbatim) | Keterangan |
|---|---|---|---|
| `name` | TextInput | `Nama Supplier` | Required, maxLength 255, unique, placeholder `Contoh: Aidil (Abg)` |
| `phone` | TextInput (tel) | `Nomor Telepon` | Optional, maxLength 20, helperText `Optional, untuk WhatsApp atau telepon supplier` |
| `relationship_type` | Select | `Tipe Hubungan` | Opsi: `Supplier Langsung` / `Aggregator` / `Perantara`, default `direct_supplier`, required |
| `notes` | Textarea | `Catatan` | Optional, rows 3, maxLength 500, columnSpanFull |
| `is_active` | Toggle | `Status Aktif` | Default `true`, helperText `Nonaktifkan kalau supplier ini sudah tidak digunakan` |

**Kolom Tabel:**

| Kolom | Label (verbatim) | Catatan |
|---|---|---|
| `name` | `Nama Supplier` | bold, searchable, sortable |
| `phone` | `Telepon` | searchable, copyable, copyMessage `Nomor telepon disalin` |
| `relationship_type` | `Tipe` | badge; warna: success/warning/gray; label: Supplier Langsung/Aggregator/Perantara |
| `notes` | `Catatan` | limit 50, wrap, toggleable |
| `is_active` | `Aktif` | icon boolean |
| `created_at` | `Dibuat` | hidden by default |

**Filter:** TernaryFilter `Status` identik Area. (`SupplierResource.php:127–132`)

**Aksi baris:** View, Edit, Delete (requiresConfirmation). Bulk: DeleteBulkAction.

**Scope:** Super admin lihat semua; owner hanya `where('owner_id', auth()->id())`. (`SupplierResource.php:148–156`)

---

#### B6.3 Stok Masuk / Pengadaan (`ProcurementBatchResource`)

Navigasi: **Transaksi › Pengadaan**
Sumber: `app/Filament/Resources/ProcurementBatchResource.php`

**Form — Section "Supplier & Produk":**

| Field | Label (verbatim) | Keterangan |
|---|---|---|
| `supplier_id` | `Supplier` | Select relationship, hanya supplier aktif milik owner, searchable, preload, required, helperText `Supplier asal pembelian` |
| `product_variant_id` | `Varian Produk` | Select relationship, hanya varian aktif milik owner (via product.owner_id), label: `{product.name} - {variant.name}`, required, helperText `Varian dodol yang dibeli` |

**Form — Section "Detail Pembelian":**

| Field | Label (verbatim) | Keterangan |
|---|---|---|
| `purchase_date` | `Tanggal Beli` | DatePicker, required, default hari ini, maxDate today |
| `expiry_date` | `Tanggal Kadaluarsa` | DatePicker, required, harus after purchase_date, helperText `Tanggal kadaluarsa dodol dalam batch ini` |

**Form — Section "Jumlah (Hasil Repackaging)":**

| Field | Label (verbatim) | Keterangan |
|---|---|---|
| `qty_kg_raw` | `Qty Pembelian Mentah` | Numeric, suffix `kg`, min 0.1, step 0.01, helperText `Berapa kg dodol mentah dibeli dari supplier` |
| `qty_packs` | `Qty Mika Jadi (Actual)` | Numeric, suffix `mika`, min 1, max 10000, helperText `Jumlah mika ACTUAL setelah repackaging, sudah dikurangi mika rusak/penyet. Bukan estimate!` |

**Form — Section "Biaya":**

| Field | Label (verbatim) | Keterangan |
|---|---|---|
| `cost_raw_material` | `Biaya Dodol Mentah` | Numeric, prefix Rp, required, helperText `Harga beli dodol mentah dari supplier` |
| `cost_packing` | `Biaya Repackaging` | Numeric, prefix Rp, optional, default 0, helperText `Optional. Biaya proses repackaging (tenaga kerja, listrik, dll)` |
| `cost_packaging` | `Biaya Kemasan` | Numeric, prefix Rp, optional, default 0, helperText `Optional. Biaya material mika + plastik + label` |
| `cost_other` | `Biaya Lain-Lain` | Numeric, prefix Rp, optional, default 0, helperText `Optional. Biaya transport, parkir, dll` |

**Form — Section "Auto-Calculated (Read-Only Preview)" (collapsible, default collapsed):**

- `batch_number_preview` — label `Nomor Batch (Auto)`, helperText `Auto-generated format: BATCH-YYYY-MM-DD-NNN`
- `total_cost_preview` — label `Total Biaya (Otomatis)`, dihitung dari sum keempat komponen biaya
- `cost_per_pack_preview` — label `HPP per Mika (Auto)`, = total_cost / qty_packs, helperText `HPP per mika = total_cost / qty_packs`
- `price_per_kg_raw_preview` — label `Harga per Kg Mentah (Auto)`, = cost_raw_material / qty_kg_raw

**Form — Section "Catatan" (default collapsed):**

- `notes` — label `Catatan`, placeholder `Contoh: 20kg dapat 70 mika, 2 mika penyet, 3 mika rusak`, helperText `Catatan kondisi batch, loss repackaging, dll`

**Kolom Tabel:**

| Kolom | Label (verbatim) | Catatan |
|---|---|---|
| `batch_number` | `Batch` | bold, copyable; sort pakai created_at |
| `purchase_date` | `Tanggal Beli` | date d M Y |
| `supplier.name` | `Supplier` | badge, primary |
| `productVariant.name` | `Varian` | format: `{product.name} - {variant.name}`, toggleable |
| `qty_kg_raw` | `Pembelian` | suffix ` kg` |
| `qty_packs` | `Mika Jadi` | suffix ` mika`, bold |
| `stok_tersisa` | `Stok Tersisa` | badge; merah=habis, kuning=hampir habis, hijau=ada; total via SQL summarizer |
| `total_cost` | `Total Biaya` | money IDR |
| `cost_per_pack` | `HPP/Mika` | money IDR, bold, color success |
| `expiry_date` | `Kadaluarsa` | merah bila expired, kuning < 7 hari, hijau otherwise; toggleable |
| `created_at` | `Dibuat` | hidden by default |

**Filter:**
- `supplier_id` — `Supplier` (multi-select, di-scope owner)
- `product_variant_id` — `Varian Produk` (multi-select, di-scope owner)
- `expired` — `Status Expiry`: opsi `Sudah Expired` / `Expire < 7 Hari` / `Masih Valid`

**Aksi:** View, Edit, Delete (requiresConfirmation). Bulk: DeleteBulkAction.

**Scope:** Super admin lihat semua; owner `where('owner_id', auth()->id())`. (`ProcurementBatchResource.php:363–371`)

---

### B7. Produk & Varian Produk

#### B7.1 Produk (`ProductResource`)

Navigasi: **Master Data › Produk**
Sumber: `app/Filament/Resources/ProductResource.php`

**Form:**

| Field | Label (verbatim) | Keterangan |
|---|---|---|
| `name` | `Nama Produk` | Required, maxLength 255, placeholder `Contoh: Dodol Coklat Susu` |
| `category` | `Kategori` | Select; opsi: `Dodol` / `Kripik` / `Opak` / `Lainnya`; default `dodol`, required |
| `notes` | `Catatan` | Optional, rows 3, maxLength 500, columnSpanFull |
| `is_active` | `Status Aktif` | Toggle, default true, helperText `Nonaktifkan kalau produk ini sudah tidak dijual` |

**Kolom Tabel:**

| Kolom | Label (verbatim) | Catatan |
|---|---|---|
| `name` | `Nama Produk` | bold |
| `category` | `Kategori` | badge; dodol=success, kripik=warning, opak=info, other=gray |
| `variants_count` | `Total Varian` | counts via `variants`, badge |
| `is_active` | `Aktif` | icon boolean |
| `created_at` | `Dibuat` | hidden by default |

**Filter:** SelectFilter `Kategori` + TernaryFilter `Status`.

**Scope:** Super admin lihat semua; owner `where('owner_id', auth()->id())`. (`ProductResource.php:136–144`)

---

#### B7.2 Varian Produk (`ProductVariantResource`)

Navigasi: **Master Data › Varian Produk**
Sumber: `app/Filament/Resources/ProductVariantResource.php`

**Form:**

| Field | Label (verbatim) | Keterangan |
|---|---|---|
| `product_id` | `Produk` | Select relationship; hanya produk aktif milik owner; required |
| `name` | `Nama Varian` | Required, maxLength 255, placeholder `Contoh: Mika 15 biji, Pack 20 biji` |
| `units_per_pack` | `Isi per Pack` | Numeric, required, default **15**, min 1, max 1000, suffix `biji`, helperText `Berapa biji dodol dalam 1 pack/mika` |
| `sale_price_per_pack` | `Harga Jual per Pack` | Numeric, required, min 0, prefix Rp, placeholder `12000`, helperText `Harga jual ke kios per pack/mika` |
| `cost_per_pack` | `HPP per Pack` | Numeric, required, min 0, prefix Rp, placeholder `9500`, helperText `Harga pokok produksi per pack/mika — dasar hitung untung kotor. Dicatat (snapshot) di setiap titipan baru; ubah nilai ini TIDAK mengubah titipan yang sudah berjalan.` |
| `sku` | `SKU` | Optional, maxLength 100, unique, placeholder `Contoh: DDL-CSU-MK15`, helperText `Optional. Kode unik untuk varian ini` |
| `is_active` | `Status Aktif` | Toggle, default true, helperText `Nonaktifkan kalau varian ini sudah tidak dijual` |

**Kolom Tabel:**

| Kolom | Label (verbatim) |
|---|---|
| `product.name` | `Produk` (badge, primary) |
| `name` | `Nama Varian` (bold) |
| `units_per_pack` | `Isi` (suffix ` biji`, center) |
| `sale_price_per_pack` | `Harga Jual` (money IDR) |
| `cost_per_pack` | `HPP` (money IDR, toggleable) |
| `sku` | `SKU` (copyable, toggleable) |
| `is_active` | `Aktif` (icon boolean) |
| `created_at` | `Dibuat` (hidden by default) |

**Filter:** SelectFilter `Produk` (di-scope owner) + TernaryFilter `Status`.

**Scope:** Owner via `whereHas('product', fn => where('owner_id', auth()->id()))`. (`ProductVariantResource.php:180–189`)

---

#### B7.3 Catatan Snapshot HPP & Units-per-Pack pada Delivery

Sumber: `app/Models/Delivery.php`, `app/Support/ConsignmentDrop.php`, `app/Models/Trip.php`

**Mekanisme snapshot saat titipan dibuat:**
Setiap kali `ConsignmentDrop::record()` membuat delivery, ia menulis:
- `cost_snapshot` ← `$variant->cost_per_pack` (HPP per mika saat itu) (`ConsignmentDrop.php:55`)
- `units_per_pack_snapshot` ← `$variant->units_per_pack` (isi per mika saat itu) (`ConsignmentDrop.php:56`)
- `unit_price` ← `$variant->sale_price_per_pack` (harga jual per mika saat itu) (`ConsignmentDrop.php:54`)

**Fallback NULL:** Accessor `Delivery::getQtyBijiDeliveredAttribute()` menggunakan `max(1, (int)($this->units_per_pack_snapshot ?? 15))` — fallback 15 defensif untuk baris delivery lama sebelum kolom ini ada. (`Delivery.php:91–92`)

**Implikasi:** Ubah `cost_per_pack` atau `units_per_pack` di varian produk **TIDAK** mengubah perhitungan titipan yang sudah berjalan. Setiap delivery membawa snapshot nilai saat dititip. (`ProductVariantResource.php:80` — helperText verbatim di atas)

**Kalkulasi untung pakai snapshot, bukan HPP global owner:**
`Trip::salesSnapshotTotals()` membaca `cost_snapshot` per baris delivery. Fallback: jika `cost_snapshot` NULL, pakai `$this->owner->hpp_per_mika ?? 9500`. (`Trip.php:222–234`)

---

### B8. Manajemen Anggota / Operator

#### B8.1 Operator (sisi Owner — `OperatorResource`)

Navigasi: **Master Data › Operator** (hanya di panel owner)
Sumber: `app/Filament/Resources/OperatorResource.php`

**Form Tambah Operator:**

| Field | Label (verbatim) | Keterangan |
|---|---|---|
| `name` | `Nama Lengkap` | Required, maxLength 255, placeholder `Contoh: Rian Saputra` |
| `email` | `Email` | Required, unique, email, disimpan lowercase+trim, placeholder `operator@cemilanqontas.id` |
| `password` | `Password` | Required saat Create, min 8, autocomplete `new-password`, helperText saat Edit: `Kosongkan kalau tidak ingin mengubah password` |
| `is_active` | `Status Aktif` | Toggle, default true, helperText `Nonaktifkan untuk disable login operator ini` |

**Catatan:** Field komisi persen (`commission_rate`) **sudah dihapus** dari form. Komentar kode: `Field 'Tarif Komisi' persen DIBUANG: legacy, tak dipakai bayar. Komisi operator = Rp/mika drop dari setting owner (owner/settings).` (`OperatorResource.php:76–77`)

Section **Komisi Khusus** — ditambahkan 23 Okt 2026 (**D9**, lihat juga **B8.2**):
- `komisi_per_mika_khusus` — label verbatim `Komisi Khusus per Mika (Rp)`, numerik, nullable, min 0. Helper text dinamis: `Kosongkan untuk ikut tarif default usaha (Rp X.XXX). Berlaku untuk trip yang dimulai SETELAH disimpan.` — di mana `X.XXX` adalah tarif komisi OWNER YANG LOGIN (`auth()->user()->getKomisiPerMikaValue()`; resource ini selalu dilihat owner, tidak ada field `owner_id` untuk dipilih seperti di UserResource). Rumus tarif default dipakai ULANG dari `UserResource` lewat trait `App\Filament\Concerns\HasKomisiKhususOperatorField::resolveOwnerDefaultKomisiRate()` — tidak ada rumus dobel. Kosong = ikut tarif default usaha; diisi = override satu tarif flat khusus operator ini. (`OperatorResource.php:88–115`)
- Field ini **TIDAK menyentuh trip yang sudah berjalan** — hanya dibaca saat trip BARU dimulai (`User::getEffectiveKomisiPerMikaValue()` dipanggil dari `StartTrip::startTrip()`, dibekukan ke `trips.komisi_per_mika_snapshot`; lihat **C7**).

**Kolom Tabel:**

| Kolom | Label (verbatim) | Catatan |
|---|---|---|
| `name` | `Nama` | bold |
| `email` | `Email` | copyable |
| `komisi_per_mika` | `Komisi /Mika` | **Diubah 23 Okt 2026 (D9)** — dulu seragam (tarif owner login utk semua baris), sekarang PER BARIS: `$record->getEffectiveKomisiPerMikaValue()` + suffix, format `Rp X.XXX (khusus)` (kalau `komisi_per_mika_khusus` diisi) atau `Rp X.XXX (ikut default)` (kalau kosong, ikut tarif owner). `->with('owner')` di `getEloquentQuery()` mencegah N+1 (dites `UserListQueryCountTest::test_daftar_operator_owner_query_konstan`). |
| `is_active` | `Aktif` | icon boolean |
| `created_at` | `Dibuat` | hidden by default |

**Aksi:**
- **Edit** — mutateFormDataUsing otomatis set `role = 'operator'` dan `owner_id = auth()->id()`. (`OperatorResource.php:132–134`)
- **Delete** — requiresConfirmation; tombol **DISABLED** (tidak bisa diklik) jika operator punya trip atau komisi; tooltip menampilkan alasan. (`OperatorResource.php:145–146`)

**Aturan Hapus Operator:**
- Operator yang punya trip atau komisi: tombol hapus disabled + tooltip menampilkan pesan `"Operator ini punya {N} trip, {M} komisi. Nonaktifkan saja, jangan hapus."` — guard berlapis UI (disabled) + server-side (`->before()`). (`User.php:254–261`, `OperatorResource.php:148–153`)
- Operator tanpa data dependensi: bisa dihapus permanen.
- **TIDAK ADA soft-delete/arsip untuk akun operator** — sistem hanya kenal `is_active` (nonaktif = login diblokir, data tetap) atau hapus permanen (hanya jika tidak ada data).

**Scope:** Owner hanya melihat operator `role = 'operator'` yang `owner_id = auth()->id()`; akun sistem (`operator.migrasi.*`) disembunyikan via `->excludeSystem()`. (`OperatorResource.php:178–188`)

---

#### B8.2 Manajemen User (sisi Super Admin — `UserResource`)

Navigasi: **Manajemen User** (Filament panel, hanya super admin + owner)
Sumber: `app/Filament/Resources/UserResource.php`

**Form:**

Section **Informasi Akun:** `name` (Nama Lengkap), `email`, `password` — identik OperatorResource.

Section **Role & Akses:**
- `role` — Select; opsi: `super_admin` (hanya tampil jika belum ada super_admin lain), `Owner`, `Operator`. Guard: user tidak bisa mengubah role dirinya sendiri (dropdown disabled), helperText: `Tidak bisa mengubah role akun sendiri (mencegah kehilangan akses panel).` (`UserResource.php:104–110`)
- `owner_id` — Select `Owner`; hanya muncul jika super admin sedang edit operator (visible + required conditional). (`UserResource.php:118–119`)
- `is_active` — Toggle; user tidak bisa menonaktifkan akunnya sendiri. (`UserResource.php:127–130`)

Section **Komisi Khusus Operator** (visible hanya jika `role = 'operator'`) — ditambahkan 21 Okt 2026:
- `komisi_per_mika_khusus` — `Komisi Khusus (Rp)`, numerik, nullable, min 0. Helper text dinamis: `Kosongkan untuk ikut tarif default usaha (Rp X.XXX)` — di mana `X.XXX` adalah tarif komisi OWNER yang terkait operator ini (owner viewer: dirinya sendiri; super admin: owner yang dipilih di field `owner_id`, field itu `->live()` supaya helper ikut berubah). Kosong = ikut tarif default usaha; diisi = override satu tarif flat khusus operator ini. (`UserResource.php:149–164`)
- Field ini **TIDAK menyentuh trip yang sudah berjalan** — hanya dibaca saat trip BARU dimulai (`User::getEffectiveKomisiPerMikaValue()` dipanggil dari `StartTrip::startTrip()`, lalu dibekukan ke `trips.komisi_per_mika_snapshot`; lihat **C7**).
- **Diperbaiki 23 Okt 2026 (D9 ✅ SELESAI)**: komentar kode di atas Section ini (`UserResource.php`, sekitar baris 138–140) kini menyebut KEDUA entry point yang sungguh ada — form `UserResource` ini (owner membuat/mengubah operatornya LEWAT Users, atau super admin lewat Edit) **DAN** `OperatorResource` (menu "Operator" di panel owner, lihat **B8.1**) yang sejak tanggal ini juga punya field `komisi_per_mika_khusus` sendiri. Dua form berbeda, satu kolom DB yang sama, rumus tarif default dibagi lewat trait `HasKomisiKhususOperatorField` (tidak dobel). Owner sekarang **bisa** mengatur komisi khusus operatornya sendiri tanpa lewat super admin.

Section **Tarif & Komisi** (visible hanya jika `role = 'owner'`):
- `harga_mika` — `Harga Mika (Rp)`, default 200, helper `Harga modal mika per kemasan. Default: Rp 200`
- `komisi_per_mika` — `Komisi Reguler (Rp)`, default 1000, helper `Komisi per mika yang diletakkan operator (basis drop). Default: Rp 1.000`
- **`komisi_kios_baru_per_mika` TIDAK ada di form ini** — dihapus 23 Okt 2026 (lihat **C8**, **D4**). Kolom DB sengaja tidak didrop.
- **`hpp_per_mika` TIDAK ada di form ini** — komentar: `hpp_per_mika DIHAPUS dari form ini — HPP sekarang per-varian produk (ProductVariant::cost_per_pack), bukan satu angka global per owner. Kolom tabel readonly super-admin (di bawah) DIBIARKAN — informasional, audit data lama, bukan jalur kalkulasi aktif.` (`UserResource.php:185–210`)

**Kolom Tabel** (super admin melihat semua; owner hanya lihat operatornya):
- `owned_active_kiosks_count` — `Kios`; hanya terisi untuk baris owner (jumlah kios aktif miliknya), `—` untuk operator/super admin. Dibaca dari `withCount('ownedKiosks')` yang sudah dimuat `getEloquentQuery()` — nol query tambahan per baris. Ditambahkan 21 Okt 2026. (`UserResource.php:271–275`)
- `operators_count` — `Operator`; sama seperti di atas, jumlah operator milik owner tersebut. (`UserResource.php:277–281`)
- `komisi_efektif_per_mika` — `Komisi Operator /Mika`. Baris **owner**: `Bayar Rp X.XXX` (tarif yang owner BAYAR, dari `getKomisiPerMikaValue()`). Baris **operator**: `Terima Rp X.XXX (khusus)` jika `komisi_per_mika_khusus` diisi, atau `Terima Rp X.XXX (ikut owner)` jika kosong (tarif efektif dari `getEffectiveKomisiPerMikaValue()`). Prefix Bayar/Terima ditambahkan 20 Okt 2026 (supaya baris owner tidak terbaca seolah owner MENERIMA komisi); suffix (khusus)/(ikut owner) ditambahkan 21 Okt 2026. (`UserResource.php:283–325`)
- `hpp_per_mika` — `HPP/Mika` — **HANYA visible untuk super admin**, `toggleable(hidden by default)` — informasional/audit saja. (`UserResource.php:327–331`)
- `harga_mika` — `Harga Mika` — HANYA super admin, toggleable hidden.
- `komisi_per_mika` — `Komisi Reguler` — HANYA super admin, toggleable hidden.
- **Kolom `komisi_kios_baru_per_mika` ("Komisi Kios Baru") DIHAPUS dari tabel** (23 Okt 2026) — field ini tidak berfungsi, lihat **C8**. (`UserResource.php:345–346`)

**Aturan Hapus (UserResource):**
- Super Admin: tombol hapus **disembunyikan total** (`->hidden()`). (`UserResource.php:313`)
- Akun sendiri: tombol hapus disembunyikan total.
- Owner berdata (punya kios/trip/operator): tombol tampil tapi disabled + tooltip `"Owner ini punya {N} kios, {M} trip, {O} operator. Nonaktifkan saja, jangan hapus."` (`User.php:245–251`)
- Operator berdata: disabled + tooltip seperti OperatorResource.
- Akun sistem (`operator.migrasi.*`): `deletionBlockReasonByRole()` mengembalikan `'Akun sistem tidak bisa dihapus.'` (`User.php:237–238`); disembunyikan dari listing.

**TIDAK ADA soft-delete/arsip untuk akun user (owner/operator).** Hanya `is_active` (nonaktif = blokir login) atau hapus permanen (diblokir jika ada data). (`User.php:155–161`, `OperatorResource.php:76–82`)

---

#### B8.3 Dashboard Super Admin — Widget "Leaderboard Owner Hari Ini"

Navigasi: Dashboard panel Admin (hanya super admin, `AdminPanelProvider`)
Sumber: `app/Filament/Widgets/OwnerPerformanceTable.php`

Widget tabel berjudul **“Leaderboard Owner Hari Ini”**, satu baris per owner, kolom lebar penuh. Hanya tampil untuk super admin (`canView()`). Kolomnya persis:
- **“Nama Owner”** — nama owner, searchable + sortable.
- **“Operator”** — jumlah operator milik owner tersebut.
- **“Kios Aktif”** — jumlah kios aktif milik owner tersebut.
- **“Trip Hari Ini”** — jumlah trip (real, bukan sentinel) hari ini.
- **“Omset Hari Ini”** — `Rp ...`.
- **“Komisi Operator Hari Ini”** — `Rp ...`.
- **“Untung Bersih Hari Ini”** — `Rp ...`.
- **“Total Trip Bulan Ini”** — jumlah trip bulan ini.
- **“Omset Bulan Ini”** — `Rp ...`.

**Catatan performa (dirombak 21 Okt 2026):** versi sebelumnya menembak 7 query terpisah PER BARIS owner (N×7, makin lambat makin banyak owner). Sekarang 2 query owner-level + `TripAggregator::for()` SATU KALI untuk semua owner sekaligus → total konstan ~9 query **berapa pun banyak owner** (diverifikasi oleh `OwnerPerformanceTableQueryCountTest`, 9 query baik untuk 1 owner maupun 5 owner).

Angka omset/komisi/untung bersih dibaca dari `TripAggregator` — rumus **identik** dengan accessor `Trip` (termasuk `komisi_per_mika_snapshot` per-trip, lihat **C7**), bukan tarif owner LIVE. Trip sentinel migrasi (**C14**) dikecualikan dari semua angka ini (lihat **C7**, **D8**).

---

### B9. Settings (PALING PENTING — cek paling teliti)

Halaman Settings owner: `GET /owner/settings`, `PUT /owner/settings/update`
Controller: `app/Http/Controllers/OwnerSettingsController.php`
View: `resources/views/owner/settings.blade.php`

**STATUS 23 OKTOBER 2026: SEMUA 8 PENGATURAN SUDAH PUNYA INPUT DAN DAPAT DISIMPAN OWNER.** Form dibagi menjadi tiga bagian agar kegunaan setiap nilai jelas. (Sebelumnya 9 pengaturan per 20 Okt 2026 — `komisi_kios_baru_per_mika` DIHAPUS dari form 23 Okt 2026 karena field ini non-fungsional, lihat **C8**, **D4**.)

---

#### B9.1 Identitas Usaha

1. **`business_name` — Nama Usaha**
   - Teks maksimal 60 karakter; kosong disimpan sebagai `null` sehingga accessor tetap memakai fallback **`Cemilan Qontas`**.
   - Nilai tampil pada header panel owner/operator, laporan bulanan, serta export PDF/Excel.
   - Helper menjelaskan bahwa nama ini digunakan di judul halaman, laporan, dan file unduhan.

---

#### B9.2 Harga & Komisi

1. **`harga_mika` — Harga Mika (Rp)**, angka minimal 0, fallback Rp 200.
2. **`komisi_per_mika` — Komisi Reguler per Mika (Rp)**, angka minimal 0, accessor fallback Rp 1.000. Helper text (diperbaiki 23 Okt 2026): `Komisi per mika yang diletakkan operator (basis drop). Default: Rp 1.000.` — sebelumnya salah menyebut `Default: Rp 500`, lihat **D2**.

`hpp_per_mika` tidak berada di form ini karena HPP aktif sekarang berasal dari biaya per varian produk (`ProductVariant::cost_per_pack`). `komisi_kios_baru_per_mika` tidak lagi berada di form ini (dihapus 23 Okt 2026) — lihat **C8**, **D4**.

---

#### B9.3 Default Kedai Baru

Bagian ini berisi lima nilai milik owner yang dipakai saat **membuat kios baru**:

1. **`default_target_visit_interval_days` — Ideal Dikunjungi Tiap (hari)**, 1–365, fallback **10**.
2. **`default_warning_visit_interval_days` — Peringatan Belum Dikunjungi (hari)**, 1–365, fallback **14**.
3. **`default_fast_mover_threshold_days` — Batas Kios Laris (hari)**, 1–365, fallback **7**.
4. **`kios_baru_days` — Masa "Kios Baru" (hari)**, 1–365, fallback **30**.
5. **`default_qty_mika` — Default Jatah Titipan Kedai Baru (mika)**, 1–100, fallback **1**.

Aturan silang: nilai **Peringatan Belum Dikunjungi harus lebih besar atau sama dengan Ideal Dikunjungi Tiap**. Jika lebih kecil, simpan ditolak dengan pesan yang menjelaskan hubungan kedua nilai tersebut.

Default interval dan jatah diterapkan pada jalur pembuatan kios owner (Filament) dan operator (Livewire). Perubahan hanya memengaruhi data **baru**; kios dan trip yang sudah ada tidak diubah. Form juga menegaskan aturan ini lewat helper text.

Controller hanya menjalankan `auth()->user()->update($data)` dan tidak menerima `owner_id` dari request, sehingga owner hanya mengubah pengaturan miliknya sendiri. Cakupan ini diverifikasi oleh `tests/Feature/Owner/SettingsTest.php`, termasuk persist/reload, validasi silang, header/PDF, kedua jalur pembuatan kios, dan isolasi antar-owner.

---

#### B9.4 Ringkasan Tabel Status Settings

| Bagian | Nilai | Kolom DB | Ada di Settings Form? | Fallback / batas |
|---|---|---|---|---|
| Identitas Usaha | Nama Usaha | `business_name` | **YA** | `Cemilan Qontas`; maks. 60 karakter |
| Harga & Komisi | Harga Mika | `harga_mika` | **YA** | Rp 200; min. 0 |
| Harga & Komisi | Komisi Reguler per Mika | `komisi_per_mika` | **YA** | Rp 1.000; min. 0 |
| Harga & Komisi | ~~Komisi Kios Baru per Mika~~ | `komisi_kios_baru_per_mika` | **TIDAK** (dihapus 23 Okt 2026) | Kolom DB tetap ada, default Rp 1.000; tidak lagi divalidasi/ditulis dari form ini — lihat **D4** |
| Default Kedai Baru | Interval Ideal Kunjungan | `default_target_visit_interval_days` | **YA** | 10 hari; 1–365 |
| Default Kedai Baru | Interval Peringatan Kunjungan | `default_warning_visit_interval_days` | **YA** | 14 hari; 1–365 dan ≥ ideal |
| Default Kedai Baru | Batas Kios Laris | `default_fast_mover_threshold_days` | **YA** | 7 hari; 1–365 |
| Default Kedai Baru | Masa Kios Baru | `kios_baru_days` | **YA** | 30 hari; 1–365 |
| Default Kedai Baru | Default Jatah Titipan | `default_qty_mika` | **YA** | 1 mika; 1–100 |

---

## C. ATURAN BISNIS (lampiran)

### C1. Model Tagihan & Titip (Serah Terima — ActiveTrip)

Sumber: `app/Livewire/Operator/ActiveTrip.php:29`

Ketika operator mengunjungi kios yang punya **titipan pending** (delivery konsinyasi tanpa settlement):
- **Tagih + Titip Ulang** — operator mencatat berapa yang terjual (settlement), lalu menetapkan jatah titipan baru (drop baru).
- **Titip Cash** — kios membayar muka, tanpa tagihan konsinyasi.
- **Lewati** — kunjungan dicatat tapi tidak ada aksi finansial.
- Blokir desain: tidak bisa "titip = jumlah jatah" tanpa tagih dulu (2-langkah).
- Tagihan **selalu** dihitung dari titipan lama (settled_delivery_id dari visit sebelumnya).

Sumber aturan 3-aksi: `MEMORY.md` (Serah Terima 3-AKSI model).

### C2. Alasan Akhir Trip

Sumber: `app/Livewire/Operator/ActiveTrip.php:29`

Konstanta `VALID_END_REASONS`:
```
['stock_habis', 'target_done', 'sakit', 'urgent_personal', 'other']
```

### C3. Satuan Mika vs Biji

- Semua DROP dan KOMISI berbasis **mika** (kemasan).
- Semua SETTLEMENT berbasis **biji** (`settlements.qty_sold` dalam satuan biji).
- Konversi: `biji = mika × units_per_pack_snapshot` (fallback 15). (`Delivery.php:74–76`, `Trip.php:80–82`)
- `BIJI_PER_MIKA = 15` (`ActiveTrip.php:25`) — konstanta lama, kini hanya dipakai fallback; nilai sebenarnya per-varian via snapshot.

### C4. Omset

Formula: `omset = SUM(settlements.amount_paid)` untuk semua settled_delivery_id aktif trip tersebut.
Sumber: `Trip::getOmsetValAttribute()` (`Trip.php:157–166`).

### C5. Mika Terjual (basis LAKU)

Formula per-baris delivery snapshot:
```
mika_terjual += qty_sold / units_per_pack_snapshot
```
Dipakai untuk **HPP estimasi** dan **untung kotor**. TERPISAH dari basis komisi.
Sumber: `Trip::salesSnapshotTotals()` (`Trip.php:188–249`).

### C6. Untung Kotor

```
untung_kotor = SUM(mika_row × (unit_price_snapshot - cost_snapshot))
```
Per baris delivery yang ter-settle. Fallback `cost_snapshot NULL → owner.hpp_per_mika ?? 9500`. (`Trip.php:234–239`)

### C7. Komisi Rian (basis DROP)

```
komisi_rian = mika_komisi × komisi_per_mika_trip
```
Dimana:
- `mika_komisi = getTotalDropReal()` = SUM deliveries kecuali `delivery_type = 'bs_redistribution'`, dipaksa `0` untuk trip sentinel migrasi (lihat **C14**). (`Trip.php:65–70`, `Trip.php:280–283`)
- `komisi_per_mika_trip` = **tarif BEKU per-trip** (`trips.komisi_per_mika_snapshot`), bukan lagi tarif owner/operator LIVE. Accessor: `(float) ($this->komisi_per_mika_snapshot ?? $this->owner?->komisi_per_mika ?? 1000)` — fallback lama (owner/1000) hanya untuk trip pra-fitur ini yang belum di-backfill. (`Trip.php:219–231`)
- Nilai snapshot **ditulis sekali** saat trip dimulai: `StartTrip::startTrip()` memanggil `auth()->user()->getEffectiveKomisiPerMikaValue()` (operator yang login) dan membekukannya ke kolom tersebut — perubahan tarif owner/operator SETELAHNYA tidak mengubah trip yang sudah berjalan. (`StartTrip.php:360,373`)
- `User::getEffectiveKomisiPerMikaValue()` = `komisi_per_mika_khusus` operator (jika diisi, lihat **B8.2**) atau tarif default owner. (`User.php:317–330`)
- Trip lama (sebelum fitur ini) di-backfill lewat command `trips:backfill-komisi-snapshot {--owner} {--dry-run} {--force} {--chunk}` — dry-run secara default, tidak pernah menimpa nilai yang sudah terisi.
- Stop kios tanpa drop baru → `getTotalDropReal() = 0` → komisi 0.
- BS daur-ulang (`bs_redistribution`) **exclude** dari komisi.

Sumber: `Trip::getKomisiRianAttribute()` (`Trip.php:289–292`).

### C8. Komisi Kios Baru (DILEBUR, dan DIHAPUS dari UI)

Bonus komisi kios baru **sudah dilebur** ke tarif flat. `getKomisiKiosBaruAttribute()` mengembalikan `0.0`. Legacy alias `getKomisiRegulerAttribute()` = `komisi_rian` penuh. (`Trip.php:298–306`)

**Status 23 Oktober 2026**: karena field ini terbukti non-fungsional (lihat **D4**), input `komisi_kios_baru_per_mika` **dihapus sepenuhnya dari UI** — form `/owner/settings` (`OwnerSettingsController.php`, `settings.blade.php`) dan form + kolom tabel `UserResource` (super admin). Kolom DB `users.komisi_kios_baru_per_mika` **sengaja dipertahankan** (bukan di-drop) untuk audit data lama; tidak lagi divalidasi atau ditulis dari form manapun. Lihat `NEXT_SESSION.md` (entri 23 Okt 2026) untuk detail investigasi.

### C9. Untung Bersih Owner

```
untung_bersih = untung_kotor - komisi_rian
```
Sumber: `Trip::getUntungBersihOwnerAttribute()` (`Trip.php:308–311`).

### C10. Kerugian BS

Kerugian BS (barang-barang rusak/reject yang dikembalikan) **ditampilkan TERPISAH** dari omset/untung — tidak masuk rumus untung bersih. Sumber: `MEMORY.md` (Untung bersih formula).

### C11. HPP Global vs Snapshot

- **HPP lama global** (`users.hpp_per_mika`): dipertahankan di kolom DB dan accessor (`getHppPerMikaValue()`, fallback 9500), tapi hanya jadi fallback untuk delivery lama dengan `cost_snapshot NULL`. Tidak ada input di form Settings maupun form UserResource untuk owner. (`OwnerSettingsController.php:18–21`, `UserResource.php:152–155`)
- **HPP aktif** sekarang per-varian: `product_variants.cost_per_pack` → snapshot ke `deliveries.cost_snapshot` saat titipan dibuat.

### C12. Kios: Status & Jenis

- **Booking** (`isBooking()`): `!is_cash_only && default_qty_mika === null` — identitas dicatat, belum ada dodol. (`Kiosk.php:103–106`)
- **Cash Only** (`is_cash_only = true`): kios cash, tanpa konsinyasi.
- **Konsinyasi** (`default_qty_mika IS NOT NULL`): kios dengan jatah titipan.
- Perubahan jenis terjadi saat operator di lapangan: "Mulai Titipan" mengisi `default_qty_mika` → tidak lagi booking. "Titip Cash" set `is_cash_only = true` → tidak lagi booking.

**Alasan Stop Kios (`STOP_REASONS`):**
- `pemilik_minta_stop` — `Pemilik minta berhenti sementara`
- `tutup_permanen` — `Kedai tutup permanen`
- `kurang_laku` — `Penjualan kurang jalan`
- `pindah_lokasi` — `Pindah lokasi`
- `lainnya` — `Alasan lain`

Sumber: `Kiosk.php:75–81`.

### C13. Kios Lama vs Kios Baru (untuk Komisi)

- **Kios Baru** = `kiosk.first_titip_date = trip.trip_date` (hari yang sama dengan trip kunjungan pertama). (`Trip.php:117–118`)
- **Durasi status kios baru** = `kios_baru_days` hari dari `first_titip_date` (fallback 30). (`User.php:354`)
- Badge ⭐ kios baru ditentukan dari `kios_baru_days` ini.
- Komisi kios baru sekarang SAMA dengan komisi reguler (tarif flat — lihat C8).

### C14. Kios Lama (Migrasi / OpeningBalance)

Kios yang sudah punya titipan berjalan sebelum masuk sistem diberi **saldo awal** via `OpeningBalance::create()`. Proses:
1. Cek idempotensi: jika sudah ada delivery pending → tidak menumpuk. (`OpeningBalance.php:40–42`)
2. Set `first_titip_date = kemarin` agar tidak terhitung kios baru. (`OpeningBalance.php:51–53`)
3. Buat delivery konsinyasi via `ConsignmentDrop::record()` dengan trip migrasi sentinel (tanggal `2000-01-01`, bisa juga tanggal historis asli lewat `kios:saldo-awal`/`SeedKioskSaldoAwal`). (`OpeningBalance.php:56–61`, `OpeningBalance.php:73–75`)
4. Trip migrasi dikenali lewat `Trip::isMigrationSentinel()` (operator-nya akun sistem `operator.migrasi.owner{N}@cemilanqontas.id`, lihat `User::isSystemAccount()`). **Diperbaiki 23 Okt 2026** (investigasi BRIEF 2 poin 2): sebelumnya trip sentinel ini ikut terhitung di komisi (mika yang "di-drop" via saldo awal dihitung sebagai drop operator sungguhan, memunculkan komisi/kerugian palsu). Sekarang `mika_komisi` dipaksa `0` untuk trip sentinel (`Trip::getMikaKomisiAttribute()`), `Trip::scopeRealOperational()` mengecualikannya dari Riwayat Trip, laporan bulanan, dashboard, dan `TripAggregator`; `TripDeleteController` juga menolak mengarsipkannya. Kemampuan "Tagih + Titip Ulang" untuk menagih titipan lama kios ini **tidak terpengaruh** — perbaikan hanya di sisi pelaporan/komisi, bukan di jalur penagihan. Dibuktikan delta 0 untuk trip nyata owner Ismi (lihat `NEXT_SESSION.md`, entri 23 Okt 2026). (`Trip.php`, `OpeningBalance.php:79–80`)

Penanda kios lama (tidak ada kolom flag): **"punya delivery konsinyasi pending"** — diturunkan murni dari data (`doesntHave('settlement')`). (`OpeningBalance.php:18–20`)

### C15. Arsip vs Hapus Trip

> ⚠️ **Koreksi terhadap draf audit sebelumnya**: draf awal bagian ini (dan temuan D7) menyatakan "Pulihkan hanya via CLI, tidak ada tombol UI". Setelah verifikasi ulang langsung ke `routes/web.php`, `TripDeleteController.php`, dan `resources/views/owner/trips/index.blade.php`, klaim itu **TIDAK BENAR** — tombol UI "Pulihkan" memang ada. Versi yang benar ada di bawah ini.

Sumber: `routes/web.php:96-99`, `app/Http/Controllers/Owner/TripDeleteController.php`, `app/Console/Commands/TripRestore.php`, `app/Console/Commands/TripForceDelete.php`, `app/Models/Trip.php`.

- **Arsip (Soft Delete)** = tombol **"Arsipkan"** di dashboard/Riwayat Trip (`DELETE owner.trips.destroy` → `TripDeleteController::destroy()`). Trip disembunyikan dari laporan/agregat via SoftDeletes global scope. Data anak (kiosk_visits, deliveries, settlements, delivery_origins, commissions) **tetap utuh** — tidak ada DB DELETE/FK cascade. (`TripDeleteController.php:11-28`)
- **Pulihkan — ADA DUA JALUR, bukan satu:**
  1. **Tombol UI "Pulihkan"** di Riwayat Trip (index) dan halaman detail trip, muncul bila trip `trashed()` — form `POST owner.trips.restore` dengan `onsubmit="return confirm('Pulihkan trip ini? Akan kembali dihitung di laporan.');"`, memanggil `TripDeleteController::restore()` yang mengosongkan `deleted_at`. (`resources/views/owner/trips/index.blade.php:111-117`, `TripDeleteController.php:30-45`)
  2. **CLI** `php artisan trip:restore {id}` — jalur alternatif/lama, fungsinya identik (`TripRestore.php:20-23`).
  - Docblock command `TripRestore.php:14-15` masih berkomentar *"UI restore boleh menyusul; untuk kini ini jalur resmi"* — komentar ini **basi/usang**: UI restore sudah ada, komentarnya belum diperbarui mengikuti kode. Lihat temuan **D7** (direvisi).
- **Hapus Permanen** = `php artisan trip:force-delete {id}` — **TIDAK BISA DI-UNDO**, hanya untuk data uji. FK cascade menghapus semua data anak. Dikonfirmasi **tidak tersedia** lewat tombol/route UI apa pun (`routes/web.php` dan `TripDeleteController.php` tidak punya route/metode force-delete). (`TripForceDelete.php:9–15`)
- Query manual JOIN `trips` (bukan via model) HARUS tambahkan `whereNull('trips.deleted_at')` sendiri — tidak ter-cover global scope. (`Trip.php:18–20`)

### C16. Arsip vs Nonaktif untuk Akun User/Operator

- **Akun owner/operator** TIDAK punya SoftDeletes/arsip.
- Alternatif: nonaktifkan (`is_active = false`) — login diblokir, data tetap.
- Hapus permanen: hanya jika tidak ada data dependensi (kios, trip, komisi).
- Super admin dan akun sistem tidak bisa dihapus dari UI. (`User.php:155–161`)

### C17. Multi-Tenant

- Area/Cluster: owner_id di kolom `clusters`.
- Kios: owner diketahui via `cluster.owner_id` (tidak ada kolom owner_id di `kiosks`). (`Kiosk.php:240–243`)
- Trip: `trips.owner_id`.
- Supplier, ProcurementBatch: `owner_id` di tabelnya masing-masing.
- Product: `owner_id` di tabel `products`.
- ProductVariant: owner via `product.owner_id` (tidak ada kolom langsung).
- Nomor trip unik per-owner. (`MEMORY.md`)

---

## D. TEMUAN

### D1. ✅ SELESAI — Gap Settings Enam Nilai Tanpa UI

Temuan awal: `business_name` dan lima default kedai baru sudah tersedia di DB/accessor, tetapi belum dapat diubah owner dari UI.

**Status 20 Oktober 2026: diperbaiki.** Halaman Settings sekarang memiliki tiga bagian (`Identitas Usaha`, `Harga & Komisi`, `Default Kedai Baru`) dan input untuk keenam nilai tersebut. Controller memvalidasi dan menyimpannya pada owner yang login; `Peringatan Belum Dikunjungi` wajib ≥ `Ideal Dikunjungi Tiap`; batas hari maksimal 365 dan jatah maksimal 100.

Default kustom dipakai pada jalur pembuatan kios owner maupun operator tanpa memutasi kios/trip lama. Nama usaha dipakai oleh header dan data export PDF/Excel. Perilaku persist/reload, validasi, penerapan default, dan isolasi tenant dilindungi oleh `tests/Feature/Owner/SettingsTest.php`.

---

### D2. ✅ SELESAI — Inkonsistensi Default `komisi_per_mika` di Settings vs UserResource

Temuan awal: helperText di Settings (`settings.blade.php`) dan `UserResource` masih menyebut `Default: Rp 500`, padahal migration `2026_07_11_000001` sudah mengubah default DB ke Rp 1.000 dan accessor `getKomisiPerMikaValue()` fallback ke Rp 1.000 — teks tidak akurat sebagai panduan pengguna.

**Status 23 Oktober 2026: diperbaiki** (bagian dari investigasi BRIEF 2 poin 1). Helper text sekarang: `Komisi per mika yang diletakkan operator (basis drop). Default: Rp 1.000.` (`settings.blade.php:71`, `UserResource.php:202`).

---

### D3. `hpp_per_mika` — Field "Zombie" di UserResource

Kolom `users.hpp_per_mika` masih ada di DB dan model (fillable, cast, accessor dengan fallback 9500). Di `UserResource` kolom ini muncul sebagai kolom tabel read-only untuk super admin (toggleable, hidden by default), tetapi komentar kode menyebutnya "informasional, audit data lama, bukan jalur kalkulasi aktif." Tidak ada form input untuk mengubah nilai ini dari manapun (Settings maupun UserResource). Kolom tabel super-admin ini **sengaja dibiarkan** (`UserResource.php:152–155`) — dokumentasikan sebagai legacy.

---

### D4. ✅ SELESAI — Komisi Kios Baru — Dua Tarif di DB, Satu Dipakai

Temuan awal: DB memiliki dua kolom (`komisi_per_mika` reguler dan `komisi_kios_baru_per_mika` kios baru), keduanya punya input di Settings form dan UserResource, tapi `Trip::getKomisiKiosBaruAttribute()` mengembalikan `0.0` — bonus kios baru sudah **dilebur** ke tarif reguler sejak sebelum temuan ini ditulis. Artinya `komisi_kios_baru_per_mika` memunculkan input di UI namun tidak berdampak ke perhitungan apa pun.

**Status 23 Oktober 2026: diperbaiki** (investigasi BRIEF 2 poin 1). Field `komisi_kios_baru_per_mika` **dihapus dari UI** — form `/owner/settings` dan form + kolom tabel `UserResource` — supaya tidak lagi menyesatkan pengguna dengan input yang tak berpengaruh. Kolom DB sengaja **tidak di-drop** (audit data lama). Satu-satunya tarif yang berdampak ke komisi tetap `komisi_per_mika` (sekarang dibekukan per-trip via `komisi_per_mika_snapshot`, lihat **C7**). Lihat **C8** untuk detail lengkap.

---

### D5. ✅ SELESAI — Implementasi Settings Kini Sesuai Komentar Migration

Migration `2026_10_17_000004` menyebut `(menu Settings owner)` sebagai tujuan kolom. Sejak 20 Oktober 2026, halaman dan controller Settings telah mengimplementasikan kelima default kedai baru tersebut, ditambah `business_name` dari migration `2026_10_17_000003`. Tidak ada lagi gap DB-siap/UI-belum-tersedia untuk field-field ini.

---

### D6. BIJI_PER_MIKA dan HARGA_PER_BIJI — Konstanta Legacy di ActiveTrip

`ActiveTrip.php:25–26` mendefinisikan `BIJI_PER_MIKA = 15` dan `HARGA_PER_BIJI = 800` sebagai konstanta kelas. Namun nilai sebenarnya sekarang per-varian via snapshot delivery. Konstanta ini berpotensi membingungkan karena tampak seperti nilai yang digunakan, padahal sudah tidak jadi basis kalkulasi utama.

---

### D7. (DIREVISI) Komentar Docblock `trip:restore` Basi — Bukan Lagi "Hanya via CLI"

**Draf audit sebelumnya salah mengklaim** "Restore trip hanya via CLI, tidak ada UI" — klaim itu sudah ditarik, lihat koreksi di **C15**. Faktanya: tombol **"Pulihkan"** sudah ada di UI (Riwayat Trip index & halaman detail trip), berfungsi sama dengan command CLI `trip:restore`.

**Temuan yang sebenarnya**: komentar docblock di `app/Console/Commands/TripRestore.php:14-15` masih berbunyi *"UI restore boleh menyusul; untuk kini ini jalur resmi memulihkan trip salah-arsip"* — frasa ini ditulis SEBELUM tombol UI "Pulihkan" dibuat, dan tidak pernah diperbarui setelah tombol itu ada. Dampaknya kecil (tidak memengaruhi fungsi), tapi komentar ini membingungkan developer berikutnya yang membaca command tersebut dan mengira UI restore belum ada. Juga terdapat **dua jalur paralel** (tombol UI + command CLI) yang melakukan hal identik tanpa saling mereferensikan di komentar masing-masing — kandidat untuk dirapikan (bukan bug, sekadar duplikasi dokumentasi internal).

---

### D8. Akun Sistem (operator.migrasi.*) — Disembunyikan Tanpa Keterangan

Akun sistem dibuat otomatis oleh `OpeningBalance` (untuk migrasi saldo awal kios lama) menggunakan pola email `operator.migrasi.owner{N}@cemilanqontas.id`, dengan password acak dan `is_active = false`. Akun ini disembunyikan dari semua listing UI (`->excludeSystem()`) dan diblokir dari edit/hapus. Owner mungkin tidak menyadari keberadaannya. (`User.php:136–148`, `OpeningBalance.php:83–91`)

**Catatan 23 Oktober 2026**: trip yang dibuat atas nama akun sistem ini (trip sentinel migrasi) sebelumnya ikut mencemari angka komisi/laporan — sudah diperbaiki, lihat **C14** dan **C7**. Keberadaan akun itu sendiri (tersembunyi tanpa keterangan di UI manapun) **masih** merupakan temuan terbuka — tidak diubah oleh perbaikan 23 Okt 2026.

---

### D9. Owner Tidak Bisa Atur Komisi Khusus Operator Sendiri (Hanya Super Admin) — ✅ SELESAI (23 Okt 2026)

~~Komentar kode di `UserResource.php:138–140` menyatakan field `komisi_per_mika_khusus` (Section **"Komisi Khusus Operator"**, lihat **B8.2**) "diisi di sini (owner membuat/mengubah operatornya) ATAU form Edit super admin" — menyiratkan owner seharusnya bisa mengisinya saat membuat/mengubah operatornya sendiri.~~

~~Namun `OperatorResource` (`app/Filament/Resources/OperatorResource.php`, sisi owner — **B8.1**) **tidak** menampilkan field ini sama sekali...~~

**Diperbaiki**: `OperatorResource` sekarang punya Section **"Komisi Khusus"** sendiri dengan field `komisi_per_mika_khusus` (lihat **B8.1** untuk teks verbatim) — owner bisa mengatur tarif khusus operatornya tanpa minta bantuan super admin. Rumus tarif default dibagi dengan `UserResource` lewat trait `App\Filament\Concerns\HasKomisiKhususOperatorField` (tidak dobel rumus). Kolom tabel "Komisi /Mika" di `ManageOperators` diubah jadi per-baris (tarif efektif + suffix khusus/ikut default), dengan `->with('owner')` menjaga tidak N+1. Komentar usang di `UserResource.php` juga diperbaiki agar menyebut kedua entry point yang sekarang benar-benar ada. Keamanan: owner A tidak bisa memount aksi edit pada operator milik owner B (scoped `getEloquentQuery()`); operator (bukan owner) ditolak 403 di `/owner-panel/operators`. Lihat commit `4ca8554`, 6 test baru (`tests/Feature/Owner/OperatorResourceTest.php`).
