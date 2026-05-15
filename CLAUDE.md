# CLAUDE.md — Entry Point untuk Claude Code

## Apa Project Ini

**Cemilan Qontas Distribusi** — Progressive Web App (PWA) untuk manajemen distribusi dodol Garut. Single-user app (untuk owner: Qontas). Dipakai dari HP di lapangan dan dari laptop di rumah untuk planning + analytics.

**Owner**: Ismi Qontas Lubis (Medan, Sumatera Utara)
**Bisnis**: Distribusi dodol Garut ke 200+ kios di area Medan
**Model**: Konsinyasi (titip), barang sisa expired ditanggung owner

## Wajib Dibaca Dulu Sebelum Coding

1. **`PRD.md`** — Product Requirements Document lengkap. Baca semua sebelum mulai. Berisi: konteks bisnis, schema database, fitur per fase, user flow, design system, edge cases, dan acceptance criteria.

## Tech Stack — JANGAN MENYIMPANG

- **PHP 8.2.12** (sudah terinstall via XAMPP)
- **Laravel 11.x** (LTS, latest stable)
- **Livewire 3.x** + **Alpine.js** (untuk reactivity tanpa React/Vue)
- **Tailwind CSS 3.x** (utility-first styling)
- **MySQL via MariaDB 10.4.32** (default XAMPP)
- **Leaflet.js + OpenStreetMap** (untuk maps — GRATIS, jangan pakai Google Maps API)
- **PWA**: manifest.json + service worker untuk install-to-home-screen
- **Composer 2.7.7** (sudah terinstall)
- **Node.js 20.14.0** + npm (sudah terinstall)

## Conventions yang Harus Diikuti

### Code Style

- Bahasa Indonesia untuk: comment, variabel domain (kios, mika, dodol), label UI, notifikasi user
- Bahasa Inggris untuk: framework code (Eloquent models, controllers, migrations, route names)
- Naming: `snake_case` untuk database, `camelCase` untuk JS, `PascalCase` untuk PHP class

### Folder Structure (default Laravel, jangan diubah)

```
dodol-app/
├── app/
│   ├── Models/           # Eloquent models
│   ├── Livewire/         # Livewire components
│   ├── Services/         # Business logic (calculator, importer)
│   └── Http/
├── database/
│   ├── migrations/
│   └── seeders/
├── resources/
│   ├── views/
│   │   ├── layouts/
│   │   └── livewire/
│   └── css/
├── public/
│   ├── manifest.json     # PWA manifest
│   ├── sw.js             # Service worker
│   └── icons/            # PWA icons
└── routes/
    └── web.php
```

### Database Conventions

- Semua tabel pakai prefix yang jelas, sesuai nama bisnis (jangan generic)
- Setiap tabel WAJIB punya: `id`, `created_at`, `updated_at`
- Soft delete (`deleted_at`) untuk: `kiosks`, `deliveries`, `products`
- Foreign keys WAJIB: `onDelete('restrict')` untuk historical data (kiosks, products), `onDelete('cascade')` untuk dependent (pickups → deliveries)
- Currency disimpan dalam satuan terkecil (INTEGER, dalam Rupiah penuh, bukan desimal)

### UI Conventions

- Mobile-first design (assume user akses dari HP di lapangan)
- Touch targets minimal 44x44px
- Form input gede, label di atas (bukan placeholder yang ngilang)
- Tombol primary di kanan-bawah (thumb zone)
- Bottom navigation untuk 4 menu utama: Dashboard, Trip, Kios, Settings
- Error message dalam bahasa Indonesia, ramah, bukan kayak "ValidationException at line 23"

## Color Palette (Pakai Exact Values Ini)

