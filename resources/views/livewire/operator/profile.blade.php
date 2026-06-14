<div class="max-w-md mx-auto">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-slate-900">Profil Saya</h1>
        <p class="text-slate-500 text-sm">Kelola informasi akun kamu</p>
    </div>

    <div class="space-y-5">
        {{-- Info Profil --}}
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-5">
            <livewire:operator.update-profile-form />
        </div>

        {{-- Ganti Password --}}
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-5">
            <livewire:operator.update-password-form />
        </div>
    </div>
</div>
