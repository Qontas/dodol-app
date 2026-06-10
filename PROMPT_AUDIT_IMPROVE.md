# Brief: Audit Menyeluruh + Improvement — Performance, Resilience, UX

## KONTEKS

113 PASS, 382 assertions. Aplikasi distribusi dodol siap deploy ke Railway.
SEBELUM deploy: audit menyeluruh + improvement oleh model dengan kemampuan analisis lebih dalam.

Mandat: kamu diberi kebebasan menganalisis dan improve aplikasi ini SECARA MANDIRI,
dengan batasan keras di bawah. Prioritaskan dampak nyata bagi operator di lapangan
(koneksi HP, layar kecil, kondisi terburu-buru) dan owner yang pantau dari dashboard.

## BATASAN KERAS (TIDAK BOLEH DILANGGAR)

### Business rules LOCKED — dilarang diubah:

- 1 mika = 15 biji, Rp 800/biji = Rp 12.000/mika
- Settlement qty BIJI, delivery qty_delivered MIKA
- HPP per owner (default Rp 9.500, custom via /owner/settings)
- harga_mika per owner (default Rp 200)
- Komisi reguler = Rp 500/mika terjual
- Komisi kios baru = Rp 1.000/mika di-drop (first_titip_date = tanggal trip)
- Kios cash only: is_cash_only = true, settlement langsung lunas
- Drop extra cash: drop > default_qty_mika → toggle cash/konsinyasi
- Skenario turun default, check+sisa biji, BS redistribusi (baru selesai — jangan disentuh logikanya)
- Extension max 2x → warning cut off
- Multi-tenant: owner_id scoping di semua tabel bisnis

### Aturan eksekusi:

- 113 test PASS adalah BASELINE MUTLAK — tidak boleh turun satupun
- Setiap perubahan perilaku (bukan sekadar optimasi internal) harus disertai test baru
- Commit atomic per kategori improvement (jangan 1 commit raksasa)
- Schema migration hanya kalau benar-benar diperlukan, dan harus reversible (ada down())
- Jangan tambah package/dependency baru tanpa lapor dulu
- Jangan refactor besar-besaran arsitektur (no moving files antar namespace)

## AREA AUDIT (URUTAN PRIORITAS)

### PRIORITAS 1: Resilience — Operator Tutup Browser di Tengah Trip

Ini kekhawatiran terbesar owner. Audit dan pastikan:

1. **Trip recovery**: Operator tutup browser/tab → buka lagi → harus otomatis
   kembali ke trip aktif yang sama, dengan progress utuh (kios yang sudah
   dikunjungi tetap tercatat). Cek StartTrip::mount() — apakah sudah redirect
   ke trip aktif kalau ada? Kalau belum, fix.
2. **Modal state loss**: Kalau operator sedang isi modal visit (belum tekan
   Simpan) lalu browser tertutup → data input hilang (ini acceptable), TAPI
   pastikan tidak ada state korup: buka lagi → modal bersih, kios itu masih
   bisa dikunjungi normal.
3. **Double submission guard**: Operator koneksi lambat, tekan "Simpan Kunjungan"
   2x → pastikan tidak tercipta 2 KioskVisit/2 Delivery duplikat. Cek apakah
   ada wire:loading.attr="disabled" + guard server-side. Kalau belum ada
   server-side idempotency guard, tambahkan.
4. **End trip accidental**: Apakah ada konfirmasi sebelum "Akhiri Trip"?
   Kalau operator salah tekan, data trip tertutup permanen. Pastikan ada
   dialog konfirmasi.
5. Tulis test untuk skenario recovery (operator punya trip aktif → mount
   StartTrip → harus diarahkan ke ActiveTrip, bukan buat trip baru).

### PRIORITAS 2: Performance — Jangan Ada Lemot di Lapangan

Audit dengan data realistis (ratusan kios, ribuan kiosk_visits):

1. **N+1 queries**: Scan semua Livewire component operator + owner dashboard
    - Filament resources. Cari relasi yang di-lazy-load dalam loop.
      Yang sudah dioptimasi (computeKiosFlags, lastOperatorPerKiosk) jangan dirusak.
2. **loadKiosks() hot path**: Method ini dipanggil setiap saveVisit. Hitung
   berapa query yang dia jalankan sekarang. Kalau > 6 query, gabungkan.
3. **Owner dashboard**: Trip report pakai accessor finansial per-trip
   (mika_terjual, omset_val, dll) yang masing-masing query sendiri?
   Kalau ya, ini N+1 tersembunyi — perbaiki dengan eager aggregate.
