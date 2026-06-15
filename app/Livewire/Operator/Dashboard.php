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
        $this->activeTrip = Trip::where('operator_id', auth()->id())
            ->whereDate('trip_date', today())
            ->whereNotNull('started_at')
            ->whereNull('ended_at')
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
