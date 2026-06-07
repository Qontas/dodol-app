<?php

namespace Tests\Unit;

use App\Models\ProcurementBatch;
use App\Models\Delivery;
use App\Models\Kiosk;
use App\Models\ProductVariant;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcurementBatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_remaining_packs_accessor_calculates_correctly()
    {
        $batch = ProcurementBatch::factory()->create([
            'qty_packs' => 100,
        ]);

        $this->assertEquals(100, $batch->remaining_packs);

        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true]);
        $trip = Trip::factory()->create([
            'operator_id' => $operator->id,
            'qty_carried_total' => 60,
            'trip_date' => today()->format('Y-m-d'),
        ]);

        $kiosk = Kiosk::factory()->create();
        $variant = ProductVariant::factory()->create();

        // Create a delivery linked to this batch
        Delivery::factory()->create([
            'kiosk_id' => $kiosk->id,
            'trip_id' => $trip->id,
            'product_variant_id' => $variant->id,
            'procurement_batch_id' => $batch->id,
            'qty_delivered' => 20,
            'source_type' => 'new_procurement',
        ]);

        $batch->refresh();
        $this->assertEquals(80, $batch->remaining_packs);

        // Create another delivery
        Delivery::factory()->create([
            'kiosk_id' => $kiosk->id,
            'trip_id' => $trip->id,
            'product_variant_id' => $variant->id,
            'procurement_batch_id' => $batch->id,
            'qty_delivered' => 35,
            'source_type' => 'new_procurement',
        ]);

        $batch->refresh();
        $this->assertEquals(45, $batch->remaining_packs);
    }
}
