{{-- /profile dipakai owner & super admin (route auth global). Layout dipilih
     per-role: owner → layout brand owner (sidebar amber, konsisten dashboard
     owner); super admin (atau role lain) → layout brand minimal yang AMAN —
     header amber TANPA sidebar resource owner.*, jadi tak ada link 403.
     KEDUA layout memuat guard identitas (meta auth-uid + pwa-token-refresh)
     → super admin TIDAK kehilangan proteksi. Section Hapus Akun (delete-user-form)
     sengaja TIDAK di-render untuk semua peran. --}}
@extends(auth()->user()->isOwner() ? 'layouts.owner' : 'layouts.brand')

@section('title', 'Profil')

@section('content')
    <div class="max-w-3xl mx-auto space-y-6">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Profil Saya</h1>
            <p class="text-sm text-slate-500">Kelola informasi akun Cemilan Qontas kamu</p>
        </div>

        {{-- Informasi Profil --}}
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="h-1.5 bg-gradient-to-r from-amber-400 to-amber-600"></div>
            <div class="p-5 sm:p-8">
                <div class="max-w-xl">
                    <livewire:profile.update-profile-information-form />
                </div>
            </div>
        </div>

        {{-- Ganti Password --}}
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="h-1.5 bg-gradient-to-r from-amber-400 to-amber-600"></div>
            <div class="p-5 sm:p-8">
                <div class="max-w-xl">
                    <livewire:profile.update-password-form />
                </div>
            </div>
        </div>
    </div>
@endsection
