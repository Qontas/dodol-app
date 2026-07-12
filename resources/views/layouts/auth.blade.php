<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Login - Cemilan Qontas</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    {{-- Bersihkan cache klien lama (device yang sudah terlanjur kena bug SW v1). --}}
    @include('partials.pwa-cache-clear')
</head>
<body class="font-sans text-slate-900 antialiased bg-slate-50 flex items-center justify-center min-h-screen">
    {{-- {{ $slot }} = SATU root element komponen Livewire. Doctype/head/body ada di
         layout ini (BUKAN di dalam komponen) supaya Livewire punya root tunggal yang
         bisa di-morph saat menampilkan error validasi — tanpa mengosongkan <body>
         (akar bug "halaman putih saat login gagal": komponen dulu me-render seluruh
         dokumen HTML sebagai root, morph gagal → "Could not find Livewire component"). --}}
    {{ $slot }}
</body>
</html>
