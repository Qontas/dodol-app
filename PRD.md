# PRD: Cemilan Qontas Distribusi

**Versi**: 1.0
**Tanggal**: 15 Mei 2026
**Owner**: Ismi Qontas Lubis
**Status**: Approved for MVP development

---

## Daftar Isi

1. [Ringkasan Eksekutif](#1-ringkasan-eksekutif)
2. [Konteks Bisnis](#2-konteks-bisnis)
3. [Masalah yang Diselesaikan](#3-masalah-yang-diselesaikan)
4. [Tech Stack & Arsitektur](#4-tech-stack--arsitektur)
5. [Database Schema](#5-database-schema)
6. [Fitur MVP per Fase](#6-fitur-mvp-per-fase)
7. [User Flow](#7-user-flow)
8. [Design System](#8-design-system)
9. [Edge Cases & Error Handling](#9-edge-cases--error-handling)
10. [Import Data Historis](#10-import-data-historis)
11. [PWA Configuration](#11-pwa-configuration)
12. [Acceptance Criteria](#12-acceptance-criteria)
13. [Roadmap Setelah MVP](#13-roadmap-setelah-mvp)

---

## 1. Ringkasan Eksekutif

### 1.1 Apa Aplikasi Ini

**Cemilan Qontas Distribusi** adalah Progressive Web App (PWA) untuk manajemen distribusi dodol Garut milik Ismi Qontas Lubis. Aplikasi ini menggantikan sistem pencatatan manual (Table Notes) yang saat ini berantakan dan tidak menghasilkan insight.

### 1.2 Tujuan Utama

1. **Visibility profit/loss per kios** — Owner dapat melihat kios mana yang menguntungkan dan kios mana yang merugikan, dengan data, bukan tebakan.
2. **Enforce workflow audit** — Setiap delivery wajib di-audit (input sisa + cash diterima) sebelum dianggap selesai. Tidak ada lagi data menggantung berminggu-minggu.
3. **Auto-classification kios** — Sistem klasifikasi otomatis: Star (untung tinggi, BS rendah), Profitable, Marginal, Loss-Maker, Unverified.
4. **Aging report** — Delivery yang belum di-audit lebih dari 7 hari otomatis di-flag merah, mengingatkan owner untuk balik audit.
5. **Mobile-first** — Dipakai dari HP di lapangan saat antar dan saat balik audit ke kios.

### 1.3 Bukan Tujuan (Out of Scope MVP)

- Sistem multi-user dengan role permission lengkap (anggota cuma read-only di Phase 2)
- Real-time GPS tracking
- Push notification
- AI route optimization
- Customer-facing portal
- Integrasi payment gateway
- Anti-fraud cryptographic signing (HMAC-SHA256) — overkill untuk single user

### 1.4 Persona Pengguna

**Primary user: Qontas (Owner)**
- Umur 22, fresh graduate IT, paham teknologi
- Pakai HP Android di lapangan, laptop di rumah
- Antar dodol 2-3 kali per minggu
- Sehari interaksi dengan 25-50 kios
- Butuh input cepat (di motor / parkiran kios)

**Secondary user (Phase 2): Anggota**
- Asisten lapangan
- Akses read-only ke laporan profit harian
- Tidak bisa edit data master kios

---

## 2. Konteks Bisnis

### 2.1 Model Bisnis

Cemilan Qontas adalah distributor dodol Garut ke retail kios di area Medan. Model: **konsinyasi (titip jual)**.

**Alur bisnis:**

1. Qontas pesan dodol mentah dari supplier di Garut, dikirim ke Medan
2. Packing dilakukan di rumah Qontas: dodol dibungkus mika plastik
3. Mika diantar ke kios-kios retail
4. Kios jual ke konsumen akhir dengan harga retail
5. Setelah 1-2 minggu, Qontas balik audit: hitung sisa, ambil cash dari yang laku
6. Sisa expired = Qontas yang tanggung (kios tidak dirugikan)

### 2.2 Skala Operasional Saat Ini

- **200+ kios** aktif di area Medan
- **2-3 trip per minggu** (Senin paling sering, kadang ada trip extra)
- **60-150 mika per trip**
- **15 dodol per mika**
- **1 anggota** asisten lapangan (digaji 20% profit per trip)

### 2.3 Struktur Cost

**Cost per batch 20kg:**
| Komponen | Cost |
|---|---|
| Dodol dari supplier (20kg @ Rp 26.000/kg) | Rp 520.000 |
| Operasional packing | Rp 30.000 |
| Mika plastik (72 pcs × Rp 200) | Rp 14.400 |
| **Total cost per batch** | **Rp 564.400** |

**Output per batch 20kg:**
- 72 mika × 15 dodol = 1.080 dodol potential
- Loss packing (penyek/penyok) ~5% = ~54 dodol gagal
- **Dodol layak jual: ~1.026 dodol**

**Unit economics:**
- HPP per dodol: **Rp 550** (rounded)
- Harga jual ke kios: **Rp 800** per dodol (default, bisa di-override per kios)
- Harga retail di kios: Rp 1.000 per dodol (margin kios 25%)
- Gross margin Qontas per dodol: **Rp 250 (31%)**

**Cost lain per trip:**
- Bensin: ~Rp 30.000 per trip (variable)
- Gaji anggota: 20% dari profit kotor trip

### 2.4 Kondisi Saat Ini (Pain Points)

Berdasarkan audit data Feb 6 - Mar 12, 2026 (5 minggu):

1. **88% deliveries belum di-audit** (sisa tidak tercatat) — owner tidak tahu profit real
2. **BS rate real ~21%** (vs perkiraan owner yang jauh lebih rendah)
3. **6 kios loss-maker** sudah teridentifikasi dengan nama
4. **71 deliveries >4 minggu belum di-clear** — uang dan barang menggantung
5. **100 kios (46%) cuma 1x kunjungan dalam 5 minggu** — kedai sekali ambil, tidak loyal
6. **Tidak ada lokasi geografis** kios tersimpan secara terstruktur (cuma alamat tertulis di Table Notes)

### 2.5 Hipotesis Improvement dari Aplikasi

| Metrik | Saat Ini | Target Setelah App |
|---|---|---|
| BS rate | 21% | 15% (cut loss-maker, focus star) |
| Open deliveries >7 hari | ~70% data | <10% (enforce workflow) |
| Profit per bulan | Rp 800K-1.5jt (estimasi) | Rp 1.5-2.5jt (real & akurat) |
| Waktu input data per trip | 30-45 menit (Table Notes) | 10-15 menit (mobile-optimized form) |

---

## 3. Masalah yang Diselesaikan

### 3.1 Masalah #1: Pencatatan Data Berantakan

**Saat ini**: Owner pakai Table Notes (spreadsheet mobile app). Format kolom: antaran, pcs, date, sisa, omset, untung, clear. Kolom sisa & untung sering kosong karena owner lupa atau tidak balik audit.

**Akibat**: Profit yang ditampilkan adalah best-case scenario (asumsi semua delivery yang sisa kosong = sisa 0). Realitanya sebagian besar dodol expired tapi tidak tercatat.

**Solusi di app**:
- Setiap delivery WAJIB melewati 2 state: `dropped` (saat antar) → `settled` (saat audit)
- Profit dashboard cuma hitung dari delivery yang sudah `settled`
- Delivery yang `dropped` >7 hari auto-flag merah di home screen
- Tidak bisa start trip baru kalau ada >20 open deliveries umur >14 hari (force discipline)

### 3.2 Masalah #2: Tidak Ada Klasifikasi Kios

**Saat ini**: Owner tidak tahu kios mana yang menguntungkan, mana yang merugikan. Semua kios diperlakukan sama.

**Akibat**: Mengirim ke loss-maker kios berulang-ulang, bocor profit halus.

**Solusi di app**:
- Auto-classify setiap kios berdasarkan history settled deliveries:
  - **⭐ STAR**: profit/visit ≥ Rp 5.000 AND BS rate < 15%
  - **🟢 PROFITABLE**: profit/visit Rp 2.000-4.999
  - **🟢 OK**: profit/visit Rp 500-1.999
  - **🟡 MARGINAL**: profit/visit Rp 0-499
  - **🔴 LOSS_MAKER**: total profit < 0 dari ≥2 settled deliveries
  - **⚠️ UNVERIFIED**: belum ada settled delivery
- Map view warnai pin berdasarkan klasifikasi (hijau, kuning, merah)
- Saat plan trip baru, sistem suggest cut LOSS_MAKER dari list

### 3.3 Masalah #3: Lokasi Kios Tidak Terstruktur

**Saat ini**: Alamat ditulis sebagai teks bebas di Table Notes (contoh: "Jl. Sm raja gg keluarga yg kanan ujung no. 37, kios warna merah"). Tidak ada koordinat. Tidak ada foto.

**Akibat**:
- Owner harus mengingat lokasi setiap kios
- Anggota baru susah dilatih
- Tidak bisa visualisasi sebaran di map

**Solusi di app**:
- Setiap kios: nama owner, nomor HP, alamat teks, **koordinat latitude/longitude**, **1 foto kios**
- Saat tambah kios baru di lapangan: tombol "Ambil Lokasi Sekarang" → auto-fill lat/lng dari GPS HP
- Tombol "Ambil Foto" → akses kamera HP, simpan ke storage app
- Map view: pin semua kios, klik pin → buka detail kios

### 3.4 Masalah #4: Lupa Identitas Kios di Lapangan

**Saat ini**: Saat di area baru, owner susah ingat mana kios yang dulu dikunjungi.

**Solusi di app**:
- Map view dengan filter "kios dalam radius 1 km dari lokasi saya sekarang"
- Search bar kios by name (autocomplete)
- "Kios terdekat" sidebar saat klik di map

### 3.5 Masalah #5: Tidak Ada Insight per Periode

**Saat ini**: Tidak tahu bulan ini lebih untung dari bulan lalu atau tidak, kios mana yang growing atau declining.

**Solusi di app**:
- Dashboard utama: revenue & profit bulan berjalan vs bulan lalu
- Grafik trend 6 bulan terakhir
- Top 10 STAR kios bulan ini
- Bottom 10 LOSS kios bulan ini
- BS rate trend per minggu

---

## 4. Tech Stack & Arsitektur

### 4.1 Stack Final (Locked)

| Layer | Technology | Versi | Alasan |
|---|---|---|---|
| Backend Framework | Laravel | 11.x | Stack yang owner kuasai, mature, security solid |
| Frontend Reactivity | Livewire | 3.x | No SPA complexity, server-side render, AJAX-like UX |
| JavaScript | Alpine.js | 3.x | Bundled with Livewire, ringan, declarative |
| CSS Framework | Tailwind CSS | 3.x | Utility-first, fast prototyping, no specificity hell |
| Database | MariaDB (MySQL drop-in) | 10.4.x | Default XAMPP, sudah jalan, free |
| Maps | Leaflet.js + OpenStreetMap | 1.9.x | Gratis selamanya, no API key, no quota |
| PWA Service Worker | Workbox | 7.x | Library robust untuk caching strategy |
| Excel Import | maatwebsite/excel | 3.1.x | Standard Laravel Excel package |
| Icons | Heroicons | 2.x | Compatible Tailwind, simple, no extra dep |

### 4.2 Kenapa BUKAN Alternatif

| Alternatif Ditolak | Alasan |
|---|---|
| React + Inertia | Owner tidak kuasai React mendalam, kompleksitas tambahan tidak worth |
| Vue 3 + Vite | Same reason, plus mismatch dengan PHP-first mindset |
| Flutter / React Native | Native app butuh 10-16 minggu, PWA 3-4 minggu, sama-sama install-able di HP |
| Google Maps API | Berbayar setelah free tier, butuh API key management |
| Firebase | Vendor lock-in, overkill untuk single-user |
| MongoDB | Relational data (kios → deliveries → pickups) lebih cocok SQL |
| Sanctum + SPA | Overkill, MVP cuma single user single device |

### 4.3 Arsitektur High-Level

```
┌──────────────────────────────────────────────────┐
│  PWA Frontend (Browser di HP atau Laptop)        │
│  - Livewire components (Blade templates)         │
│  - Alpine.js for client-side interactivity       │
│  - Tailwind CSS for styling                       │
│  - Service Worker for offline & install          │
│  - Leaflet.js for map rendering                  │
└──────────────────────┬───────────────────────────┘
                       │ HTTP/HTTPS
                       │
┌──────────────────────▼───────────────────────────┐
│  Laravel Backend (XAMPP locally / VPS prod)      │
│  - Routes (web.php)                              │
│  - Controllers (thin, delegate to services)      │
│  - Livewire Components (state management)        │
│  - Services (business logic: profit calc, etc)   │
│  - Eloquent Models (data layer)                  │
│  - Migrations & Seeders                          │
└──────────────────────┬───────────────────────────┘
                       │ SQL
                       │
┌──────────────────────▼───────────────────────────┐
│  MariaDB Database                                │
│  - kiosks, products, deliveries, pickups,       │
│    trips, settings, users                        │
└──────────────────────────────────────────────────┘

External: OpenStreetMap tile server (free)
Local storage: storage/app/public/{kiosks,profiles}
```

### 4.4 Deployment Strategy

**MVP (lokal dulu)**:
- Develop di laptop pakai XAMPP
- Akses dari HP via local network (laptop dan HP di WiFi yang sama, akses `http://[IP-LAPTOP]:8000`)

**Production (setelah MVP stable)**:
- VPS murah: Niagahoster VPS Rp 80-150rb/bulan, atau Biznet Gio
- Domain custom: `app.cemilanqontas.com` atau similar
- HTTPS via Let's Encrypt (free)
- Deploy: git push to VPS + `php artisan migrate --force` + `npm run build`

---

## 5. Database Schema

### 5.1 Diagram ERD (Tekstual)

```
users (single user, owner)
  ├─ has many trips
  ├─ has many deliveries (via trips)

settings (singleton)
  ├─ hpp_per_dodol
  ├─ default_selling_price
  ├─ dodol_per_mika
  ├─ anggota_percentage
  ├─ bensin_per_trip_estimate

products (default 1 produk: Dodol Garut)
  ├─ has many deliveries

kiosks (master kios)
  ├─ has many deliveries
  ├─ status: active, paused, dropped

trips (1 sesi keluar antar)
  ├─ belongs to user
  ├─ has many deliveries
  ├─ status: planned, in_progress, completed, cancelled

deliveries (1 baris per drop ke kios)
  ├─ belongs to trip
  ├─ belongs to kiosk
  ├─ belongs to product
  ├─ has one pickup
  ├─ status: dropped, settled, void

pickups (audit hasil settlement)
  ├─ belongs to delivery (1-to-1)
  ├─ records: sisa, cash_received, settled_at

kiosk_photos (multiple foto per kios, future)
  ├─ belongs to kiosk
```

### 5.2 Migration Files

#### 5.2.1 `users` (default Laravel + customization)

```php
// database/migrations/0001_01_01_000000_create_users_table.php
Schema::create('users', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('email')->unique();
    $table->timestamp('email_verified_at')->nullable();
    $table->string('password');
    $table->enum('role', ['owner', 'anggota'])->default('owner');
    $table->rememberToken();
    $table->timestamps();
});
```

#### 5.2.2 `settings`

```php
// database/migrations/2026_05_15_000001_create_settings_table.php
Schema::create('settings', function (Blueprint $table) {
    $table->id();
    $table->integer('hpp_per_dodol')->default(550); // Rupiah
    $table->integer('default_selling_price')->default(800); // Rupiah
    $table->integer('dodol_per_mika')->default(15);
    $table->decimal('anggota_percentage', 5, 2)->default(20.00); // 20.00 = 20%
    $table->integer('bensin_per_trip_estimate')->default(30000); // Rupiah
    $table->integer('packing_loss_percentage')->default(5); // 5%
    $table->timestamps();
});

// Seeder: pastikan 1 row ada
```

#### 5.2.3 `products`

```php
Schema::create('products', function (Blueprint $table) {
    $table->id();
    $table->string('name'); // "Dodol Garut Original", dst
    $table->integer('cost_per_unit'); // override settings hpp_per_dodol
    $table->integer('default_selling_price'); // override settings
    $table->integer('units_per_package')->default(15); // dodol per mika
    $table->boolean('is_active')->default(true);
    $table->timestamps();
    $table->softDeletes();
});
```

#### 5.2.4 `kiosks`

```php
Schema::create('kiosks', function (Blueprint $table) {
    $table->id();
    $table->string('name')->index(); // "kios udin", "kebun sayur 1"
    $table->string('owner_name')->nullable();
    $table->string('phone')->nullable();
    $table->text('address')->nullable();
    $table->decimal('latitude', 10, 7)->nullable();
    $table->decimal('longitude', 10, 7)->nullable();
    $table->string('photo_path')->nullable(); // relative to storage/app/public
    $table->text('notes')->nullable();
    $table->enum('status', ['active', 'paused', 'dropped'])->default('active');
    $table->integer('custom_selling_price')->nullable(); // per-kios price override
    $table->enum('payment_type', ['konsinyasi', 'cash'])->default('konsinyasi');
    $table->timestamps();
    $table->softDeletes();
});
```

**Index strategy**: `name` di-index untuk autocomplete search yang cepat.

#### 5.2.5 `trips`

```php
Schema::create('trips', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->onDelete('restrict');
    $table->date('trip_date');
    $table->time('started_at')->nullable();
    $table->time('ended_at')->nullable();
    $table->enum('status', ['planned', 'in_progress', 'completed', 'cancelled'])->default('planned');
    $table->integer('bensin_cost')->nullable(); // actual bensin, optional
    $table->text('notes')->nullable();
    $table->timestamps();
});
```

#### 5.2.6 `deliveries`

```php
Schema::create('deliveries', function (Blueprint $table) {
    $table->id();
    $table->foreignId('trip_id')->constrained()->onDelete('restrict');
    $table->foreignId('kiosk_id')->constrained()->onDelete('restrict');
    $table->foreignId('product_id')->constrained()->onDelete('restrict');
    $table->date('delivered_at');
    $table->integer('mika_count'); // jumlah mika yang diantar
    $table->integer('units_count'); // calculated: mika_count * units_per_package
    $table->integer('selling_price_per_unit'); // snapshot harga saat antar
    $table->integer('cost_per_unit'); // snapshot HPP saat antar
    $table->enum('status', ['dropped', 'settled', 'void'])->default('dropped');
    $table->text('notes')->nullable();
    $table->timestamps();
    $table->softDeletes();
});
```

**Penting**: `selling_price_per_unit` dan `cost_per_unit` disimpan SEBAGAI SNAPSHOT saat delivery dibuat. Kalau di Settings nanti owner naikin harga dari Rp 800 ke Rp 850, delivery yang lama tetap pakai Rp 800. Ini wajib untuk akurasi histori.

#### 5.2.7 `pickups`

```php
Schema::create('pickups', function (Blueprint $table) {
    $table->id();
    $table->foreignId('delivery_id')->constrained()->onDelete('cascade');
    $table->date('settled_at');
    $table->integer('sisa_units'); // dodol yang sisa
    $table->integer('cash_received'); // cash yang diterima dari kios
    $table->integer('calculated_revenue'); // (units_count - sisa) * selling_price
    $table->integer('calculated_cost'); // units_count * cost (full produced cost)
    $table->integer('calculated_profit'); // revenue - cost
    $table->text('notes')->nullable();
    $table->timestamps();
});
```

**Auto-calculation**: saat pickup di-save, otomatis compute revenue, cost, profit dari snapshot di delivery. Disimpan sebagai column untuk performance (avoid recomputing di dashboard).

#### 5.2.8 `kiosk_photos` (Phase 2)

```php
Schema::create('kiosk_photos', function (Blueprint $table) {
    $table->id();
    $table->foreignId('kiosk_id')->constrained()->onDelete('cascade');
    $table->string('photo_path');
    $table->text('caption')->nullable();
    $table->timestamps();
});
```

**MVP**: cukup 1 foto per kios, simpan di `kiosks.photo_path`. Multi-foto di Phase 2.

### 5.3 Seeder Plan

```php
// database/seeders/DatabaseSeeder.php
public function run() {
    // 1. Create default user (owner)
    User::create([
        'name' => 'Ismi Qontas Lubis',
        'email' => 'ismiqontas@gmail.com',
        'password' => Hash::make('CHANGE_ME_BEFORE_PRODUCTION'),
        'role' => 'owner',
    ]);

    // 2. Create default settings
    Settings::create([
        'hpp_per_dodol' => 550,
        'default_selling_price' => 800,
        'dodol_per_mika' => 15,
        'anggota_percentage' => 20.00,
        'bensin_per_trip_estimate' => 30000,
        'packing_loss_percentage' => 5,
    ]);

    // 3. Create default product
    Product::create([
        'name' => 'Dodol Garut Original',
        'cost_per_unit' => 550,
        'default_selling_price' => 800,
        'units_per_package' => 15,
        'is_active' => true,
    ]);
}
```

---

## 6. Fitur MVP per Fase

MVP dibagi 3 fase, masing-masing 1 minggu. Setiap fase harus selesai dan diuji sebelum lanjut fase berikutnya.

### 6.1 FASE 1: Foundation & Master Data (Minggu 1)

**Tujuan**: Setup project, auth, dan CRUD master data (kios, settings, produk).

#### 6.1.1 Tasks

**Hari 1: Project Setup**
- [ ] `composer create-project laravel/laravel . "11.*"`
- [ ] Install Livewire 3: `composer require livewire/livewire`
- [ ] Setup Tailwind CSS via Vite
- [ ] Setup auth scaffolding (Laravel Breeze + Livewire stack)
- [ ] Konfigurasi `.env`: database name `cemilan_qontas_db`
- [ ] Create database di phpMyAdmin
- [ ] Run `php artisan migrate` (auth tables)
- [ ] Verify login page jalan di `http://localhost:8000/login`

**Hari 2: Database Schema**
- [ ] Bikin migration untuk: `settings`, `products`, `kiosks`, `trips`, `deliveries`, `pickups`
- [ ] Bikin Eloquent models dengan relationships
- [ ] Bikin DatabaseSeeder dengan default data
- [ ] Run migrate + seed
- [ ] Verify di phpMyAdmin: semua tabel ada, default data sudah masuk

**Hari 3: Settings Page**
- [ ] Livewire component: `Settings/EditSettings`
- [ ] Form: HPP, default selling price, dodol per mika, anggota %, bensin estimate, packing loss %
- [ ] Validasi numeric, positive
- [ ] Save dengan flash message "Settings disimpan"
- [ ] Test: ubah HPP, simpan, refresh — masih ada

**Hari 4: Kios List & Create**
- [ ] Route `/kios` → list semua kios
- [ ] Livewire component: `Kiosks/Index` dengan search bar, filter status
- [ ] Tampilan table di desktop, card di mobile
- [ ] Tombol "Tambah Kios" → modal atau halaman create
- [ ] Form create: nama (req), owner_name, phone, address, latitude, longitude (tombol "Ambil Lokasi Sekarang"), photo (tombol "Ambil Foto"), notes, status, custom_selling_price
- [ ] Implementasi geolocation API JS (browser meminta izin lokasi)
- [ ] Implementasi camera access via `<input type="file" accept="image/*" capture="environment">`
- [ ] Simpan foto di `storage/app/public/kiosks/{id}.jpg` resize max 1080px
- [ ] Run `php artisan storage:link`

**Hari 5: Kios Edit & Delete**
- [ ] Route `/kios/{id}` → detail kios
- [ ] Tombol Edit → form sama seperti create, prefilled
- [ ] Soft delete (set deleted_at) kalau owner mau "drop" kios
- [ ] Tampilan: foto kios besar, info kios, button edit/delete

**Hari 6: Map View**
- [ ] Route `/peta` → map full screen
- [ ] Leaflet.js dengan OpenStreetMap tile
- [ ] Pin semua kios yang ada lat/lng
- [ ] Pin warna berdasarkan status: active=biru, paused=kuning, dropped=abu
- [ ] Klik pin → popup nama kios + tombol "Lihat Detail"
- [ ] Tombol "Lokasi Saya" → zoom ke posisi user

**Hari 7: Polish & Test Fase 1**
- [ ] Layout responsive di mobile (test di HP via local network)
- [ ] Bottom navigation: Dashboard | Trip | Kios | Settings (Dashboard belum berfungsi, placeholder dulu)
- [ ] Set bahasa app: Indonesia
- [ ] Test full flow: tambah kios baru di lapangan via HP → pin muncul di map → edit info → soft delete
- [ ] Screenshot ke owner, konfirmasi siap lanjut Fase 2

#### 6.1.2 Acceptance Criteria Fase 1

- ✅ Bisa login dengan email/password
- ✅ Bisa edit settings (HPP, harga jual, dst), persist setelah refresh
- ✅ Bisa tambah kios baru dengan: foto dari kamera HP + GPS koordinat auto
- ✅ Bisa edit & soft-delete kios
- ✅ Map view tampil semua kios dengan pin sesuai status
- ✅ Akses dari HP via local WiFi network jalan lancar
- ✅ Tidak ada error PHP/JS di console

### 6.2 FASE 2: Trip & Delivery Workflow (Minggu 2)

**Tujuan**: Implementasi alur trip → delivery → pickup audit.

#### 6.2.1 Tasks

**Hari 8: Trip Planning**
- [ ] Route `/trip/baru` → planning page
- [ ] Pilih kios yang akan dikunjungi (multi-select, search, filter)
- [ ] Untuk setiap kios: input rencana mika count
- [ ] Auto-calculate total mika trip
- [ ] Tombol "Mulai Trip" → create trip record dengan status `planned`
- [ ] Redirect ke `/trip/{id}` (trip detail)

**Hari 9: Trip Execution (in_progress)**
- [ ] Route `/trip/{id}` → detail trip dengan list kios yang dipilih
- [ ] Tombol "Mulai Antar" → status `in_progress`
- [ ] Untuk setiap kios: tombol "Konfirmasi Antar" → create delivery record
- [ ] Form delivery: mika count (prefilled dari planning, editable), notes
- [ ] Setelah konfirmasi: status delivery = `dropped`
- [ ] Visual: list kios dengan checklist, yang udah di-drop muncul ✓
- [ ] Tombol "Selesai Trip" muncul setelah min 1 kios di-drop → status trip `completed`

**Hari 10: Pickup Audit Flow**
- [ ] Route `/audit` → list semua delivery dengan status `dropped`
- [ ] Sort by oldest first (FIFO audit)
- [ ] Filter: by kios, by age (>7 hari, >14 hari)
- [ ] Visual: card per delivery dengan info kios, tanggal drop, mika, badge umur (merah kalau >7 hari)
- [ ] Tombol "Audit" pada delivery → buka form audit

**Hari 11: Audit Form**
- [ ] Form audit (Livewire component `Audits/Settle`):
  - Sisa (jumlah dodol)
  - Cash diterima (Rupiah)
  - Notes (optional)
- [ ] Validasi: sisa max = mika_count × dodol_per_mika, cash ≥ 0
- [ ] Auto-preview profit calculation: revenue, cost, profit, anggota share, net profit
- [ ] Tombol "Simpan Audit" → create pickup record, update delivery status ke `settled`
- [ ] Toast notification: "Audit disimpan. Profit: Rp X.XXX"

**Hari 12: Trip History**
- [ ] Route `/trip/riwayat` → list semua trip (newest first)
- [ ] Per trip: tanggal, jumlah kios, total mika, total profit (kalau sudah ada pickup)
- [ ] Klik trip → detail trip dengan list delivery + status pickup
- [ ] Bisa edit trip date, bensin cost actual

**Hari 13: Quick Add Kios Saat Trip**
- [ ] Saat trip in_progress, ada tombol "Tambah Kios Baru ke Trip"
- [ ] Form quick add: nama + lokasi (auto GPS) + mika count
- [ ] Setelah save: kios baru masuk database + langsung create delivery
- [ ] Use case: lagi keliling, ketemu kios baru yang mau nitip

**Hari 14: Polish & Test Fase 2**
- [ ] Test full workflow: plan trip 5 kios → mulai antar → drop semua → setelah 1 minggu balik audit semua → semua settled
- [ ] Test edge case: drop trip di tengah jalan (cancel trip)
- [ ] Test offline: drop kios saat HP offline (apakah Livewire queue request?)
- [ ] Konfirmasi siap lanjut Fase 3

#### 6.2.2 Acceptance Criteria Fase 2

- ✅ Bisa plan trip baru dengan 1-50 kios
- ✅ Bisa eksekusi trip: drop barang per kios, simpan delivery record
- ✅ Bisa quick-add kios baru saat trip in_progress
- ✅ Audit page tampilkan delivery dengan status dropped, sort by oldest
- ✅ Bisa input sisa & cash, sistem otomatis calculate profit dengan benar
- ✅ Trip history tampil rapi, bisa drill down

### 6.3 FASE 3: Dashboard, Analytics, & Data Migration (Minggu 3)

**Tujuan**: Insight dashboard + import data historis dari Excel.

#### 6.3.1 Tasks

**Hari 15: Dashboard KPI Cards**
- [ ] Route `/` (root) → dashboard
- [ ] KPI cards row:
  - Revenue bulan ini (vs bulan lalu, persentase change)
  - Profit bersih bulan ini
  - BS rate bulan ini
  - Open deliveries count (yg belum di-audit, dengan badge umur >7 hari)
- [ ] Pakai grid responsive: 2 columns di mobile, 4 di desktop

**Hari 16: Top/Bottom Kios List di Dashboard**
- [ ] Section "Star Kios" (5 teratas profit/visit bulan ini)
- [ ] Section "Loss Maker" (semua kios yang total profit < 0 dari ≥2 delivery)
- [ ] Section "Unverified" (kios yang belum pernah di-audit, butuh follow up)
- [ ] Klik nama kios → detail kios

**Hari 17: Detail Kios dengan History**
- [ ] Update `/kios/{id}` (Phase 1) tambahin:
  - Classification badge (Star/Profitable/Marginal/Loss/Unverified)
  - Statistik: total visit, total mika, total profit, BS rate avg
  - Tabel history delivery + pickup per kios
  - Grafik trend profit per visit (line chart, optional pakai Chart.js)

**Hari 18: Aging Report Page**
- [ ] Route `/laporan/aging` → table semua open delivery
- [ ] Kolom: tanggal drop, hari yang lewat, kios, mika, estimated value
- [ ] Color coding: hijau (<7 hari), kuning (7-14), oranye (15-21), merah (>21)
- [ ] Export ke Excel tombol

**Hari 19: Monthly Report Page**
- [ ] Route `/laporan/bulanan` → pilih bulan/tahun
- [ ] Summary: total trip, total revenue, total profit, total gaji anggota, total bensin, net owner
- [ ] Detail breakdown per kios (sortable table)
- [ ] Export PDF / Excel

**Hari 20: Import dari Excel Table Notes**
- [ ] Route `/admin/import` → upload form
- [ ] Accept `.xlsx` dengan columns: antaran, pcs, Date, sisa, Omset, untung, clear
- [ ] Pakai `maatwebsite/excel`, custom import class
- [ ] Logic: untuk setiap row,
  - Cari kios by name (fuzzy match), kalau gak ada → create kios baru dengan status active, lokasi null
  - Create trip baru group by Date (1 trip per tanggal)
  - Create delivery dengan mika_count = pcs, status `dropped`
  - Kalau sisa terisi → langsung create pickup, status delivery `settled`
- [ ] Tampilkan summary: X kios baru dibuat, Y trips dibuat, Z deliveries imported
- [ ] Sebelum execute: preview mode (show what will be created)

**Hari 21: Final Polish**
- [ ] Settings: tombol "Reset Database" (dengan double confirm) untuk reset kalau import salah
- [ ] PWA: setup manifest.json, service worker, install prompt
- [ ] Test install ke home screen di HP Android
- [ ] Test offline mode: cache assets, kalau offline tampilkan banner "Mode offline, beberapa fitur terbatas"
- [ ] Final bug bashing
- [ ] Deploy ke production VPS (kalau ready) atau tetap lokal dulu

#### 6.3.2 Acceptance Criteria Fase 3

- ✅ Dashboard menampilkan KPI real-time
- ✅ Bisa drill down dari dashboard ke detail kios
- ✅ Aging report tampil kios yang butuh follow up audit
- ✅ Monthly report bisa di-generate dan export
- ✅ Bisa import Excel Table Notes historis tanpa error
- ✅ PWA bisa di-install ke home screen HP Android, icon muncul
- ✅ App tetap usable saat offline (read-only)

---

## 7. User Flow

### 7.1 Flow: Tambah Kios Baru di Lapangan

```
User di lokasi kios baru di Medan
  ↓
Buka app dari home screen HP
  ↓
Tap "Kios" di bottom nav
  ↓
Tap tombol "+ Tambah Kios" (floating action button, kanan bawah)
  ↓
Form muncul:
  - Nama kios: [_______]
  - Nama owner: [_______]
  - Nomor HP: [_______]
  - Alamat: [_______]
  - Lokasi: [Tap "Ambil Lokasi Sekarang"]
    → Browser minta izin lokasi
    → User accept
    → Auto-fill lat/lng dengan koordinat sekarang
    → Tampil pin di mini-map preview
  - Foto: [Tap "Ambil Foto"]
    → Kamera HP terbuka
    → User foto kios, tap shutter
    → Preview foto di form
  - Notes: [textarea bebas]
  - Custom harga: [opsional, kalau bukan Rp 800]
  - Status: dropdown (default: active)
  ↓
Tap "Simpan"
  ↓
Validation pass → save ke database
  ↓
Redirect ke detail kios baru
  ↓
Toast: "Kios [nama] disimpan"
```

### 7.2 Flow: Eksekusi Trip Harian

```
Pagi, user mau antar dodol
  ↓
Buka app, tap "Trip" di bottom nav
  ↓
Tap "+ Trip Baru"
  ↓
Page pilih kios:
  - Search bar di atas
  - Filter: active only (default)
  - Tap kios untuk select (multi-select)
  - Yang udah dipilih ada di sidebar dengan input mika count
  ↓
Setelah pilih 30 kios (misal), tap "Mulai Trip"
  ↓
Trip created, status `planned`
  ↓
Tap "Mulai Antar" → status `in_progress`
  ↓
List kios muncul (urutan: bebas, default by created_at)
  ↓
Sampai di kios pertama:
  - Tap kios → form drop muncul
  - Edit mika count kalau berbeda dari rencana
  - Tap "Konfirmasi Drop"
  - Delivery created, status `dropped`
  - Checklist di list kios jadi ✓
  ↓
Ulangi untuk semua kios
  ↓
Selesai semua kios → tap "Selesai Trip"
  - Optional: input bensin actual
  - Status trip `completed`
  ↓
Redirect ke dashboard
```

### 7.3 Flow: Audit / Pickup

```
Seminggu setelah trip, user mau audit
  ↓
Buka app, tap "Audit" (bisa di dashboard kalau ada notification, atau di menu)
  ↓
Page audit muncul list delivery `dropped`:
  - Sort by oldest first
  - Card per delivery: nama kios, tanggal drop, umur (badge merah kalau >7 hari)
  - Tap kios → form audit
  ↓
Form audit:
  - Mika diantar: 3 (read-only, dari delivery)
  - Total dodol: 45
  - Input "Sisa (dodol)": [__]
  - Input "Cash diterima (Rp)": [__]
  - Notes: [textarea]
  - Auto-preview:
    - Revenue: Rp ___
    - Cost: Rp ___
    - Profit kotor: Rp ___
    - Jatah anggota (20%): Rp ___
    - Profit bersih: Rp ___
  ↓
Tap "Simpan Audit"
  ↓
Pickup created, delivery status = `settled`
  ↓
Toast: "Audit disimpan. Profit bersih: Rp X"
  ↓
Kembali ke audit list, delivery tadi udah hilang dari list
```

### 7.4 Flow: Review Kios LOSS_MAKER

```
Owner buka dashboard
  ↓
Lihat section "Loss Maker" — ada 3 kios
  ↓
Tap "kios madiotomo" (loss -Rp 5.300)
  ↓
Detail kios muncul:
  - Foto kios
  - Badge merah "🔴 LOSS MAKER"
  - Stats:
    - Total visit: 1
    - Total mika: 2
    - Total profit: -Rp 5.300
    - BS rate: 53%
  - History table 1 row delivery, lihat sisa 16 dodol dari 30
  ↓
Owner decide: stop kirim
  ↓
Tap "Edit" → ubah status `dropped`
  ↓
Save
  ↓
Selanjutnya: kios ini tidak muncul di list saat plan trip baru
```

---

## 8. Design System

### 8.1 Color Palette

(Sudah dijelaskan di `CLAUDE.md`)

### 8.2 Typography

- **Font family**: System UI font stack (gratis, native rendering di setiap OS)
  ```css
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
  ```
- **Heading sizes** (Tailwind default):
  - h1: `text-3xl` (30px) bold
  - h2: `text-2xl` (24px) bold
  - h3: `text-xl` (20px) semibold
  - h4: `text-lg` (18px) semibold
- **Body**: `text-base` (16px) regular
- **Small/caption**: `text-sm` (14px)
- **Tiny/label**: `text-xs` (12px) uppercase tracking-wide

### 8.3 Component Library

Komponen umum yang dipakai:

#### 8.3.1 Buttons

```html
<!-- Primary -->
<button class="bg-primary hover:bg-primary-dark text-white px-4 py-2 rounded-lg font-medium">
  Simpan
</button>

<!-- Secondary -->
<button class="bg-white border border-stone-300 text-stone-700 hover:bg-stone-50 px-4 py-2 rounded-lg font-medium">
  Batal
</button>

<!-- Danger -->
<button class="bg-danger hover:bg-red-700 text-white px-4 py-2 rounded-lg font-medium">
  Hapus
</button>

<!-- FAB (Floating Action Button) -->
<button class="fixed bottom-20 right-4 bg-primary text-white w-14 h-14 rounded-full shadow-lg flex items-center justify-center">
  <svg>+</svg>
</button>
```

#### 8.3.2 Cards

```html
<div class="bg-card border border-stone-200 rounded-lg p-4 shadow-sm">
  <!-- content -->
</div>
```

#### 8.3.3 Badges

```html
<!-- Status badges -->
<span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-green-100 text-green-800">
  ⭐ STAR
</span>
<span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-red-100 text-red-800">
  🔴 LOSS
</span>
```

#### 8.3.4 Form Input

```html
<div class="mb-4">
  <label class="block text-sm font-medium text-stone-700 mb-1">Nama Kios</label>
  <input type="text" class="w-full px-3 py-2 border border-stone-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
  @error('name')
    <p class="text-sm text-danger mt-1">{{ $message }}</p>
  @enderror
</div>
```

### 8.4 Layout

#### 8.4.1 Bottom Navigation (Mobile)

```html
<nav class="fixed bottom-0 left-0 right-0 bg-white border-t border-stone-200 flex justify-around py-2 z-50">
  <a href="/" class="flex flex-col items-center text-xs text-stone-600 hover:text-primary">
    <svg>...</svg>
    <span>Dashboard</span>
  </a>
  <a href="/trip" class="...">Trip</a>
  <a href="/kios" class="...">Kios</a>
  <a href="/settings" class="...">Settings</a>
</nav>
```

#### 8.4.2 Top Bar (Mobile)

```html
<header class="sticky top-0 z-40 bg-white border-b border-stone-200 px-4 py-3 flex items-center justify-between">
  <h1 class="text-lg font-semibold text-stone-900">Cemilan Qontas</h1>
  <button>...</button>
</header>
```

### 8.5 Responsive Breakpoints

Pakai default Tailwind:
- Mobile (default): < 640px
- `sm:` 640px+
- `md:` 768px+
- `lg:` 1024px+

Approach: **Mobile-first**. Default styling untuk mobile, pakai `md:` atau `lg:` untuk override di desktop.

### 8.6 Empty States

Setiap list/page yang bisa kosong WAJIB punya empty state yang ramah:

```html
<div class="text-center py-12">
  <svg class="mx-auto h-12 w-12 text-stone-400">...</svg>
  <h3 class="mt-2 text-sm font-medium text-stone-900">Belum ada kios</h3>
  <p class="mt-1 text-sm text-stone-500">Tambah kios pertama lo untuk mulai distribusi.</p>
  <button class="mt-4 bg-primary text-white px-4 py-2 rounded-lg">+ Tambah Kios</button>
</div>
```

---

## 9. Edge Cases & Error Handling

### 9.1 Edge Case List

| # | Skenario | Behavior yang Diharapkan |
|---|---|---|
| 1 | Kios di-soft-delete, masih ada delivery history | Tetap tampil di history (read-only), tidak muncul di list "tambah kios ke trip" |
| 2 | User input sisa > mika_count × 15 | Validation error: "Sisa tidak boleh lebih dari total dodol diantar" |
| 3 | User input cash negatif | Validation error: "Cash harus angka positif atau 0" |
| 4 | User mau hapus produk yang masih dipakai di delivery | Restrict, error: "Produk ini masih dipakai di X delivery. Tidak bisa dihapus, set inactive saja." |
| 5 | HPP atau harga jual diubah di settings, history sudah pakai value lama | History tetap akurat (snapshot di delivery), tapi delivery baru pakai value baru |
| 6 | User offline saat input audit | Form tampil error: "Koneksi terputus, simpan akan retry otomatis" (Phase 2 implementation, Phase 1 cuma show message) |
| 7 | GPS HP tidak akurat di lokasi tertentu | User bisa manual edit lat/lng di form |
| 8 | Foto kios gagal upload (file >10MB) | Validation: "Foto maksimal 5MB. Coba kompres dulu." |
| 9 | User start trip baru padahal ada trip in_progress | Block: "Ada trip yang belum selesai (#5, tanggal X). Selesaikan dulu sebelum start trip baru." |
| 10 | Import Excel: nama kios di sheet beda case ("Kios Udin" vs "kios udin") | Fuzzy match (case-insensitive, trim spaces). Kalau >85% match, anggap kios sama. |
| 11 | Import Excel: ada row dengan kios baru, sisa kosong | Create kios baru status=active, lat/lng=null. Create delivery status=dropped. |
| 12 | User akses page audit padahal tidak ada open delivery | Empty state ramah: "Tidak ada audit pending. Kerja bagus!" |
| 13 | Database connection lost | Custom error page 500 dengan tombol retry, jangan tampilkan stack trace Laravel |
| 14 | User clear browser data, PWA cache hilang | App tetap jalan dari server (degrade gracefully ke regular web app) |

### 9.2 Form Validation Rules

#### Kiosk
- `name`: required, string, min:2, max:100, unique (case-insensitive)
- `phone`: nullable, string, regex format Indonesia
- `latitude`: nullable, decimal, between -90 and 90
- `longitude`: nullable, decimal, between -180 and 180
- `photo`: nullable, image, max:5MB, mimes:jpg,png,webp
- `custom_selling_price`: nullable, integer, min:100

#### Delivery
- `kiosk_id`: required, exists in kiosks
- `mika_count`: required, integer, min:1, max:100
- `delivered_at`: required, date, not future

#### Pickup
- `delivery_id`: required, exists in deliveries, status must be 'dropped'
- `sisa_units`: required, integer, min:0, max: (mika_count × dodol_per_mika)
- `cash_received`: required, integer, min:0
- `settled_at`: required, date, >= delivery.delivered_at, not future

### 9.3 Error Messages (Bahasa Indonesia)

| Tech Error | User-Facing Message |
|---|---|
| ValidationException | "Ada yang salah di form, cek lagi yang ditandai merah" |
| 404 Not Found | "Halaman atau data yang kamu cari tidak ditemukan" |
| 403 Forbidden | "Kamu tidak punya akses ke halaman ini" |
| 500 Internal Server Error | "Ada error di server, coba lagi sebentar. Kalau masih error, hubungi admin." |
| Connection timeout | "Server tidak merespon, cek koneksi internet kamu" |

---

## 10. Import Data Historis

### 10.1 Use Case

Owner punya data 5 minggu di Excel Table Notes (file `Antaran_Dodol_Juli__4_.xlsx`). Data ini valuable untuk:
- Baseline klasifikasi kios (Star/Loss/dst)
- History trend

Wajib bisa di-import sekali setelah app jadi.

### 10.2 Format Excel yang Diharapkan

| antaran | pcs | Date | sisa | Omset | untung | clear |
|---|---|---|---|---|---|---|
| hm said 1 | 3 | 2026-02-06 | (kosong) | 36000 | (kosong) | [ ] |
| kedai wise | 1 | 2026-02-25 | 5 | 8000 | -1500 | [ ] |

### 10.3 Logic Import

```
For each row in Excel:
  1. Cleanup: trim whitespace, lowercase nama kios
  2. Cari kios di database dengan nama similar (fuzzy match >85%)
     - Kalau ketemu: pakai existing kiosk
     - Kalau gak ketemu: create new kiosk dengan default values
  3. Cari trip dengan tanggal sama
     - Kalau ketemu: pakai existing trip
     - Kalau gak ketemu: create new trip dengan status `completed`
  4. Create delivery:
     - mika_count = pcs
     - selling_price_per_unit = 800 (default, atau dari settings)
     - cost_per_unit = 550
     - status = 'dropped'
  5. Kalau sisa terisi (not null):
     - Create pickup
     - sisa_units = sisa
     - cash_received = omset (asumsi semua omset = cash diterima)
     - Auto-calculate revenue, cost, profit
     - Update delivery status = 'settled'
```

### 10.4 UI Import

```
Halaman /admin/import:
  - Upload file .xlsx
  - Tombol "Preview"
  - Tampilkan:
    - X rows akan diproses
    - Y kios baru akan dibuat
    - Z trips baru akan dibuat
    - W deliveries akan dibuat
    - V pickups akan dibuat (kalau sisa filled)
  - Warning kalau ada row yang invalid
  - Tombol "Jalankan Import" (dengan double confirm)
  - Setelah import: tampil summary + link ke dashboard
```

### 10.5 Code Structure

```php
// app/Imports/TableNotesImport.php
class TableNotesImport implements ToCollection, WithHeadingRow
{
    public function collection(Collection $rows)
    {
        // 1. Group rows by Date → trips
        // 2. Loop, create kiosks (if not exist) + deliveries + pickups
        // 3. Wrap dalam DB::transaction untuk atomic
    }
}

// app/Services/KioskMatcher.php
class KioskMatcher
{
    public function findOrCreate(string $name): Kiosk
    {
        // Fuzzy match logic pakai similar_text() PHP
        // Threshold 85%
    }
}
```

---

## 11. PWA Configuration

### 11.1 `manifest.json`

File `public/manifest.json`:

```json
{
  "name": "Cemilan Qontas Distribusi",
  "short_name": "Cemilan Qontas",
  "description": "Aplikasi manajemen distribusi dodol Garut",
  "start_url": "/",
  "display": "standalone",
  "orientation": "portrait",
  "background_color": "#FFFBEB",
  "theme_color": "#D97706",
  "icons": [
    {
      "src": "/icons/icon-192.png",
      "sizes": "192x192",
      "type": "image/png",
      "purpose": "any maskable"
    },
    {
      "src": "/icons/icon-512.png",
      "sizes": "512x512",
      "type": "image/png",
      "purpose": "any maskable"
    }
  ]
}
```

### 11.2 Service Worker

File `public/sw.js`:

```javascript
const CACHE_NAME = 'cemilan-qontas-v1';
const STATIC_ASSETS = [
  '/',
  '/css/app.css',
  '/js/app.js',
  '/icons/icon-192.png',
  // tambahkan asset lain
];

// Install: cache static assets
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(STATIC_ASSETS))
  );
});

// Fetch: cache-first untuk assets, network-first untuk HTML
self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);
  if (event.request.destination === 'document') {
    // Network first untuk HTML
    event.respondWith(
      fetch(event.request).catch(() => caches.match(event.request))
    );
  } else {
    // Cache first untuk assets
    event.respondWith(
      caches.match(event.request).then((response) => response || fetch(event.request))
    );
  }
});

// Activate: cleanup old caches
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(
      keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key))
    ))
  );
});
```

### 11.3 Register Service Worker di Blade Layout

```blade
{{-- resources/views/layouts/app.blade.php --}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#D97706">
    <link rel="manifest" href="/manifest.json">
    <link rel="apple-touch-icon" href="/icons/icon-192.png">
    <title>{{ $title ?? 'Cemilan Qontas' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body>
    <!-- content -->
    @livewireScripts
    <script>
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/sw.js');
        }
    </script>
</body>
</html>
```

### 11.4 Install Prompt

Tambahkan di home screen:

```javascript
let deferredPrompt;
window.addEventListener('beforeinstallprompt', (e) => {
  e.preventDefault();
  deferredPrompt = e;
  // Show custom "Install App" button
  document.getElementById('install-btn').style.display = 'block';
});

document.getElementById('install-btn').addEventListener('click', () => {
  deferredPrompt.prompt();
});
```

### 11.5 Generate Icons

Generate icon 192x192 dan 512x512 PNG dari logo brand. Tools:
- https://realfavicongenerator.net (gratis)
- Atau bikin manual di Figma/Canva

Simpan di `public/icons/icon-192.png` dan `public/icons/icon-512.png`.

---

## 12. Acceptance Criteria (Overall MVP)

Aplikasi MVP dianggap **DONE** kalau:

### 12.1 Functional Criteria

- [ ] User bisa login pakai email/password
- [ ] User bisa CRUD kios (lengkap dengan foto + GPS)
- [ ] User bisa CRUD produk
- [ ] User bisa edit settings (HPP, harga, dst)
- [ ] User bisa plan trip, execute trip, drop delivery ke kios
- [ ] User bisa quick-add kios baru saat trip in_progress
- [ ] User bisa audit delivery (input sisa & cash)
- [ ] Profit auto-calculated dengan benar (revenue - cost - anggota share)
- [ ] Dashboard tampil KPI bulan berjalan
- [ ] Map view tampil semua kios dengan pin color sesuai status
- [ ] Aging report tampil delivery >7 hari yang belum di-audit
- [ ] Bisa import data Excel Table Notes
- [ ] PWA bisa di-install ke home screen HP Android

### 12.2 Quality Criteria

- [ ] Tidak ada error PHP/JS di console saat happy path flow
- [ ] Mobile responsive: usable di HP 360px width
- [ ] Form validation menampilkan error message bahasa Indonesia yang ramah
- [ ] Loading state ditampilkan saat operasi >1 detik
- [ ] Tidak ada N+1 query di list page (pakai `with()` eager load)
- [ ] Migration bisa di-rollback (`php artisan migrate:rollback`)
- [ ] Seeder bisa di-run berulang tanpa error (idempotent)
- [ ] Bahasa Indonesia konsisten di semua UI (no English leak)

### 12.3 Documentation Criteria

- [ ] README.md di root project: cara setup, run, deploy
- [ ] Comment di kode untuk logic kompleks (calculation, validation)
- [ ] List dependency di composer.json + package.json terkurasi

---

## 13. Roadmap Setelah MVP

Fitur yang dianggap "nice to have" tapi BUKAN MVP:

### Phase 2 (Bulan 2-3)

- Multi-user dengan role (anggota read-only)
- Real-time GPS tracking saat trip in_progress (untuk owner monitor anggota)
- Notifikasi push: "Delivery #5 udah 10 hari belum di-audit"
- Multiple foto per kios
- Foto bukti pengantaran (saat drop, foto barang di kios)

### Phase 3 (Bulan 4-6)

- Route optimization: AI suggest urutan kios paling efisien berdasarkan jarak
- Cash flow forecast: prediksi pendapatan bulan depan berdasarkan historical
- Supplier order management: track pesanan dodol dari Garut
- Inventory management: stok di gudang Qontas (sebelum dipack)
- Customer-facing feature: kios bisa request stock via WhatsApp link

### Phase 4 (Bulan 6+)

- SaaS productize: kalau MVP work untuk Qontas, jadikan multi-tenant SaaS untuk distributor snack lain di Indonesia
- Pricing: Rp 100-300rb/bulan per distributor
- Validate: minimal 3 distributor lain mau bayar sebelum invest develop further

---

**End of PRD**

Versi ini akan terus di-update kalau ada perubahan scope. Setiap perubahan major harus disepakati dengan owner sebelum implementasi.
