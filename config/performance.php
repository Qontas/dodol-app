<?php

return [

    /*
     * BRIEF 11 Nov 2026 "PERFORMA MELAMBAT" item 1 — instrumentasi request lambat
     * (App\Http\Middleware\LogSlowRequests).
     *
     * Semua nilai bisa diubah lewat .env / Variables Railway TANPA deploy kode.
     */

    // Saklar utama. false = middleware tidak mengukur apa pun (nol overhead selain 1 cek config).
    'enabled' => (bool) env('SLOW_REQUEST_ENABLED', true),

    // Request dicatat ke log kalau durasinya > ambang ini (milidetik) ...
    'slow_ms' => (int) env('SLOW_REQUEST_MS', 800),

    // ... ATAU jumlah query-nya > ambang ini.
    'slow_queries' => (int) env('SLOW_REQUEST_QUERIES', 40),

    // Channel log tujuan. Default 'stderr' karena Railway hanya menampilkan
    // stdout/stderr — channel 'single' menulis ke storage/logs/laravel.log di dalam
    // container dan TIDAK terlihat di tab Logs Railway. Lihat config/logging.php.
    'log_channel' => env('SLOW_REQUEST_LOG_CHANNEL', 'stderr'),

    // Header Server-Timing (app;dur=..., db;dur=...) pada respons HTML, terlihat di
    // DevTools > Network > Timing. Hanya angka durasi — tanpa data pengguna.
    'server_timing' => (bool) env('SERVER_TIMING_ENABLED', true),

];
