<?php

namespace Tests\Feature\Operator;

use App\Models\User;
use App\Models\Trip;
use App\Models\Kiosk;
use App\Models\Cluster;
use App\Models\ProductVariant;
use App\Models\ProcurementBatch;
use App\Models\Delivery;
use App\Livewire\Operator\ActiveTrip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ActiveTripTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_can_sort_kiosks_by_distance()
    {
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true]);
        
        $cluster = Cluster::create(['name' => 'Cluster A']);
        
        $trip = Trip::factory()->create([
            'operator_id' => $operator->id,
            'starting_cluster_id' => $cluster->id,
            'qty_carried_total' => 10,
            'started_at' => now(),
            'trip_date' => today()->format('Y-m-d'),
        ]);
        
        $kioskFar = Kiosk::factory()->create([
            'cluster_id' => $cluster->id,
            'latitude' => 0.1,
            'longitude' => 0.1,
            'name' => 'Far Kiosk',
        ]);
        
        $kioskClose = Kiosk::factory()->create([
            'cluster_id' => $cluster->id,
            'latitude' => 0.001,
            'longitude' => 0.001,
            'name' => 'Close Kiosk',
        ]);
        
        $kioskMedium = Kiosk::factory()->create([
            'cluster_id' => $cluster->id,
            'latitude' => 0.01,
            'longitude' => 0.01,
            'name' => 'Medium Kiosk',
        ]);
        
        $kioskNoGeo = Kiosk::factory()->create([
            'cluster_id' => $cluster->id,
            'latitude' => null,
            'longitude' => null,
            'name' => 'No Geo Kiosk',
        ]);

        $this->actingAs($operator);

        Livewire::test(ActiveTrip::class)
            ->assertOk()
            ->assertSet('sortedByDistance', false)
            ->call('sortByDistance', 0.0, 0.0)
            ->assertSet('sortedByDistance', true)
            ->assertSet('userLat', 0.0)
            ->assertSet('userLng', 0.0)
            ->assertSeeHtmlInOrder([
                'Close Kiosk',
                'Medium Kiosk',
                'Far Kiosk',
                'No Geo Kiosk'
            ]);
    }

    public function test_visited_kiosks_are_placed_at_the_bottom_even_when_sorted_by_distance()
    {
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true]);
        $cluster = Cluster::create(['name' => 'Cluster A']);
        
        $trip = Trip::factory()->create([
            'operator_id' => $operator->id,
            'starting_cluster_id' => $cluster->id,
            'qty_carried_total' => 10,
            'started_at' => now(),
            'trip_date' => today()->format('Y-m-d'),
        ]);
        
        $kioskFar = Kiosk::factory()->create([
            'cluster_id' => $cluster->id,
            'latitude' => 0.1,
            'longitude' => 0.1,
            'name' => 'Far Kiosk',
        ]);
        
        $kioskClose = Kiosk::factory()->create([
            'cluster_id' => $cluster->id,
            'latitude' => 0.001,
            'longitude' => 0.001,
            'name' => 'Close Kiosk',
        ]);
        
        $kioskMedium = Kiosk::factory()->create([
            'cluster_id' => $cluster->id,
            'latitude' => 0.01,
            'longitude' => 0.01,
            'name' => 'Medium Kiosk',
        ]);
        
        // Make $kioskClose visited
        \App\Models\KioskVisit::create([
            'trip_id' => $trip->id,
            'kiosk_id' => $kioskClose->id,
            'visited_at' => now(),
            'visit_action' => 'check_only',
        ]);

        $this->actingAs($operator);

        Livewire::test(ActiveTrip::class)
            ->call('sortByDistance', 0.0, 0.0)
            ->assertSeeHtmlInOrder([
                'Medium Kiosk',
                'Far Kiosk',
                'Close Kiosk' // Visited should go last
            ]);
    }

    public function test_operator_end_trip_creates_correct_commission()
    {
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true]);
        $cluster = Cluster::create(['name' => 'Cluster A']);
        
        $trip = Trip::factory()->create([
            'operator_id' => $operator->id,
            'starting_cluster_id' => $cluster->id,
            'qty_carried_total' => 60,
            'started_at' => now(),
            'trip_date' => today()->format('Y-m-d'),
        ]);

        $variant = ProductVariant::factory()->create();
        $batch = ProcurementBatch::factory()->create([
            'product_variant_id' => $variant->id,
            'qty_packs' => 10,
            'cost_raw_material' => 50000,
            'cost_packing' => 10000,
            'cost_packaging' => 10000,
            'cost_other' => 10000,
        ]);

        $kiosk = Kiosk::factory()->create(['cluster_id' => $cluster->id]);

        $delivery = Delivery::factory()->create([
            'kiosk_id' => $kiosk->id,
            'trip_id' => $trip->id,
            'product_variant_id' => $variant->id,
            'procurement_batch_id' => $batch->id,
            'qty_delivered' => 5,
        ]);

        $pastTrip = Trip::factory()->create([
            'operator_id' => $operator->id,
            'trip_date' => today()->subDays(5)->format('Y-m-d'),
        ]);
        $pendingDelivery = Delivery::factory()->create([
            'kiosk_id' => $kiosk->id,
            'trip_id' => $pastTrip->id,
            'product_variant_id' => $variant->id,
            'qty_delivered' => 10,
        ]);

        $this->actingAs($operator);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->set('returnFresh', 0)
            ->set('returnExpired', 0)
            ->set('uangDiterima', 120000)
            ->call('hitungTagihan')
            ->call('saveVisit')
            ->assertHasNoErrors();

        Livewire::test(ActiveTrip::class)
            ->call('openEndTripModal')
            ->assertSet('tripSummary.total_uang_diterima', 120000)
            ->assertSet('tripSummary.hpp_estimasi', 95000)
            ->assertSet('tripSummary.komisi_rian', 5000)
            ->set('endReason', 'target_done')
            ->call('confirmEndTrip')
            ->assertRedirect(route('operator.dashboard'));

        $this->assertDatabaseHas('commissions', [
            'trip_id' => $trip->id,
            'operator_id' => $operator->id,
            'cash_collected_reported' => 120000,
            'commission_rate' => 0.2000,
        ]);

        $commission = \App\Models\Commission::where('trip_id', $trip->id)->first();
        $this->assertNotNull($commission);
        $this->assertEqualsWithDelta(5000, (float) $commission->commission_amount, 1.0);
    }
}
