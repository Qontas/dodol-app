{{-- /profile dipakai owner & super admin. Owner mendapat layout brand Cemilan
     Qontas (sidebar amber, konsisten dashboard owner); super admin tetap pakai
     layout app generik sebagai fallback aman (route owner.* tak relevan untuknya).
     Kedua layout memuat guard identitas (meta auth-uid + pwa-token-refresh). --}}
@php($brandOwner = auth()->check() && auth()->user()->isOwner())

@if ($brandOwner)
    @extends('layouts.owner')

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
@else
    {{-- Fallback super admin: layout app generik, tetap bertema amber. --}}
    <x-app-layout>
        <x-slot name="header">
            <h2 class="font-bold text-xl text-slate-900 leading-tight">{{ __('Profil Saya') }}</h2>
        </x-slot>

        <div class="py-8 sm:py-12">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                    <div class="h-1.5 bg-gradient-to-r from-amber-400 to-amber-600"></div>
                    <div class="p-5 sm:p-8">
                        <div class="max-w-xl">
                            <livewire:profile.update-profile-information-form />
                        </div>
                    </div>
                </div>

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
@endif
