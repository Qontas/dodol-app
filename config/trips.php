<?php

return [

    /*
     * BRIEF 27 Okt 2026 "TRIP LUPA DIAKHIRI" (lanjutan: syarat idle minimal) —
     * jumlah jam minimal sebuah trip HARUS tidak ada aktivitas (kunjungan/
     * delivery/tambah stok, lihat App\Support\TripAutoCloser::lastActivityAt())
     * sebelum boleh ditutup otomatis, SELAIN syarat trip_date < hari ini
     * (App\Support\TripAutoCloser::staleQuery()).
     *
     * Mencegah trip yang MASIH dipakai operator mengantar lewat tengah malam
     * ikut tertutup begitu ia membuka halaman sesaat setelah jam 00:00 —
     * lihat App\Support\TripAutoCloser::isIdleLongEnough().
     *
     * Override lewat .env: TRIP_AUTO_CLOSE_IDLE_HOURS.
     */
    'auto_close_idle_hours' => (int) env('TRIP_AUTO_CLOSE_IDLE_HOURS', 6),

];