4. **wire:poll.30s**: Cek payload polling LiveTripProgress — apakah dia
   re-render seluruh dashboard atau cuma section kecil? Kalau berat, persempit.
5. **Index database**: Periksa query paling sering (kiosk_visits by kiosk_id+visited_at,
   deliveries by kiosk_id, settlements by delivery_id, trips by owner_id+trip_date).
   Pastikan index ada. Tambah migration index kalau kurang (reversible).
6. **Asset loading**: Landing page pakai Tailwind CDN (ok untuk landing),
   tapi pastikan halaman operator pakai Vite build (bukan CDN) — cek.
7. Ukur sebelum/sesudah kalau memungkinkan (query count via DB::enableQueryLog
   di test, bukan asumsi).

### PRIORITAS 3: UX Operator (Layar HP, Kondisi Lapangan)

1. **Touch targets**: Semua tombol min 44x44px. Audit modal visit — tombol
   +/- qty, radio buttons, checkbox.
2. **Feedback visual**: Setiap aksi (simpan, mulai trip, akhiri trip) harus
   ada loading state yang jelas. Operator di bawah matahari harus tau
   aplikasinya lagi proses atau hang.
3. **Error messages**: Bahasa Indonesia sederhana, actionable. "Gagal menyimpan.
   Coba lagi." lebih baik dari stack trace atau diam.
4. **Offline awareness**: Kalau koneksi putus saat saveVisit → apa yang terjadi?
   Minimal: tampilkan pesan jelas "Koneksi terputus, coba lagi" bukan spinner
   selamanya. (PWA offline penuh = di luar scope, jangan dikerjakan.)
5. **Smart flags & info**: Pastikan badge flags + operator terakhir tidak
   bikin card kios terlalu penuh di layar 360px. Cek wrapping.

### PRIORITAS 4: UX Owner Dashboard

1. Konsistensi bahasa sehari-hari (sudah sebagian: Belum Bayar, Perlu Dikunjungi,
   Area, dll) — scan sisa istilah teknis yang terlewat di view owner + Filament
   owner panel (label kolom, helper text, notifikasi).
2. Empty states: dashboard owner baru (belum ada data) harus informatif,
   bukan angka 0 semua tanpa arahan.
3. Prediksi habis dodol (fitur baru) — pastikan tampil di tempat yang berguna
   bagi owner (list kios yang dicek + sisa + prediksi).

### PRIORITAS 5: Inisiatif Bebas (Opsional, Kalau Ada Nilai Nyata)

Kamu boleh menambahkan improvement lain yang menurutmu bernilai untuk bisnis
distribusi ini, SELAMA: tidak mengubah business rules locked, tidak menambah
dependency, test tetap hijau, dan setiap tambahan dijelaskan alasannya di
laporan akhir. Contoh arah yang masuk akal: validasi input yang lebih ketat,
guard kasus tepi (trip tanpa kios, settlement 0 biji), perbaikan aksesibilitas,
konsistensi format Rupiah. Kalau ragu → masukkan ke laporan sebagai saran,
jangan dikerjakan.

## STEP EKSEKUSI

1. AUDIT DULU (read-only): jalankan test baseline, baca semua file terkait,
   buat daftar temuan per prioritas. JANGAN edit apapun di fase ini.
2. Laporkan ringkasan temuan (bullet, ranked by impact) + rencana eksekusi.
   TUNGGU konfirmasi singkat kalau ada temuan yang butuh keputusan bisnis.
   Temuan yang murni teknis (index, N+1, guard) → langsung eksekusi tanpa nunggu.
3. Eksekusi per prioritas, commit atomic per kategori:
    - fix(resilience): ...
    - perf(operator): ...
    - perf(dashboard): ...
    - ux(operator): ...
    - ux(owner): ...
4. php artisan test --compact setelah SETIAP kategori (bukan cuma di akhir)
5. Push semua commit ke origin main
6. Laporan akhir: tabel temuan → aksi → hasil, + daftar saran yang TIDAK
   dikerjakan (untuk dipertimbangkan owner)

## STOP POINTS

1. Temuan yang butuh ubah business rule locked → STOP, lapor, jangan kerjakan
2. Test turun dari 113 → STOP, rollback perubahan terakhir, lapor
3. Butuh package baru → STOP, lapor alasan + alternatif
4. Perubahan schema yang tidak reversible → STOP, lapor
5. Ragu apakah sesuatu "perilaku" atau "optimasi" → anggap perilaku, tambah test

Output per fase: ringkas, tabel kalau bisa. No narasi panjang.

Mulai dari fase AUDIT sekarang.

--- END OF BRIEF ---
