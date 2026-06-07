<?php

namespace Tests\Feature\Owner;

use App\Models\User;
use App\Models\Settlement;
use App\Models\Delivery;
use App\Models\Kiosk;
use App\Models\ProductVariant;
use App\Models\Cluster;
use App\Models\Trip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_dashboard_shows_correct_data_and_chart_variables()
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);

        $kiosk = Kiosk::factory()->create();
        $variant = ProductVariant::factory()->create([
            'sale_price_per_pack' => 12000,
            'is_active' => true,
        ]);
        
        $delivery = Delivery::factory()->create([
            'kiosk_id' => $kiosk->id,
            'product_variant_id' => $variant->id,
            'qty_delivered' => 5,
        ]);

        Settlement::factory()->create([
            'delivery_id' => $delivery->id,
            'visit_date' => today(),
            'qty_sold' => 75,
            'qty_returned_fresh' => 0,
            'qty_returned_expired' => 0,
            'amount_due' => 60000,
            'amount_paid' => 60000,
            'status' => 'paid',
        ]);

        $deliveryPast = Delivery::factory()->create([
            'kiosk_id' => $kiosk->id,
            'product_variant_id' => $variant->id,
            'qty_delivered' => 5,
        ]);

        Settlement::factory()->create([
            'delivery_id' => $deliveryPast->id,
            'visit_date' => today()->subDays(2),
            'qty_sold' => 45,
            'qty_returned_fresh' => 30,
            'qty_returned_expired' => 0,
            'amount_due' => 36000,
            'amount_paid' => 36000,
            'status' => 'paid',
        ]);

        $response = $this->actingAs($owner)->get('/owner/dashboard');

        $response->assertOk();
        $response->assertViewHas('chartLabels');
        $response->assertViewHas('chartData');

        $chartData = $response->viewData('chartData');
        $chartLabels = $response->viewData('chartLabels');

        $this->assertCount(30, $chartData);
        $this->assertCount(30, $chartLabels);

        // Today's index is the last one (index 29)
        $this->assertEquals(60000, $chartData[29]);
        
        // Two days ago is index 27
        $this->assertEquals(36000, $chartData[27]);

        // The day before that (index 28) should be 0
        $this->assertEquals(0, $chartData[28]);
    }

    public function test_owner_dashboard_displays_real_time_trip_progress()
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'name' => 'Rian']);
        
        $cluster = Cluster::create(['name' => 'Cluster A']);
        
        $trip = Trip::factory()->create([
            'operator_id' => $operator->id,
            'starting_cluster_id' => $cluster->id,
            'qty_carried_total' => 60,
            'started_at' => now(),
            'trip_date' => today()->format('Y-m-d'),
        ]);

        $kiosk = Kiosk::factory()->create([
            'cluster_id' => $cluster->id,
            'name' => 'Kios Rian Live Test',
        ]);

        $response = $this->actingAs($owner)->get('/owner/dashboard');

        $response->assertOk();
        $response->assertViewHas('activeTrips');
        $response->assertSee('Rian');
        $response->assertSee('Cluster A');
        $response->assertSee('60 mika');
    }
}
