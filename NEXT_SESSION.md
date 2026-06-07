# HANDOVER & NEXT SESSION CONTEXT — Dodol-App
*Terakhir diperbarui: 07 Juni 2026*

## STATUS TERAKHIR
**PRODUCTION READY - 58 Tests PASS (178 assertions)**
Seluruh test suite berjalan sukses dan mencakup asersi logika operasional serta asersi database secara terisolasi.

## FITUR TERAKHIR DISELESAIKAN (ANTIGRAVITY)
1. **Otomatisasi Asset Publishing Map Picker**:
   - Mendaftarkan `@php artisan filament:assets` pada composer script hook `post-autoload-dump` dan `post-update-cmd` di `composer.json`.
   - Asset frontend map picker secara otomatis ter-copy ke direktori `public/js/dotswan` dan `public/css/dotswan` pada build step deploy (tanpa perlu manual copy).
2. **Feature Test `ActiveTripSaveVisitTest` Komprehensif**:
   - Menguji fungsionalitas Livewire component `ActiveTrip::saveVisit()` untuk 4 skenario `visit_action` (`drop_only`, `settle_only`, `drop_and_settle`, `check_only`) dengan asersi database terperinci.
3. **Bulk Import Kios CSV Filament**:
   - Membangun native Filament v3 Importer (`KioskImporter`) dengan pemetaan kolom:
     - `nama` -> `name` (Wajib di-map, validasi tidak boleh kosong).
     - `pemilik` -> `owner_name`
     - `cluster` -> `cluster_id` (Mencari cluster berdasarkan nama; jika tidak ada, fallback ke cluster `UNCATEGORIZED` atau `null`).
     - `qty_mika` -> `default_qty_mika`
     - `telepon` -> `phone`
     - `alamat` -> `location_description`
     - `lat` -> `latitude`
     - `lng` -> `longitude`
   - Menambahkan `ImportAction` pada header action `KioskResource` List page.

---

## BUSINESS RULES (LOCKED - DILARANG DIUBAH)
Aturan-aturan ini telah dikunci dan tidak boleh diubah oleh asisten AI berikutnya:

1. **Konversi Satuan & Harga**:
   - **1 mika = 15 biji**.
   - Harga jual dodol = **Rp 800 per biji** (atau setara **Rp 12.000 per mika**).
   - Penginputan pada form Kunjungan/Settlement menggunakan satuan **BIJI**, sedangkan data titipan baru (delivery/drop) menggunakan satuan **MIKA**.

2. **Formula Finansial Trip Report (Owner Dashboard)**:
   - **Mika Terjual** = `sum(qty_sold) settlements trip ÷ 15`
   - **Mika Kios Baru** = `sum(qty_delivered)` dari delivery ke kios yang memiliki `created_at` sama dengan tanggal berlangsungnya trip DAN dikunjungi dengan aksi `drop_only`. Komisi dihitung berdasarkan `qty_delivered` (bukan `qty_sold`).
   - **Omset** = `sum(amount_paid)` dari seluruh settlements dalam trip bersangkutan.
   - **HPP** = `mika_terjual × 9500`
   - **Untung Kotor** = `mika_terjual × 2500`
   - **Komisi Reguler** = `mika_terjual × 500`
   - **Komisi Kios Baru** = `mika_kios_baru × 1000`
   - **Total Komisi Rian** = `komisi_reguler + komisi_kios_baru`
   - **Untung Bersih Owner** = `untung_kotor - total_komisi_rian`

3. **Definisi Kios Baru**:
   - Kios dibuat di database (`kios.created_at`) pada hari berlangsungnya trip **DAN** dikunjungi dengan aksi `drop_only` pada trip ini.

4. **Logika Stok per Batch**:
   - Sisa mika per batch = `qty_packs - sum(qty_delivered)` dari batch bersangkutan.

---

## PRIORITAS SESI BERIKUTNYA
**DEKLARASI FREEZE KODE - Lanjut eksekusi Deployment ke Railway.app**
*Catatan: Jangan tambahkan prioritas pengerjaan fitur baru. Sesi berikutnya difokuskan penuh untuk deployment.*