```css
/* Primary brand — warm amber, cocok untuk food/dodol */
--primary: #D97706;        /* amber-600 */
--primary-dark: #B45309;   /* amber-700, hover state */
--primary-light: #FCD34D;  /* amber-300, soft accent */

/* Backgrounds */
--bg-base: #FFFBEB;        /* amber-50, base off-white (gak silau di siang) */
--bg-card: #FFFFFF;        /* white untuk card */
--bg-subtle: #F5F5F4;      /* stone-100 untuk separator */

/* Text */
--text-primary: #1C1917;   /* stone-900, hampir hitam tapi warm */
--text-secondary: #57534E; /* stone-600 */
--text-muted: #A8A29E;     /* stone-400 */

/* Semantic colors */
--success: #16A34A;        /* green-600 untuk profit */
--warning: #EA580C;        /* orange-600 untuk perhatian */
--danger: #DC2626;         /* red-600 untuk loss */
--info: #0891B2;           /* cyan-600 untuk neutral info */

/* Borders */
--border: #E7E5E4;         /* stone-200 */
```

Implementasi di Tailwind config: extend `theme.colors` dengan nilai di atas, akses via `bg-primary`, `text-primary`, dst.

## Cara Kerja Setiap Fase

1. **Sebelum mulai fase**: Baca dokumen fase yang relevan di `PRD.md`
2. **Konfirmasi ke user**: Sebelum eksekusi, ringkas apa yang akan dilakukan di fase ini
3. **Eksekusi step by step**: Setiap perubahan signifikan (install package, create migration, create component), minta approval
4. **Test setelah fase**: Jalankan migration, buka di browser, screenshot ke user
5. **Selesaikan fase sebelum lompat**: Jangan mix fase 1 dengan fase 2

## Build Commands

```bash
# Install Laravel project (Fase 1, sekali)
composer create-project laravel/laravel . "11.*"

# Install dependencies tambahan
composer require livewire/livewire
composer require maatwebsite/excel  # untuk import Excel

# Install JS dependencies
npm install -D tailwindcss postcss autoprefixer
npm install leaflet                # untuk maps

# Development
php artisan serve                  # jalanin server di localhost:8000
npm run dev                        # watch tailwind compile
php artisan migrate                # run migrations
php artisan db:seed                # run seeders

# Production build
npm run build
```

## Yang TIDAK Boleh Dilakukan

- ❌ Pakai Google Maps API (berbayar, ada kuota)
- ❌ Pakai Firebase atau Supabase (overkill untuk single-user MVP)
- ❌ Pakai React Native / Flutter (kita pakai PWA, bukan native)
- ❌ Bikin multi-tenancy / multi-user di MVP (single-user dulu)
- ❌ Implementasi HMAC-SHA256 untuk request signing (overkill untuk MVP, single user)
- ❌ Setup CI/CD pipeline (manual deploy ke VPS udah cukup)
- ❌ Pakai package dari penulis yang tidak terverifikasi (lihat downloads di packagist, minimal 10K downloads)

## Quick Reference: Domain Terminologi

| Istilah | Arti |
|---|---|
| **Kios** | Toko retail tempat dodol dititipkan |
| **Mika** | Container plastik berisi 15 dodol |
| **Antar** | Drop barang ke kios (delivery) |
| **Audit / Pickup** | Balik ke kios, hitung sisa, ambil cash |
| **Sisa / BS** | Barang sisa, dodol yang tidak laku |
| **HPP** | Harga Pokok Produksi (cost per dodol) |
| **Omset** | Total penjualan (revenue) |
| **Trip** | Satu sesi keluar antar ke beberapa kios |
| **Anggota** | Asisten yang bantu antar, digaji 20% profit |

## Pertanyaan Umum Saat Coding

**Q: Mau pake package baru, perlu konfirmasi?**
A: Ya, selalu. Tanya: "Mau install package X untuk Y, OK?"

**Q: Ada bug error di runtime, langsung fix atau tanya?**
A: Fix simple bugs (typo, missing import) silently. Tanya untuk: logic error, schema change, design decision.

**Q: User minta fitur yang tidak di PRD?**
A: Konfirmasi prioritas: "Fitur ini di luar scope MVP. Mau ditambah ke MVP atau simpen di Phase 2?"

**Q: PRD ambigu di satu bagian?**
A: Tanya user spesifik dengan opsi: "Untuk X, saya lihat 2 interpretasi: A atau B. Mana yang dimaksud?"

---

**Next**: Baca `PRD.md` sekarang, lalu konfirmasi siap eksekusi Fase 1 (Project Foundation Setup).
