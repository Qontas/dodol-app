// TUGAS 3 (BRIEF 23 Okt 2026, lanjutan) — Chart.js untuk grafik "Tren Omset
// (30 Hari Terakhir)" di /owner/dashboard, DIBUNDLE lewat Vite (npm, versi
// dikunci di package-lock.json) — bukan lagi <script src="https://cdn.jsdelivr.net/npm/chart.js">
// TANPA VERSI (rekomendasi audit performa #5a, risiko supply-chain: CDN tanpa
// versi bisa diam-diam berubah kapan saja).
//
// 'chart.js/auto' meniru build UMD yang lama dipakai dari CDN (semua
// controller/element/plugin ter-registrasi otomatis) supaya kode pemanggil
// (`new Chart(ctx, {...})` di owner/dashboard.blade.php) TIDAK perlu diubah.
// window.Chart ditempel manual karena ESM tidak otomatis membuat identifier
// global seperti build UMD CDN.
//
// Entry point TERPISAH dari resources/js/app.js (lihat vite.config.js) supaya
// Chart.js HANYA dimuat di halaman yang butuh (@vite di owner/dashboard.blade.php
// saja) — bukan ikut terbundle ke semua halaman owner/operator lewat app.js.
import Chart from 'chart.js/auto';

window.Chart = Chart;
