@extends('layouts.owner')
@section('title', 'Pengaturan Owner')

@section('content')
    <div class="max-w-xl mx-auto space-y-6">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">Pengaturan</h1>
            <p class="mt-1 text-slate-600">Atur HPP per mika untuk bisnis Anda.</p>
        </div>

        @if (session('status'))
            <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                {{ session('status') }}
            </div>
        @endif

        <form method="POST" action="{{ route('owner.settings.update') }}"
              class="bg-white rounded-lg border border-slate-200 p-6 space-y-4">
            @csrf
            @method('PUT')

            <div>
                <label for="hpp_per_mika" class="block text-sm font-medium text-slate-700">
                    HPP per Mika (Rp)
                </label>
                <input type="number" step="0.01" min="1" name="hpp_per_mika" id="hpp_per_mika"
                       value="{{ old('hpp_per_mika', (int) $user->getHppPerMikaValue()) }}"
                       class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                <p class="mt-1 text-xs text-slate-500">Harga Pokok Produksi per mika. Default: Rp 9.500.</p>
                @error('hpp_per_mika')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex justify-end">
                <button type="submit"
                        class="inline-flex items-center rounded-md bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700">
                    Simpan
                </button>
            </div>
        </form>
    </div>
@endsection
