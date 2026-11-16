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
    // Saran "Kunjungi": picu bila hari sejak kunjungan >= max(revisit_min_days, revisit_gap_multiplier × rerata jarak
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
        'problem' => env('KIOSK_INSIGHTS_LABEL_PROBLEM', 'Bermasalah'),
        'inactive' => env('KIOSK_INSIGHTS_LABEL_INACTIVE', 'Tidak aktif'),
    ],
    'methodology' => [
        'performance' => 'Konsinyasi saja; cash dan BS redistribusi tidak masuk persentase/laju/saran jatah. Minimal 3 titipan selesai dihitung meskipun pembayaran belum lunas.',
        'weekly_sales' => '7 × jumlah biji laku / jumlah maksimum(hari siklus, 1), termasuk titipan laku nol. Hari awal: tanggal trip nyata; owner_placed created_at; migration first_titip_date atau created_at.',
        'sales' => 'Laku dan omset termasuk cash serta BS redistribusi, dari delivery unik yang ditagih pada kunjungan aktif trip nyata, selesai, nonarsip; delivery asal arsip tetap mengikuti TripAggregator.',
        'receivable' => 'Saldo piutang terkini, umur sejak visit_date settlement sampai as_of. Bukan rekonstruksi saldo historis atau arus kas berdasarkan tanggal cicilan.',
        'visits' => 'Pasangan unik trip/kedai dari kunjungan aktif atau delivery tanpa kunjungan pada trip nyata; sentinel migrasi bukan kunjungan. Walk-in tidak dilaporkan sebagai kedai.',
        'revisit' => 'Saran "Kunjungi" muncul bila hari sejak kunjungan terakhir minimal sebesar maksimum(revisit_min_days, revisit_gap_multiplier × rerata jarak kunjungan kedai itu). Kedai yang riwayat jaraknya kurang dari revisit_min_gaps jarak memakai ambang tetap revisit_days.',
        'labels' => 'Satu label: nonaktif > data belum cukup > bermasalah > laris > lambat > stabil. Persentil laju relatif sesama kedai konsinyasi yang cukup data dalam usaha yang sama.',
        'suggestions' => 'Saran saja, tidak mengubah jatah/data. Data belum cukup tidak mendapat saran BS/jatah, tetapi piutang/kunjungi tetap boleh. Habis = laku ≥95% pada minimal 2 dari 3 tagihan konsinyasi terakhir: permintaan tersensor, tawarkan minimal +1 mika; estimasi laju × jarak boleh menaikkan sampai +2, dibatasi max_quota_increase. Tidak menaikkan kedai bermasalah/nonaktif. Penurunan hanya jika laku rendah dan jeda terukur. Prioritas: piutang lama → lama tidak dikunjungi → BS tinggi → jatah.',
        'returns' => '% BS = kedaluwarsa/rusak saja ÷ biji titipan ditagih ×100%. Sisa Dibawa Pulang (%) = retur segar saja ÷ basis sama; informasi, bukan kerugian atau pemicu label.',
    ],
];
