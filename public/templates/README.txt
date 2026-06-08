CARA IMPORT KIOS (Cemilan Qontas / dodol-app)
===============================================

Ada 2 template:

1) kiosk-import-template.csv  (FORMAT STANDAR — direkomendasikan)
   Kolom: nama, pemilik, cluster, qty_mika, telepon, alamat, lat, lng, tanggal_pertama_titip
   - nama        : WAJIB. Nama kios.
   - pemilik     : opsional (boleh kosong).
   - cluster     : nama cluster. Kalau belum ada → otomatis dibuat untuk owner Anda.
   - qty_mika    : angka, default mika per antar.
   - lat / lng   : koordinat desimal (mis. 3.604311 / 98.665020). Boleh kosong.
   - tanggal_pertama_titip : format DD/MM/YYYY (mis. 26/02/2025). Boleh kosong.

2) kiosk-import-template-aidil.csv  (FORMAT SPREADSHEET LAMA)
   Kolom: nomor, cluster, nama_kios, qty_mika, deskripsi_lokasi, koordinat_atau_link, tanggal_pertama_titip
   - cluster (angka) : "1", "2", atau kosong. Angka → cluster "Cluster 1" dst (otomatis dibuat).
                       Kosong → masuk cluster "Uncategorized".
   - koordinat_atau_link : kolom campur, otomatis dideteksi:
       * Format DMS  -> "3° 36' 15.5196\" N 98° 39' 54.072\" E"  => dikonversi ke lat/lng desimal
       * Google Maps -> "https://maps.app.goo.gl/..."            => disimpan di catatan (GPS: ...)
       * Teks biasa  -> "WARKOP NST"                              => diabaikan (tanpa koordinat)
       * Kosong      -> tanpa koordinat
   - Tidak ada kolom nama pemilik → owner_name disimpan kosong.

CATATAN
- Semua kios yang di-import otomatis terikat ke cluster milik owner yang melakukan import (multi-tenant).
- Kolom foto / nama pengantar di spreadsheet lama TIDAK di-import.
- Upload lewat: menu Kios (Filament admin) → tombol Import.
