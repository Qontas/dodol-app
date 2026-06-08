# Brief: Landing Page Cemilan Qontas

## KONTEKS

100 PASS. Buat landing page untuk route GET / (root).
Sekarang root redirect ke /login (tidak branded).
Ganti dengan landing page yang proper, branded, dan menarik.

## DESIGN SPEC

### Warna & Branding

- Primary: Amber (#F59E0B) — warna dodol/kuning keemasan
- Secondary: Slate (#1E293B) — dark professional
- Accent: Green (#10B981) — untuk highlight positif
- Font: sudah ada (Inter dari Tailwind)
- Nama bisnis: "Cemilan Qontas"
- Tagline: "Sistem Distribusi Dodol Terpercaya"
- Emoji/Icon: 🍬 (dodol)

### Sections

#### 1. Hero Section

- Navbar: Logo "🍬 Cemilan Qontas" + tombol "Masuk" di kanan
- Headline besar: "Kelola Distribusi Dodolmu dengan Mudah"
- Subheadline: "Pantau kios, catat kunjungan, dan analisis bisnis dalam satu platform terintegrasi"
- 2 CTA button:
    - "Mulai Sekarang" → /login (amber, primary)
    - "Pelajari Lebih Lanjut" → scroll ke features section (outline)
- Hero visual: mockup sederhana (SVG ilustrasi dashboard/kios)

#### 2. Stats Section

- 3 angka bisnis (hardcode, bisa update manual):
    - "217+ Kios Aktif"
    - "100% Digital"
    - "Real-time Tracking"

#### 3. Features Section

6 feature cards:

1. 📍 GPS Navigasi — "Navigasi langsung ke kios dengan satu klik"
2. 📊 Dashboard Real-time — "Pantau omset dan stok secara langsung"
3. 📋 Laporan Otomatis — "Export laporan PDF & Excel kapan saja"
4. 🔔 Smart Alert — "Notifikasi kios overdue dan fast mover"
5. 👥 Multi Operator — "Kelola banyak operator dalam satu platform"
6. 📦 Stok Tracking — "Pantau sisa stok per batch procurement"

#### 4. How It Works Section

3 langkah sederhana:

1. "Owner input data kios & cluster"
2. "Operator mulai trip & catat kunjungan"
3. "Owner pantau laporan & analisis bisnis"

#### 5. CTA Section

- Background amber
- Text: "Siap kelola distribusi lebih efisien?"
- Button: "Masuk Sekarang" → /login

#### 6. Footer

- "© 2026 Cemilan Qontas. Hak Cipta Dilindungi."
- Link: Login Owner | Login Operator

## TECHNICAL SPEC

### Route

- Ubah route GET / di routes/web.php
- Sekarang: redirect ke /login
- Ganti ke: return view('welcome') atau view('landing')

### View file

- Buat: resources/views/landing.blade.php
- Standalone HTML (tidak extend layouts.app — halaman publik)
- Pakai Tailwind CDN atau Vite (ikuti convention project)
- Full responsive (mobile + desktop)
- Smooth scroll untuk anchor links

### Animasi (simple, tidak berat)

- Fade in on scroll (pakai Intersection Observer API, vanilla JS)
- Hover effects pada cards (sudah ada via Tailwind)

## STEP EKSEKUSI

1. Buat resources/views/landing.blade.php (full HTML dengan semua sections)
2. Update routes/web.php: GET / → return view('landing')
3. php artisan test --compact (target 100+ PASS)
4. Commit:
   git add resources/views/landing.blade.php routes/web.php
   git commit -m "feat(ui): landing page Cemilan Qontas"
   git push origin main

## STOP POINTS

1. Route / conflict dengan existing middleware
2. Test turun dari 100 PASS
3. Tailwind CDN vs Vite conflict

JANGAN auto-decide. Lapor dulu.

Output: ringkas per step + test status. No narasi panjang.

Mulai sekarang.

--- END OF BRIEF ---
