<?php

return [
    // Zona untuk menampilkan jam "Dibuat" di laporan. SENGAJA terpisah dari APP_TIMEZONE: menggantinya
    // akan menggeser tanggal bisnis (today(), trip_date), sedangkan laporan hanya butuh jam yang terbaca.
    'timezone' => env('KIOSK_INSIGHTS_TIMEZONE', 'Asia/Jakarta'),
    'min_settlements' => (int) env('KIOSK_INSIGHTS_MIN_SETTLEMENTS', 3),
    'slow_sell_through_pct' => (float) env('KIOSK_INSIGHTS_SLOW_PCT', 50),
    'fast_sell_through_pct' => (float) env('KIOSK_INSIGHTS_FAST_PCT', 80),
    'fast_percentile' => (float) env('KIOSK_INSIGHTS_FAST_PERCENTILE', 75),
    'problem_bs_pct' => (float) env('KIOSK_INSIGHTS_BS_PCT', 25),
    'debt_age_days' => (int) env('KIOSK_INSIGHTS_DEBT_DAYS', 30),
    'inactive_days' => (int) env('KIOSK_INSIGHTS_INACTIVE_DAYS', 30),
    // Saran "Kunjungi": picu bila hari sejak kunjungan >= max(revisit_min_days, revisit_gap_multiplier × rerata jarak
    // kunjungan kedai itu). Kedai dengan riwayat jarak kurang dari revisit_min_gaps memakai ambang tetap revisit_days.
    'revisit_days' => (int) env('KIOSK_INSIGHTS_REVISIT_DAYS', 14),
    'revisit_min_days' => (int) env('KIOSK_INSIGHTS_REVISIT_MIN_DAYS', 10),
    'revisit_gap_multiplier' => (float) env('KIOSK_INSIGHTS_REVISIT_GAP_MULTIPLIER', 1.5),
    'revisit_min_gaps' => (int) env('KIOSK_INSIGHTS_REVISIT_MIN_GAPS', 3),
    'frequent_sold_out_ratio' => (float) env('KIOSK_INSIGHTS_SOLD_OUT_RATIO', 0.5),
    'max_quota_increase' => (int) env('KIOSK_INSIGHTS_MAX_QUOTA_INCREASE', 2),
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
        'suggestions' => 'Saran saja, tidak mengubah jatah/data. Estimasi jatah = bulatkan(laju biji/minggu ÷ 7 × rata-rata jarak kunjungan ÷ isi mika saat ini), minimum 1. Kenaikan dibatasi ambang max_quota_increase (default +2), hanya jika sering habis dan % laku tinggi; penurunan hanya jika % laku rendah. Tidak ada saran jatah saat data kurang, jeda kunjungan belum terukur, atau kedai tidak aktif. Prioritas tindakan: piutang lama → lama tidak dikunjungi → retur/BS tinggi → jatah.',
        'returns' => 'Retur/BS = (biji retur segar + biji retur kedaluwarsa) ÷ biji titipan konsinyasi yang sudah ditagih ×100%.',
    ],
];
