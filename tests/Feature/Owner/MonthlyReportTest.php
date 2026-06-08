<?php

namespace Tests\Feature\Owner;

use App\Models\User;
use App\Models\Trip;
use App\Models\Cluster;
use App\Models\Kiosk;
use App\Models\Delivery;
use App\Models\Commission;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonthlyReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_monthly_report_calculations_are_accurate_and_scoped_properly()
    {
        // 1. Setup Owner A with custom harga_mika = 300
        $ownerA = User::factory()->create([
            'role' => 'owner',
            'is_active' => true,
            'harga_mika' => 300.00,
        ]);

        // Setup Operator for Owner A
        $operatorA = User::factory()->create([
            'role' => 'operator',
            'is_active' => true,
            'owner_id' => $ownerA->id,
        ]);

        $clusterA = Cluster::create(['name' => 'Cluster Owner A', 'owner_id' => $ownerA->id]);
        $kiosA = Kiosk::factory()->create(['cluster_id' => $clusterA->id]);
        $variant = ProductVariant::factory()->create(['is_active' => true]);

        // Trip for Owner A in target month (June 2026)
        $tripA = Trip::factory()->create([
            'owner_id' => $ownerA->id,
            'operator_id' => $operatorA->id,
            'starting_cluster_id' => $clusterA->id,
            'trip_date' => '2026-06-15',
            'ended_at' => now(),
            'qty_carried_total' => 50,
        ]);

        // Deliveries for Owner A in June 2026
        Delivery::factory()->create([
            'trip_id' => $tripA->id,
            'kiosk_id' => $kiosA->id,
            'product_variant_id' => $variant->id,
            'qty_delivered' => 15,
        ]);

        Delivery::factory()->create([
            'trip_id' => $tripA->id,
            'kiosk_id' => $kiosA->id,
            'product_variant_id' => $variant->id,
            'qty_delivered' => 5,
        ]);

        // Commission for Owner A in June 2026
        // cash_collected_reported = 100000, margin_rate_assumed = 0.25, commission_rate = 0.2
        // commission_amount = 100000 * 0.25 * 0.2 = 5000.00
        Commission::create([
            'trip_id' => $tripA->id,
            'operator_id' => $operatorA->id,
            'cash_collected_reported' => 100000.00,
            'margin_rate_assumed' => 0.2500,
            'commission_rate' => 0.2000,
            'status' => 'paid',
            'paid_at' => '2026-06-15 17:00:00',
        ]);

        // 2. Setup Owner B with default harga_mika = 200 (to test tenant isolation)
        $ownerB = User::factory()->create([
            'role' => 'owner',
            'is_active' => true,
        ]);

        $operatorB = User::factory()->create([
            'role' => 'operator',
            'is_active' => true,
            'owner_id' => $ownerB->id,
        ]);

        $clusterB = Cluster::create(['name' => 'Cluster Owner B', 'owner_id' => $ownerB->id]);
        $kiosB = Kiosk::factory()->create(['cluster_id' => $clusterB->id]);

        $tripB = Trip::factory()->create([
            'owner_id' => $ownerB->id,
            'operator_id' => $operatorB->id,
            'starting_cluster_id' => $clusterB->id,
            'trip_date' => '2026-06-15',
            'ended_at' => now(),
            'qty_carried_total' => 50,
        ]);

        Delivery::factory()->create([
            'trip_id' => $tripB->id,
            'kiosk_id' => $kiosB->id,
            'product_variant_id' => $variant->id,
            'qty_delivered' => 20, // should not affect Owner A
        ]);

        // 3. Setup Trip for Owner A in a DIFFERENT month (e.g. July 2026, to test month isolation)
        $tripAPast = Trip::factory()->create([
            'owner_id' => $ownerA->id,
            'operator_id' => $operatorA->id,
            'starting_cluster_id' => $clusterA->id,
            'trip_date' => '2026-07-15',
            'ended_at' => now(),
            'qty_carried_total' => 50,
        ]);

        Delivery::factory()->create([
            'trip_id' => $tripAPast->id,
            'kiosk_id' => $kiosA->id,
            'product_variant_id' => $variant->id,
            'qty_delivered' => 10, // should not affect June 2026
        ]);

        // 4. Request the Monthly Report page for Owner A, June 2026
        $response = $this->actingAs($ownerA)->get('/owner/reports/monthly?month=2026-06');

        $response->assertOk();

        // 5. Assert calculations
        // Total Mika Diantar: 15 + 5 = 20
        $response->assertViewHas('total_mika_diantar', 20);

        // Modal Mika: 20 * 300 = 6000
        $response->assertViewHas('modal_mika', 6000);

        // Rekap Komisi: 5000
        $response->assertViewHas('rekap_komisi', 5000);

        // Check if values are rendered in the HTML view
        $response->assertSee('20 mika');
        $response->assertSee('Rp 6.000');
        $response->assertSee('Rp 5.000');
    }
}
