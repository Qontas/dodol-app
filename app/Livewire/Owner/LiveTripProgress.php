<?php

namespace App\Livewire\Owner;

use Livewire\Component;
use App\Models\Trip;
use App\Models\Kiosk;

class LiveTripProgress extends Component
{
    public function render()
    {
        // Multi-tenant: owner hanya lihat trip bisnisnya sendiri.
        $activeTrips = Trip::whereNull('ended_at')
            ->where('owner_id', auth()->id())
            ->with(['operator', 'startingCluster', 'visits.kiosk', 'deliveries'])
            ->get();

        return view('livewire.owner.live-trip-progress', compact('activeTrips'));
    }
}
