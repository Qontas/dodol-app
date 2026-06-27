<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <span class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-gradient-to-br from-amber-500 to-amber-600 text-white font-bold shadow-sm">Q</span>
            <div>
                <h2 class="font-bold text-xl text-slate-900 leading-tight">
                    {{ __('Profil Saya') }}
                </h2>
                <p class="text-sm text-slate-500">Kelola informasi akun Cemilan Qontas kamu</p>
            </div>
        </div>
    </x-slot>

    <div class="py-8 sm:py-12">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
            {{-- Info Profil --}}
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
    </div>
</x-app-layout>
