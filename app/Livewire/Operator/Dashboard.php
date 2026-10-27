<?php

namespace App\Livewire\Operator;

use App\Models\KioskVisit;
use App\Models\Trip;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.operator')]
class Dashboard extends Component
{
    public ?Trip $activeTrip = null;

    public int $tripsToday = 0;

    public int $kiosksVisitedToday = 0;

    public function mount(): void
    {
        $this->loadStats();
    }

    protected function loadStats(): void
    {
        // BRIEF 27 Okt 2026 "TRIP LUPA DIAKHIRI" item 3 — TANPA filter tanggal
        // (sama alasan StartTrip::activeTrip()): jaring pengaman tambahan, trip
        // hari-hari sebelumnya seharusnya SUDAH ditutup otomatis oleh
        // App\Http\Middleware\AutoCloseStaleTrips sebelum komponen ini mount().
        $this->activeTrip = Trip::where('operator_id', auth()->id())
            ->whereNotNull('started_at')
            ->whereNull('ended_at')
            ->latest('id')
            ->first();

        $this->tripsToday = Trip::where('operator_id', auth()->id())
            ->whereDate('trip_date', today())
            ->count();

        $this->kiosksVisitedToday = KioskVisit::active()->whereHas('trip', function ($query) {
            $query->where('operator_id', auth()->id())
                ->whereDate('trip_date', today());
        })->count();
    }

    public function render()
    {
        return view('livewire.operator.dashboard');
    }
}
