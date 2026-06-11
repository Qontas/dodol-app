<!DOCTYPE html>
<html lang="id" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cemilan Qontas — Sistem Distribusi Dodol</title>
    <meta name="description" content="Platform manajemen distribusi dodol modern: kelola kios, trip, tagihan titipan, dan laporan dalam satu sistem.">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Plus Jakarta Sans', 'sans-serif'] },
                    keyframes: {
                        'gradient-pan': {
                            '0%, 100%': { backgroundPosition: '0% 50%' },
                            '50%': { backgroundPosition: '100% 50%' },
                        },
                        'float': {
                            '0%, 100%': { transform: 'translateY(0)' },
                            '50%': { transform: 'translateY(-12px)' },
                        },
                    },
                    animation: {
                        'gradient-pan': 'gradient-pan 12s ease infinite',
                        'float': 'float 6s ease-in-out infinite',
                    },
                },
            },
        }
    </script>
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .reveal { opacity: 0; transform: translateY(32px); transition: opacity .7s cubic-bezier(.16,1,.3,1), transform .7s cubic-bezier(.16,1,.3,1); }
        .reveal.visible { opacity: 1; transform: translateY(0); }
        .tilt-card { transform-style: preserve-3d; transition: transform .2s ease-out; will-change: transform; }
        .hero-mesh {
            background:
                radial-gradient(60% 50% at 20% 10%, rgba(251,146,60,.25), transparent 60%),
                radial-gradient(50% 50% at 80% 0%, rgba(245,158,11,.20), transparent 60%),
                radial-gradient(60% 60% at 50% 100%, rgba(15,23,42,.85), transparent 70%);
        }
        .gradient-text {
            background: linear-gradient(90deg, #fbbf24, #f97316, #fb923c, #fbbf24);
            background-size: 300% 100%;
            -webkit-background-clip: text; background-clip: text; color: transparent;
        }
        ::-webkit-scrollbar { width: 10px; }
        ::-webkit-scrollbar-track { background: #0f172a; }
        ::-webkit-scrollbar-thumb { background: #f59e0b; border-radius: 99px; }
    </style>
</head>
<body class="bg-slate-950 text-slate-200 antialiased overflow-x-hidden">

    <!-- NAVBAR -->
    <nav id="navbar" class="fixed top-0 inset-x-0 z-50 transition-all duration-300">
        <div class="max-w-6xl mx-auto px-6">
            <div class="flex items-center justify-between h-16 mt-3 rounded-2xl px-5 border border-white/10 bg-white/5 backdrop-blur-xl shadow-lg shadow-black/20">
                <a href="#" class="flex items-center gap-2.5 group">
                    <span class="grid place-items-center w-9 h-9 rounded-xl bg-gradient-to-br from-amber-400 to-orange-600 text-slate-950 font-extrabold text-lg shadow-lg shadow-amber-500/30 group-hover:scale-105 transition">Q</span>
                    <span class="font-extrabold text-white tracking-tight">Cemilan Qontas</span>
                </a>
                <div class="hidden md:flex items-center gap-8 text-sm font-medium text-slate-300">
                    <a href="#fitur" class="hover:text-amber-400 transition">Fitur</a>
                    <a href="#cara-kerja" class="hover:text-amber-400 transition">Cara Kerja</a>
                    <a href="#testimoni" class="hover:text-amber-400 transition">Testimoni</a>
                </div>
                <a href="{{ route('login') }}" class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-amber-400 to-orange-500 px-5 py-2 text-sm font-bold text-slate-950 shadow-lg shadow-amber-500/30 hover:shadow-amber-500/50 hover:-translate-y-0.5 transition">
                    Masuk
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5-5 5M6 12h12"/></svg>
                </a>
            </div>
        </div>
    </nav>

    <!-- HERO -->
    <header class="relative min-h-screen flex items-center hero-mesh overflow-hidden">
        <div class="absolute inset-0 -z-10 animate-gradient-pan opacity-60"
             style="background: linear-gradient(120deg, #0f172a, #1e1b4b, #0f172a, #422006); background-size: 300% 300%;"></div>
        <div class="absolute top-1/4 -left-20 w-72 h-72 bg-amber-500/20 rounded-full blur-3xl animate-float"></div>
        <div class="absolute bottom-1/4 -right-20 w-80 h-80 bg-orange-600/20 rounded-full blur-3xl animate-float" style="animation-delay:-3s"></div>

        <div class="max-w-6xl mx-auto px-6 pt-28 pb-16 w-full">
            <div class="max-w-3xl reveal">
                <span class="inline-flex items-center gap-2 rounded-full border border-amber-400/30 bg-amber-400/10 px-4 py-1.5 text-xs font-semibold text-amber-300 mb-6">
                    <span class="w-2 h-2 rounded-full bg-amber-400 animate-pulse"></span>
                    Sistem Distribusi Dodol Modern
                </span>
                <h1 class="text-5xl md:text-7xl font-extrabold leading-[1.05] tracking-tight text-white">
                    Kelola Distribusi <br>
                    <span class="gradient-text animate-gradient-pan">Dodol</span> Tanpa Ribet.
                </h1>
                <p class="mt-6 text-lg md:text-xl text-slate-300/90 leading-relaxed max-w-2xl">
                    Satu platform untuk mengatur kios, trip operator, tagihan biji-ke-mika,
                    komisi, hingga laporan bulanan — semuanya rapi, real-time, dan terukur.
                </p>
                <div class="mt-9 flex flex-col sm:flex-row gap-4">
                    <a href="{{ route('login') }}" class="inline-flex items-center justify-center gap-2 rounded-2xl bg-gradient-to-r from-amber-400 to-orange-500 px-7 py-4 font-bold text-slate-950 shadow-xl shadow-amber-500/30 hover:shadow-amber-500/50 hover:-translate-y-1 transition text-base">
                        Mulai Sekarang
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5-5 5M6 12h12"/></svg>
                    </a>
                    <a href="#fitur" class="inline-flex items-center justify-center gap-2 rounded-2xl border border-white/15 bg-white/5 backdrop-blur px-7 py-4 font-semibold text-white hover:bg-white/10 hover:-translate-y-1 transition text-base">
                        Lihat Fitur
                    </a>
                </div>
            </div>
        </div>
        <div class="absolute bottom-8 left-1/2 -translate-x-1/2 text-slate-500 animate-bounce">
            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 14l-7 7m0 0l-7-7m7 7V3"/></svg>
        </div>
    </header>

    <!-- STATS -->
    <section class="relative border-y border-white/10 bg-slate-900/50">
        <div class="max-w-6xl mx-auto px-6 py-16 grid grid-cols-2 md:grid-cols-4 gap-8">
            @php
                $stats = [
                    ['n' => 217, 'suffix' => '+', 'label' => 'Kios Terdaftar'],
                    ['n' => 15,  'suffix' => '',  'label' => 'Biji per Mika'],
                    ['n' => 100, 'suffix' => '%', 'label' => 'Hitungan Akurat'],
                    ['n' => 24,  'suffix' => '/7', 'label' => 'Akses Real-time'],
                ];
            @endphp
            @foreach ($stats as $s)
                <div class="reveal text-center">
                    <div class="text-4xl md:text-5xl font-extrabold gradient-text">
                        <span class="counter" data-target="{{ $s['n'] }}">0</span>{{ $s['suffix'] }}
                    </div>
                    <p class="mt-2 text-sm font-medium text-slate-400">{{ $s['label'] }}</p>
                </div>
            @endforeach
        </div>
    </section>

    <!-- FITUR -->
    <section id="fitur" class="max-w-6xl mx-auto px-6 py-24">
        <div class="text-center max-w-2xl mx-auto reveal">
            <h2 class="text-4xl md:text-5xl font-extrabold text-white tracking-tight">Semua yang Anda Butuhkan</h2>
            <p class="mt-4 text-slate-400 text-lg">Dirancang khusus untuk alur bisnis distribusi dodol dari hulu ke hilir.</p>
        </div>
        <div class="mt-16 grid md:grid-cols-3 gap-6">
            @php
                $features = [
                    ['icon' => 'M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z', 'title' => 'Manajemen Kios', 'desc' => 'Daftar kios baru langsung dari lapangan, lengkap dengan komisi kios baru dan status cash-only.'],
                    ['icon' => 'M9 17a2 2 0 11-4 0 2 2 0 014 0zM19 17a2 2 0 11-4 0 2 2 0 014 0z M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a1 1 0 001 1h1', 'title' => 'Trip Operator', 'desc' => 'Mulai trip, titip dodol, tunda bayar terkontrol (maks 2x), hingga tagihan otomatis biji-ke-mika.'],
                    ['icon' => 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z', 'title' => 'Laporan Bulanan', 'desc' => 'Rekap penjualan, HPP, dan komisi per owner. Export PDF & Excel siap pakai untuk pembukuan.'],
                    ['icon' => 'M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z', 'title' => 'Tagihan Akurat', 'desc' => 'Hitung otomatis 1 mika = 15 biji @ Rp 800. Kelebihan titipan otomatis tercatat sebagai penjualan cash.'],
                    ['icon' => 'M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z', 'title' => 'Multi-Tenant', 'desc' => 'Setiap owner punya data terisolasi: kios, trip, supplier, dan operator-nya sendiri. Aman & rapi.'],
                    ['icon' => 'M13 10V3L4 14h7v7l9-11h-7z', 'title' => 'Komisi Otomatis', 'desc' => 'Komisi reguler & kios baru dihitung otomatis sesuai aturan tiap owner — tanpa salah, tanpa manual.'],
                ];
            @endphp
            @foreach ($features as $f)
                <div class="reveal group relative tilt-card rounded-3xl border border-white/10 bg-gradient-to-b from-white/5 to-transparent p-7 hover:border-amber-400/40 transition-colors">
                    <div class="absolute inset-0 rounded-3xl bg-gradient-to-br from-amber-500/0 to-orange-500/0 group-hover:from-amber-500/10 group-hover:to-orange-500/5 transition-all duration-300"></div>
                    <div class="relative">
                        <div class="grid place-items-center w-12 h-12 rounded-2xl bg-gradient-to-br from-amber-400 to-orange-600 text-slate-950 shadow-lg shadow-amber-500/20">
                            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $f['icon'] }}"/></svg>
                        </div>
                        <h3 class="mt-5 text-xl font-bold text-white">{{ $f['title'] }}</h3>
                        <p class="mt-2 text-slate-400 leading-relaxed text-[15px]">{{ $f['desc'] }}</p>
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    <!-- CARA KERJA -->
    <section id="cara-kerja" class="relative bg-slate-900/40 border-y border-white/10">
        <div class="max-w-4xl mx-auto px-6 py-24">
            <div class="text-center reveal">
                <h2 class="text-4xl md:text-5xl font-extrabold text-white tracking-tight">Cara Kerjanya</h2>
                <p class="mt-4 text-slate-400 text-lg">Empat langkah sederhana dari gudang sampai laporan.</p>
            </div>
            <div class="mt-16 relative">
                <div class="absolute left-5 md:left-1/2 top-2 bottom-2 w-px bg-gradient-to-b from-amber-400/60 via-orange-500/40 to-transparent md:-translate-x-1/2"></div>
                @php
                    $steps = [
                        ['t' => 'Pengadaan', 'd' => 'Owner input batch dodol dari supplier dengan HPP sesuai. Stok gudang siap didistribusi.'],
                        ['t' => 'Mulai Trip', 'd' => 'Operator memulai trip, membawa stok mika, dan menitipkan ke kios-kios sesuai rute.'],
                        ['t' => 'Tagihan', 'd' => 'Penjualan dihitung per biji, dikonversi ke mika. Kios cash langsung lunas saat itu juga.'],
                        ['t' => 'Laporan', 'd' => 'Komisi, HPP, dan omzet otomatis terekap. Owner tinggal export PDF/Excel.'],
                    ];
                @endphp
                <div class="space-y-10">
                    @foreach ($steps as $i => $st)
                        <div class="reveal relative flex items-start gap-6 md:gap-0 md:grid md:grid-cols-2 md:items-center {{ $i % 2 ? 'md:[direction:rtl]' : '' }}">
                            <div class="{{ $i % 2 ? 'md:pl-12' : 'md:pr-12' }} [direction:ltr] flex-1">
                                <div class="rounded-2xl border border-white/10 bg-white/5 backdrop-blur p-6 hover:border-amber-400/40 transition">
                                    <h3 class="text-lg font-bold text-amber-400">{{ $st['t'] }}</h3>
                                    <p class="mt-1.5 text-slate-300 text-[15px] leading-relaxed">{{ $st['d'] }}</p>
                                </div>
                            </div>
                            <div class="absolute left-5 md:static md:mx-auto -translate-x-1/2 md:translate-x-0 grid place-items-center w-10 h-10 rounded-full bg-gradient-to-br from-amber-400 to-orange-600 text-slate-950 font-extrabold shadow-lg shadow-amber-500/30 ring-4 ring-slate-950">
                                {{ $i + 1 }}
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    <!-- TESTIMONI -->
    <section id="testimoni" class="max-w-6xl mx-auto px-6 py-24">
        <div class="grid md:grid-cols-2 gap-6">
            @php
                $quotes = [
                    ['q' => 'Dulu hitung setoran biji ke mika selalu meleset. Sekarang tinggal input, sistemnya yang hitung. Komisi operator nggak pernah ribut lagi.', 'n' => 'Bu Qontas', 'r' => 'Owner — Cemilan Qontas'],
                    ['q' => 'Mulai trip, titip ke kios, tagih, semua dari HP. Akhir bulan laporan langsung jadi PDF. Distribusi 200-an kios terasa ringan.', 'n' => 'Operator Lapangan', 'r' => 'Tim Distribusi'],
                ];
            @endphp
            @foreach ($quotes as $q)
                <figure class="reveal relative rounded-3xl border border-white/10 bg-gradient-to-br from-white/[0.07] to-transparent p-8 md:p-10">
                    <svg class="w-10 h-10 text-amber-400/40" fill="currentColor" viewBox="0 0 24 24"><path d="M9.983 3v7.391c0 5.704-3.731 9.57-8.983 10.609l-.995-2.151c2.432-.917 3.995-3.638 3.995-5.849h-4v-10h9.983zm14.017 0v7.391c0 5.704-3.748 9.57-9 10.609l-.996-2.151c2.433-.917 3.996-3.638 3.996-5.849h-3.983v-10h9.983z"/></svg>
                    <blockquote class="mt-4 text-lg md:text-xl text-slate-200 leading-relaxed font-medium">“{{ $q['q'] }}”</blockquote>
                    <figcaption class="mt-6 flex items-center gap-3">
                        <span class="grid place-items-center w-11 h-11 rounded-full bg-gradient-to-br from-amber-400 to-orange-600 text-slate-950 font-bold">{{ mb_substr($q['n'], 0, 1) }}</span>
                        <div>
                            <div class="font-bold text-white">{{ $q['n'] }}</div>
                            <div class="text-sm text-slate-400">{{ $q['r'] }}</div>
                        </div>
                    </figcaption>
                </figure>
            @endforeach
        </div>
    </section>

    <!-- CTA -->
    <section class="max-w-6xl mx-auto px-6 pb-24">
        <div class="reveal relative overflow-hidden rounded-[2rem] p-12 md:p-16 text-center bg-gradient-to-br from-amber-400 via-orange-500 to-orange-600 shadow-2xl shadow-orange-500/30">
            <div class="absolute -top-16 -right-16 w-64 h-64 bg-white/20 rounded-full blur-3xl"></div>
            <div class="absolute -bottom-16 -left-16 w-64 h-64 bg-amber-900/20 rounded-full blur-3xl"></div>
            <div class="relative">
                <h2 class="text-3xl md:text-5xl font-extrabold text-slate-950 tracking-tight">Siap merapikan distribusi Anda?</h2>
                <p class="mt-4 text-slate-900/80 text-lg max-w-xl mx-auto font-medium">Masuk sekarang dan kelola kios, trip, serta laporan dalam satu dasbor.</p>
                <a href="{{ route('login') }}" class="mt-8 inline-flex items-center gap-2 rounded-2xl bg-slate-950 px-8 py-4 font-bold text-amber-400 shadow-xl hover:-translate-y-1 transition text-base">
                    Masuk ke Dasbor
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5-5 5M6 12h12"/></svg>
                </a>
            </div>
        </div>
    </section>

    <!-- FOOTER -->
    <footer class="border-t border-white/10 bg-slate-950">
        <div class="max-w-6xl mx-auto px-6 py-12 flex flex-col md:flex-row items-center justify-between gap-6">
            <a href="#" class="flex items-center gap-2.5">
                <span class="grid place-items-center w-9 h-9 rounded-xl bg-gradient-to-br from-amber-400 to-orange-600 text-slate-950 font-extrabold text-lg">Q</span>
                <span class="font-extrabold text-white">Cemilan Qontas</span>
            </a>
            <p class="text-sm text-slate-500">Sistem Distribusi Dodol — dibuat untuk bisnis yang tumbuh.</p>
            <p class="text-sm text-slate-500">© {{ date('Y') }} Cemilan Qontas. All rights reserved.</p>
        </div>
    </footer>

    <script>
        // Navbar shadow on scroll
        const nav = document.getElementById('navbar');
        const onScroll = () => nav.classList.toggle('shadow-2xl', window.scrollY > 20);
        window.addEventListener('scroll', onScroll, { passive: true });

        // Reveal on scroll (Intersection Observer)
        const revealObserver = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('visible');
                    revealObserver.unobserve(entry.target);
                }
            });
        }, { threshold: 0.12 });
        document.querySelectorAll('.reveal').forEach((el) => revealObserver.observe(el));

        // Animated counters
        const animateCounter = (el) => {
            const target = parseInt(el.dataset.target, 10);
            const duration = 1600;
            const start = performance.now();
            const tick = (now) => {
                const p = Math.min((now - start) / duration, 1);
                const eased = 1 - Math.pow(1 - p, 3);
                el.textContent = Math.floor(eased * target).toLocaleString('id-ID');
                if (p < 1) requestAnimationFrame(tick);
                else el.textContent = target.toLocaleString('id-ID');
            };
            requestAnimationFrame(tick);
        };
        const counterObserver = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    animateCounter(entry.target);
                    counterObserver.unobserve(entry.target);
                }
            });
        }, { threshold: 0.6 });
        document.querySelectorAll('.counter').forEach((el) => counterObserver.observe(el));

        // 3D tilt effect on feature cards
        document.querySelectorAll('.tilt-card').forEach((card) => {
            card.addEventListener('mousemove', (e) => {
                const r = card.getBoundingClientRect();
                const px = (e.clientX - r.left) / r.width - 0.5;
                const py = (e.clientY - r.top) / r.height - 0.5;
                card.style.transform = `perspective(900px) rotateY(${px * 8}deg) rotateX(${-py * 8}deg) translateY(-4px)`;
            });
            card.addEventListener('mouseleave', () => {
                card.style.transform = 'perspective(900px) rotateY(0) rotateX(0) translateY(0)';
            });
        });
    </script>
</body>
</html>
