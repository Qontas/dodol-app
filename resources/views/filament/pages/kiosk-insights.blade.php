<x-filament-panels::page>
    {{-- Shared component uses the application's utility styles, not Filament's limited vendor build. --}}
    @vite('resources/css/app.css')
    @livewire(\App\Livewire\Owner\KioskInsightDownloads::class, ['embedded' => true])
</x-filament-panels::page>
