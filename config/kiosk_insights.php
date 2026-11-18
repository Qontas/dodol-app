<?php

return [
    // Zona untuk menampilkan jam "Dibuat" di laporan. SENGAJA terpisah dari APP_TIMEZONE: menggantinya
    // akan menggeser tanggal bisnis (today(), trip_date), sedangkan laporan hanya butuh jam yang terbaca.
    'timezone' => env('KIOSK_INSIGHTS_TIMEZONE', 'Asia/Jakarta'),
    'min_settlements' => (int) env('KIOSK_INSIGHTS_MIN_SETTLEMENTS', 3),
    'slow_sell_through_pct' => (float) env('KIOSK_INSIGHTS_SLOW_PCT', 60),
    'fast_sell_through_pct' => (float) env('KIOSK_INSIGHTS_FAST_PCT', 80),
    'fast_percentile' => (float) env('KIOSK_INSIGHTS_FAST_PERCENTILE', 75),
    'problem_bs_pct' => (float) env('KIOSK_INSIGHTS_BS_PCT', 10),
    'debt_age_days' => (int) env('KIOSK_INSIGHTS_DEBT_DAYS', 30),
    'inactive_days' => (int) env('KIOSK_INSIGHTS_INACTIVE_DAYS', 30),
    // Saran "Kunjungi": picu bila hari sejak kunjungan >= max(revisit_min_days, revisit_gap_multiplier Ã— rerata jarak
    // kunjungan kedai itu). Kedai dengan riwayat jarak kurang dari revisit_min_gaps memakai ambang tetap revisit_days.
    'revisit_days' => (int) env('KIOSK_INSIGHTS_REVISIT_DAYS', 14),
    'revisit_min_days' => (int) env('KIOSK_INSIGHTS_REVISIT_MIN_DAYS', 10),
    'revisit_gap_multiplier' => (float) env('KIOSK_INSIGHTS_REVISIT_GAP_MULTIPLIER', 1.5),
    'revisit_min_gaps' => (int) env('KIOSK_INSIGHTS_REVISIT_MIN_GAPS', 3),
    'max_quota_increase' => (int) env('KIOSK_INSIGHTS_MAX_QUOTA_INCREASE', 2),
    // Detik. Inner deadline <=8.5s leaves ~1.5s for request/state IO (target polling ~10s).
    // Settings are clamped DOWN in KioskInsightThumbnail: max10 parallel transfers to bounded temp
    // files, sequential GD decode <=4MP, plus 2s CPU reserve per wave. No first-wave bypass.
    // CPU/filesystem IO cannot be preempted by PHP; these are conservative limits, not a hard timer.
    'photo' => [
        'budget' => (float) env('KIOSK_INSIGHTS_PHOTO_BUDGET', 8.5),
        'concurrency' => (int) env('KIOSK_INSIGHTS_PHOTO_CONCURRENCY', 10),
        'connect_timeout' => (float) env('KIOSK_INSIGHTS_PHOTO_CONNECT_TIMEOUT', 1),
        'thumb_timeout' => (float) env('KIOSK_INSIGHTS_PHOTO_THUMB_TIMEOUT', 1.5),
        'source_timeout' => (float) env('KIOSK_INSIGHTS_PHOTO_SOURCE_TIMEOUT', 3),
        'put_timeout' => (float) env('KIOSK_INSIGHTS_PHOTO_PUT_TIMEOUT', 1),
        'max_source_bytes' => (int) env('KIOSK_INSIGHTS_PHOTO_MAX_BYTES', 5 * 1024 * 1024),
    ],
    'labels' => [
        'insufficient' => env('KIOSK_INSIGHTS_LABEL_INSUFFICIENT', 'Data belum cukup'),
        'fast' => env('KIOSK_INSIGHTS_LABEL_FAST', 'Laris'),
        'stable' => env('KIOSK_INSIGHTS_LABEL_STABLE', 'Stabil'),
        'slow' => env('KIOSK_INSIGHTS_LABEL_SLOW', 'Lambat'),
        'problem' => 'Banyak rusak', // Compatibility for old snapshots; display uses debt/bs.
        'debt' => 'Piutang lama',
        'bs' => 'Banyak rusak',
        'inactive' => env('KIOSK_INSIGHTS_LABEL_INACTIVE', 'Tidak aktif'),
    ],
    'methodology' => [
        'performance' => 'Penilaian penjualan hanya untuk konsinyasi, tidak termasuk penjualan tunai atau penjualan kembali barang rusak. Perlu sedikitnya 3 kali ditagih; uang yang belum lunas tetap dihitung.',
        'weekly_sales' => 'Laku per Minggu (biji): jumlah biji terjual dibagi jumlah hari sejak dititipkan sampai ditagih, lalu dikali 7. Setiap titipan dihitung paling sedikit 1 hari; titipan yang tidak laku tetap dihitung. Tanggal awal memakai tanggal perjalanan pengiriman, tanggal pemilik mencatat titipan, atau tanggal titip pertama untuk data awal (jika tidak ada, tanggal pencatatannya).',
        'sales' => 'Biji terjual dan omset termasuk penjualan tunai serta penjualan kembali barang rusak. Tiap titipan dihitung satu kali, saat ditagih pada kunjungan yang masih berlaku dalam perjalanan selesai dan tidak diarsipkan. Jika hanya perjalanan pengiriman asal yang diarsipkan, penagihan tetap dihitung agar sama dengan laporan perjalanan.',
        'receivable' => 'Piutang adalah saldo yang belum dibayar saat laporan dibuat. Umurnya dihitung sejak tanggal penagihan sampai tanggal acuan. Bukan saldo pada tanggal lampau atau catatan uang masuk menurut tanggal cicilan.',
        'visits' => 'Kedatangan ke satu kedai dihitung sekali dalam satu perjalanan, dari kunjungan yang masih berlaku atau pengiriman tanpa catatan kunjungan. Pencatatan titipan awal bukan kunjungan. Penjualan langsung tanpa kedai tidak dilaporkan sebagai kedai.',
        'revisit' => 'Saran "Kunjungi" mengikuti jarak kunjungan biasanya, dikali pengali pengingat pada aturan aktif, dan tidak lebih cepat dari batas hari minimum. Jika riwayat jarak antarkunjungan belum cukup, gunakan batas hari tetap pada aturan aktif.',
        'labels' => 'Satu label dengan urutan: Tidak aktif, Data belum cukup, Piutang lama atau Banyak rusak, Laris, Lambat, lalu Stabil. Jika piutang lama dan banyak barang rusak terjadi bersamaan, tampilkan Piutang lama; saran memuat keduanya. Peringkat Laku per Minggu dibandingkan dengan kedai konsinyasi yang cukup data dalam usaha yang sama.',
        'suggestions' => 'Saran tidak otomatis mengubah jatah. Data belum cukup belum mendapat saran barang rusak atau jatah, tetapi tetap boleh diingatkan untuk ditagih dan dikunjungi. Jika sedikitnya 95% terjual pada 2 dari 3 penagihan terakhir, barang mungkin habis sebelum pembeli berikutnya datang: sarankan tambahan paling sedikit 1 mika. Perkiraan biji laku per hari dikali jarak kunjungan lalu dibagi isi per mika bisa memberi tambahan sampai 2 mika, mengikuti batas pada aturan aktif. Tidak menaikkan jatah kedai Piutang lama, Banyak rusak, atau Tidak aktif. Penurunan hanya disarankan jika penjualan rendah dan jarak kunjungan bisa dihitung. Dahulukan piutang lama, lama tidak dikunjungi, banyak barang rusak, lalu jatah.',
        'returns' => 'Rusak/Kedaluwarsa (%) adalah biji rusak atau kedaluwarsa dibagi biji titipan konsinyasi yang sudah ditagih, lalu dikali 100. Belum Laku, Dibawa Pulang (%) memakai biji yang masih baik dan dibawa pulang, dibagi jumlah titipan yang sama, lalu dikali 100. Barang yang masih baik bukan kerugian dan tidak membuat label Banyak rusak.',
    ],
];
