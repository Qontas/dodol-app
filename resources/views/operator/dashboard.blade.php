@extends('layouts.operator')
@section('title', 'Beranda')

@section('content')
    <div class="max-w-md mx-auto space-y-5">
        <div>
            <p class="text-sm text-slate-500">Halo,</p>
            <h1 class="text-xl font-semibold text-slate-900">{{ $user->name }}</h1>
        </div>

        <button type="button" disabled
                class="w-full bg-amber-600 text-white text-base font-semibold py-4 rounded-lg shadow opacity-50 cursor-not-allowed">
            Mulai Trip
        </button>

        <p class="text-center text-sm text-slate-500">
            Belum ada trip aktif. Tap tombol di atas untuk mulai.
        </p>

        <div class="bg-white border border-slate-200 rounded-lg p-4 text-center">
            <div class="text-xs uppercase tracking-wide text-slate-500">Hari Ini</div>
            <div class="mt-1 text-lg font-semibold text-slate-900">0 kios dikunjungi</div>
        </div>
    </div>
@endsection
