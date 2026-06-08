<!DOCTYPE html>
<html lang="id" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cemilan Qontas — Sistem Distribusi Dodol Terpercaya</title>
    <meta name="description" content="Pantau kios, catat kunjungan, dan analisis bisnis distribusi dodol dalam satu platform terintegrasi.">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        .fade-in { opacity: 0; transform: translateY(24px); transition: opacity .6s ease, transform .6s ease; }
        .fade-in.is-visible { opacity: 1; transform: none; }
    </style>
</head>
<body class="bg-white text-slate-800 antialiased">

    {{-- ===== NAVBAR ===== --}}
    <header class="sticky top-0 z-50 bg-white/90 backdrop-blur border-b border-slate-100">
        <nav class="max-w-6xl mx-auto px-4 h-16 flex items-center justify-between">
            <a href="#" class="flex items-center gap-2 font-extrabold text-lg text-slate-900">
                <span class="text-2xl">🍬</span> Cemilan Qontas
            </a>
            <a href="{{ route('login') }}"
               class="inline-flex items-center rounded-lg bg-amber-500 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-amber-600 transition-colors">
                Masuk
            </a>
        </nav>
    </header>

    {{-- ===== HERO ===== --}}
    <section class="relative overflow-hidden bg-gradient-to-b from-amber-50 to-white">
        <div class="max-w-6xl mx-auto px-4 py-16 sm:py-24 grid lg:grid-cols-2 gap-12 items-center">
            <div class="fade-in">
                <span class="inline-flex items-center gap-2 rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-700 mb-5">
                    🍬 Sistem Distribusi Dodol Terpercaya
                </span>
                <h1 class="text-4xl sm:text-5xl font-extrabold tracking-tight text-slate-900 leading-tight">
                    Kelola Distribusi Dodolmu dengan Mudah
                </h1>
                <p class="mt-5 text-lg text-slate-600 max-w-xl">
                    Pantau kios, catat kunjungan, dan analisis bisnis dalam satu platform terintegrasi.
                </p>
                <div class="mt-8 flex flex-wrap gap-3">
                    <a href="{{ route('login') }}"
                       class="inline-flex items-center rounded-xl bg-amber-500 px-6 py-3 text-base font-bold text-white shadow-md hover:bg-amber-600 active:scale-[0.98] transition">
                        Mulai Sekarang
                    </a>
                    <a href="#features"
                       class="inline-flex items-center rounded-xl border-2 border-slate-200 px-6 py-3 text-base font-semibold text-slate-700 hover:border-amber-400 hover:text-amber-700 transition">
                        Pelajari Lebih Lanjut
                    </a>
                </div>
            </div>

            {{-- Hero visual: mockup dashboard sederhana --}}
            <div class="fade-in">
                <div class="rounded-2xl border border-slate-200 bg-white shadow-xl p-5">
                    <div class="flex items-center gap-2 mb-4">
                        <span class="h-3 w-3 rounded-full bg-red-400"></span>
                        <span class="h-3 w-3 rounded-full bg-amber-400"></span>
                        <span class="h-3 w-3 rounded-full bg-green-400"></span>
                        <span class="ml-2 text-xs text-slate-400">dashboard.cemilanqontas.id</span>
                    </div>
                    <div class="grid grid-cols-3 gap-3 mb-4">
                        <div class="rounded-lg bg-amber-50 p-3">
                            <div class="text-[10px] uppercase text-amber-600 font-semibold">Omset</div>
                            <div class="text-lg font-bold text-slate-900">Rp 4,8jt</div>
                        </div>
                        <div class="rounded-lg bg-green-50 p-3">
                            <div class="text-[10px] uppercase text-green-600 font-semibold">Kios</div>
                            <div class="text-lg font-bold text-slate-900">217</div>
                        </div>
                        <div class="rounded-lg bg-slate-50 p-3">
                            <div class="text-[10px] uppercase text-slate-500 font-semibold">Stok</div>
                            <div class="text-lg font-bold text-slate-900">1.2rb</div>
                        </div>
                    </div>
                    <svg viewBox="0 0 320 90" class="w-full h-24" preserveAspectRatio="none">
                        <polyline fill="none" stroke="#F59E0B" stroke-width="3"
                                  points="0,70 40,55 80,60 120,35 160,42 200,20 240,28 280,12 320,18" />
                        <polyline fill="rgba(245,158,11,0.08)" stroke="none"
                                  points="0,70 40,55 80,60 120,35 160,42 200,20 240,28 280,12 320,18 320,90 0,90" />
                    </svg>
                    <div class="mt-3 space-y-2">
                        <div class="flex items-center justify-between rounded-lg border border-slate-100 px-3 py-2">
                            <span class="text-sm font-medium text-slate-700">Kedai Bu Sari</span>
                            <span class="text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded-full">✓ Dikunjungi</span>
                        </div>
                        <div class="flex items-center justify-between rounded-lg border border-slate-100 px-3 py-2">
                            <span class="text-sm font-medium text-slate-700">Toko Doni</span>
                            <span class="text-xs bg-red-100 text-red-700 px-2 py-0.5 rounded-full font-bold">🔴 URGENT</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ===== STATS ===== --}}
    <section class="bg-slate-900 text-white">
        <div class="max-w-6xl mx-auto px-4 py-12 grid grid-cols-1 sm:grid-cols-3 gap-8 text-center">
            <div class="fade-in">
                <div class="text-4xl font-extrabold text-amber-400">217+</div>
                <div class="mt-1 text-slate-300">Kios Aktif</div>
            </div>
            <div class="fade-in">
                <div class="text-4xl font-extrabold text-amber-400">100%</div>
                <div class="mt-1 text-slate-300">Digital</div>
            </div>
            <div class="fade-in">
                <div class="text-4xl font-extrabold text-amber-400">Real-time</div>
                <div class="mt-1 text-slate-300">Tracking</div>
            </div>
        </div>
    </section>

    {{-- ===== FEATURES ===== --}}
    <section id="features" class="bg-white">
        <div class="max-w-6xl mx-auto px-4 py-16 sm:py-24">
            <div class="text-center max-w-2xl mx-auto fade-in">
                <h2 class="text-3xl font-extrabold text-slate-900">Semua yang Kamu Butuhkan</h2>
                <p class="mt-3 text-slate-600">Fitur lengkap untuk mengelola distribusi dari hulu ke hilir.</p>
            </div>
            <div class="mt-12 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ([
                    ['📍', 'GPS Navigasi', 'Navigasi langsung ke kios dengan satu klik.'],
                    ['📊', 'Dashboard Real-time', 'Pantau omset dan stok secara langsung.'],
                    ['📋', 'Laporan Otomatis', 'Export laporan PDF & Excel kapan saja.'],
                    ['🔔', 'Smart Alert', 'Notifikasi kios overdue dan fast mover.'],
                    ['👥', 'Multi Operator', 'Kelola banyak operator dalam satu platform.'],
                    ['📦', 'Stok Tracking', 'Pantau sisa stok per batch procurement.'],
                ] as $feature)
                    <div class="fade-in rounded-2xl border border-slate-200 p-6 hover:shadow-lg hover:-translate-y-1 transition">
                        <div class="text-3xl">{{ $feature[0] }}</div>
                        <h3 class="mt-4 text-lg font-bold text-slate-900">{{ $feature[1] }}</h3>
                        <p class="mt-2 text-sm text-slate-600">{{ $feature[2] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ===== HOW IT WORKS ===== --}}
    <section class="bg-amber-50/60">
        <div class="max-w-6xl mx-auto px-4 py-16 sm:py-24">
            <div class="text-center max-w-2xl mx-auto fade-in">
                <h2 class="text-3xl font-extrabold text-slate-900">Cara Kerjanya</h2>
                <p class="mt-3 text-slate-600">Tiga langkah sederhana dari input sampai analisis.</p>
            </div>
            <div class="mt-12 grid gap-8 sm:grid-cols-3">
                @foreach ([
                    ['1', 'Owner input data kios & cluster'],
                    ['2', 'Operator mulai trip & catat kunjungan'],
                    ['3', 'Owner pantau laporan & analisis bisnis'],
                ] as $step)
                    <div class="fade-in text-center">
                        <div class="mx-auto h-14 w-14 rounded-full bg-amber-500 text-white text-xl font-extrabold flex items-center justify-center shadow-md">
                            {{ $step[0] }}
                        </div>
                        <p class="mt-4 font-semibold text-slate-800">{{ $step[1] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ===== CTA ===== --}}
    <section class="bg-amber-500">
        <div class="max-w-4xl mx-auto px-4 py-16 text-center fade-in">
            <h2 class="text-3xl font-extrabold text-white">Siap kelola distribusi lebih efisien?</h2>
            <a href="{{ route('login') }}"
               class="mt-8 inline-flex items-center rounded-xl bg-white px-8 py-3 text-base font-bold text-amber-600 shadow-md hover:bg-amber-50 active:scale-[0.98] transition">
                Masuk Sekarang
            </a>
        </div>
    </section>

    {{-- ===== FOOTER ===== --}}
    <footer class="bg-slate-900 text-slate-400">
        <div class="max-w-6xl mx-auto px-4 py-10 flex flex-col sm:flex-row items-center justify-between gap-4">
            <p class="text-sm">© 2026 Cemilan Qontas. Hak Cipta Dilindungi.</p>
            <div class="flex items-center gap-6 text-sm">
                <a href="{{ route('login') }}" class="hover:text-white transition">Login Owner</a>
                <a href="{{ route('login') }}" class="hover:text-white transition">Login Operator</a>
            </div>
        </div>
    </footer>

    {{-- Fade-in on scroll (vanilla JS, ringan) --}}
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const els = document.querySelectorAll('.fade-in');
            if (!('IntersectionObserver' in window)) {
                els.forEach(el => el.classList.add('is-visible'));
                return;
            }
            const obs = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('is-visible');
                        obs.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.12 });
            els.forEach(el => obs.observe(el));
        });
    </script>
</body>
</html>
