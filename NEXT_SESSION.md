# NEXT_SESSION.md — Dodol-App
*Sesi terakhir: 09 Juni 2026*

## TRIGGER SENTENCE
Bg, lanjut dodol-app. 115 PASS. UI Blocker Start Trip fixed. Workspace steril.
GitHub: Qontas/dodol-app synced.
PRIORITAS MUTLAK: DEPLOYMENT KE RAILWAY.APP.

## STATUS TERAKHIR (KODE FREEZE - PRODUCTION READY)
- 3a4aeda fix(operator): resolve disabled button state on start trip when trip bebas is active
- a41baac style(auth): redesign login page to match landing brand identity
- Test Suite: **115 PASS (382 assertions)**.
- GitHub: tersinkronisasi di branch `main`.

## BUSINESS RULES (LOCKED - JANGAN DIUBAH)
- 1 mika = 15 biji, Rp 800/biji = Rp 12.000/mika.
- Settlement qty BIJI, delivery qty_delivered MIKA.
- HPP per owner (default Rp 9.500, custom via `/owner/settings`).
- Komisi reguler = Rp 500/mika terjual.
- Komisi kios baru = Rp 1.000/mika di-drop (first_titip_date = tanggal trip).
- Kios cash only: `is_cash_only = true`, settlement langsung lunas saat itu juga.
- Drop extra cash: drop > `default_qty_mika` → kelebihan = `cash_sale` delivery.
- Extension max 2x → warning cut off.
- harga_mika = Rp 200/mika (default, bisa custom per owner untuk perhitungan laporan bulanan).
- Multi-tenant: `owner_id` mengikat tables: `clusters`, `suppliers`, `products`, `procurement_batches`, `trips`, dan `users` (operator).

## PRIORITAS BERIKUTNYA: DEPLOYMENT
Tidak ada lagi coding lokal. 
Langkah selanjutnya murni infrastruktur:
1. Setup Railway.app (GitHub repo connect).
2. Provisioning MySQL Database di Railway.
3. Setup Environment Variables (APP_URL, DB_*, dll).
4. Run artisan migrate & db:seed di production.
