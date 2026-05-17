<?php

namespace App\Livewire\Operator;

use App\Models\Cluster;
use App\Models\Trip;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.operator')]
class ActiveTrip extends Component
{
    public Trip $trip;

    public ?int $startingClusterId = null;

    public function mount(int $tripId): void
    {
        $this->trip = Trip::where('id', $tripId)
            ->where('operator_id', auth()->id())
            ->whereNotNull('started_at')
            ->whereNull('ended_at')
            ->firstOrFail();

        $this->startingClusterId = session()->get("trip_{$tripId}_starting_cluster");
    }

    public function render()
    {
        return view('livewire.operator.active-trip', [
            'startingCluster' => $this->startingClusterId
                ? Cluster::find($this->startingClusterId)
                : null,
        ]);
    }
}
