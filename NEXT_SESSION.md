# Next Session Notes — Day 5 Plan

_Day 4 closed: 17 May 2026, sore_

## Status Day 4: CLOSED ✅

### Sesi 1 — SettlementObserver Auto-Status

- Listen `saving` event
- Logic: amount_paid >= amount_due → status=paid + paid_at
- 3 unit tests added
- Commit: c9ff2a7

### Sesi 2 — Production Seeder (Blank Canvas)

- 1 owner user (Ismi Qontas Lubis)
- 1 product (Dodol Coklat Susu) + 1 variant (Mika 15 biji @ Rp 12.000)
- 8 settings (default config)
- All other tables EMPTY (cluster, supplier, kios, etc — user input via UI)
- Commit: b40aea7

### Test Suite: 36 PASS (94 assertions)

## Strategic Decisions Locked (Day 4 Brainstorm)

### Architecture

- Single login form, auto role-based redirect
- Owner dashboard: full access + financial intelligence
- Operator dashboard: operational data + smart recommendation (no financial)
- Permission level C: detail dengan smart suggestion

### Input Flow

### Input Flow & Route Optimization

- Trip-Based Flow (Mulai Trip → Pilih Cluster → Pilih Kios → Input → Selesai Trip)
- Cluster sort: by urgency score (jumlah kios overdue/warning)
- Kios sort: Nearest Neighbor algorithm (geographic flow) + urgency indicator
- Smart recommendation: activate after 3+ visits per kios (velocity reliable)

### Route Optimization Strategy

- Phase 1 (Day 5-45): **Cara A — Nearest Neighbor algorithm**
    - Simple geometric calculation pakai Haversine formula (GPS to GPS distance)
    - No external API, works offline
    - Cukup akurat untuk 5-15 kios per cluster
    - Implementation target: Day 18-22

- Phase 2 (Day 60+): **Cara B — Google Maps Directions API**
    - Real-world route accuracy (consider jalan satu arah, traffic, dll)
    - Cost: ~Rp 0 untuk volume bisnis lo (free tier $200/bulan credit)
    - Better ETA per kios
    - Upgrade dari Cara A tanpa rewrite logic

### Fallback Logic

- Kios tanpa GPS coordinates → fallback ke sort by urgency
- Operator nggak izinin GPS HP → fallback ke gudang sebagai starting point
- Offline mode → urutan dari last cached calculation

### Phases

- Phase 1 (Day 5-45): Internal tool untuk Cemilan Qontas
- Phase 2 (Day 60-90): Polish + validate market + AI integration
- Phase 3 (Year 1+): Scale up multi-domain platform

## Day 5+ Roadmap (Phase 1)

### Day 5-10: Authentication & Layout

- Customize Breeze auth (login/register)
- Role middleware (owner vs operator routes)
- Base layout dengan navigation berbeda per role
- Owner dashboard skeleton
- Operator dashboard skeleton

### Day 11-15: Master Data CRUD (Owner)

- Manajemen Cluster
- Manajemen Supplier
- Manajemen Kios (form lengkap + GPS + photo upload)
- Manajemen User/Anggota (RBAC)
- Manajemen Procurement Batch

### Day 16-22: Operational Core (Operator + Owner)

- Trip-Based Flow Implementation
- Operator: Mulai Trip → Pilih Cluster → List Kios (Nearest Neighbor) → Input
- Owner: Live trip monitoring
- Settlement workflow
- Stop Kedai workflow (lo describe Day 4)

### Day 23-30: Analytics & Reporting (Owner Only)

- Dashboard: omset, profit, loss rate
- Per-kios analytics: velocity, productive score
- Per-cluster analytics
- Per-period reports (daily, weekly, monthly)
- Smart recommendation engine

### Day 31-38: Mobile Optimization

- Tailwind responsive UI
- GPS Geolocation API
- Camera HP upload
- PWA setup (Add to Home Screen)
- Touch-friendly forms

### Day 39-45: Deploy + Real Use

- Deploy ke hosting (Niagahoster atau similar)
- Domain custom (cemilanqontas.id)
- Lo pakai aplikasi real
- Iterate based on feedback

## Trigger Sentence untuk Day 5

"Bg, gua udah istirahat. Day 4 closed dengan 36 tests + production seeder.
Sekarang lanjut Day 5: Setup authentication customization & role-based routing.
Phase 1 starting."
