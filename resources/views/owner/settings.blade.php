@extends('layouts.owner')
@section('title', 'Pengaturan Owner')

@section('content')
    <div class="max-w-xl mx-auto space-y-6">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">Pengaturan</h1>
            <p class="mt-1 text-slate-600">Atur identitas usaha, harga &amp; komisi, dan default untuk kedai baru.</p>
        </div>

        @if (session('status'))
            <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                {{ session('status') }}
            </div>
        @endif

        <form method="POST" action="{{ route('owner.settings.update') }}" class="space-y-6">
            @csrf
            @method('PUT')

            {{-- ========== Identitas Usaha ========== --}}
            <div class="bg-white rounded-lg border border-slate-200 p-6 space-y-4">
                <div>
                    <h2 class="text-base font-semibold text-slate-900">Identitas Usaha</h2>
                    <p class="text-xs text-slate-500">Nama ini tampil di judul halaman, laporan, dan file PDF/Excel yang diunduh.</p>
                </div>

                <div>
                    <label for="business_name" class="block text-sm font-medium text-slate-700">
                        Nama Usaha
                    </label>
                    <input type="text" maxlength="60" name="business_name" id="business_name"
                           value="{{ old('business_name', $user->getBusinessNameValue()) }}"
                           class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                    <p class="mt-1 text-xs text-slate-500">Maksimal 60 karakter. Kosongkan untuk pakai nama default "Cemilan Qontas".</p>
                    @error('business_name')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            {{-- ========== Harga & Komisi ========== --}}
            <div class="bg-white rounded-lg border border-slate-200 p-6 space-y-4">
                <div>
                    <h2 class="text-base font-semibold text-slate-900">Harga &amp; Komisi</h2>
                    <p class="text-xs text-slate-500">Dipakai untuk menghitung untung dan komisi operator.</p>
                </div>

                {{-- hpp_per_mika DIHAPUS dari form ini — HPP sekarang per-varian produk
                     (Master Data → Produk), bukan satu angka global per owner. --}}
                <div>
                    <label for="harga_mika" class="block text-sm font-medium text-slate-700">
                        Harga Mika (Rp)
                    </label>
                    <input type="number" step="0.01" min="0" name="harga_mika" id="harga_mika"
                           value="{{ old('harga_mika', (int) $user->getHargaMikaValue()) }}"
                           class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                    <p class="mt-1 text-xs text-slate-500">Harga modal mika per kemasan. Default: Rp 200.</p>
                    @error('harga_mika')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="komisi_per_mika" class="block text-sm font-medium text-slate-700">
                        Komisi Reguler per Mika (Rp)
                    </label>
                    <input type="number" step="0.01" min="0" name="komisi_per_mika" id="komisi_per_mika"
                           value="{{ old('komisi_per_mika', (int) $user->getKomisiPerMikaValue()) }}"
                           class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                    <p class="mt-1 text-xs text-slate-500">Komisi per mika yang terjual pada kios biasa. Default: Rp 500.</p>
                    @error('komisi_per_mika')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="komisi_kios_baru_per_mika" class="block text-sm font-medium text-slate-700">
                        Komisi Kios Baru per Mika (Rp)
                    </label>
                    <input type="number" step="0.01" min="0" name="komisi_kios_baru_per_mika" id="komisi_kios_baru_per_mika"
                           value="{{ old('komisi_kios_baru_per_mika', (int) $user->getKomisiKiosBaruPerMikaValue()) }}"
                           class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                    <p class="mt-1 text-xs text-slate-500">Komisi per mika untuk kios yang baru pertama kali dititipi. Default: Rp 1.000.</p>
                    @error('komisi_kios_baru_per_mika')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            {{-- ========== Default Kedai Baru ========== --}}
            <div class="bg-white rounded-lg border border-slate-200 p-6 space-y-4">
                <div>
                    <h2 class="text-base font-semibold text-slate-900">Default Kedai Baru</h2>
                    <p class="text-xs text-slate-500">
                        Hanya berlaku untuk kios dan trip BARU yang dibuat setelah disimpan. Kios dan trip yang sudah ada tidak berubah.
                    </p>
                </div>

                <div>
                    <label for="default_target_visit_interval_days" class="block text-sm font-medium text-slate-700">
                        Ideal Dikunjungi Tiap (hari)
                    </label>
                    <input type="number" min="1" max="365" step="1"
                           name="default_target_visit_interval_days" id="default_target_visit_interval_days"
                           value="{{ old('default_target_visit_interval_days', $user->getDefaultTargetVisitIntervalDaysValue()) }}"
                           class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                    <p class="mt-1 text-xs text-slate-500">Jarak ideal antar kunjungan ke satu kios. Default: 10 hari.</p>
                    @error('default_target_visit_interval_days')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="default_warning_visit_interval_days" class="block text-sm font-medium text-slate-700">
                        Peringatan Belum Dikunjungi (hari)
                    </label>
                    <input type="number" min="1" max="365" step="1"
                           name="default_warning_visit_interval_days" id="default_warning_visit_interval_days"
                           value="{{ old('default_warning_visit_interval_days', $user->getDefaultWarningVisitIntervalDaysValue()) }}"
                           class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                    <p class="mt-1 text-xs text-slate-500">Kios dianggap "lama tidak dikunjungi" setelah sekian hari. Harus &ge; Ideal Dikunjungi Tiap. Default: 14 hari.</p>
                    @error('default_warning_visit_interval_days')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="default_fast_mover_threshold_days" class="block text-sm font-medium text-slate-700">
                        Batas Kios Laris (hari)
                    </label>
                    <input type="number" min="1" max="365" step="1"
                           name="default_fast_mover_threshold_days" id="default_fast_mover_threshold_days"
                           value="{{ old('default_fast_mover_threshold_days', $user->getDefaultFastMoverThresholdDaysValue()) }}"
                           class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                    <p class="mt-1 text-xs text-slate-500">Kios dianggap "laris" jika stoknya habis dalam sekian hari atau kurang. Default: 7 hari.</p>
                    @error('default_fast_mover_threshold_days')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="kios_baru_days" class="block text-sm font-medium text-slate-700">
                        Masa "Kios Baru" (hari)
                    </label>
                    <input type="number" min="1" max="365" step="1"
                           name="kios_baru_days" id="kios_baru_days"
                           value="{{ old('kios_baru_days', $user->getKiosBaruDaysValue()) }}"
                           class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                    <p class="mt-1 text-xs text-slate-500">Lama status "kios baru" disematkan sejak kios pertama kali dititipi. Default: 30 hari.</p>
                    @error('kios_baru_days')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="default_qty_mika" class="block text-sm font-medium text-slate-700">
                        Default Jatah Titipan Kedai Baru (mika)
                    </label>
                    <input type="number" min="1" max="100" step="1"
                           name="default_qty_mika" id="default_qty_mika"
                           value="{{ old('default_qty_mika', $user->getDefaultQtyMikaValue()) }}"
                           class="mt-1 block w-full rounded-md border-slate-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                    <p class="mt-1 text-xs text-slate-500">Jumlah mika yang otomatis terisi saat membuat kios baru (owner maupun operator masih bisa ubah per kios). Default: 1 mika.</p>
                    @error('default_qty_mika')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
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
