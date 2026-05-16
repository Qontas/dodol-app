<?php

namespace Tests\Unit\Observers;

use App\Models\Delivery;
use App\Models\Settlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class DeliveryObserverTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_procurement_with_batch_passes(): void
    {
        $delivery = Delivery::factory()->create([
            'source_type' => 'new_procurement',
        ]);

        $this->assertDatabaseHas('deliveries', [
            'id' => $delivery->id,
            'source_type' => 'new_procurement',
        ]);
        $this->assertNotNull($delivery->procurement_batch_id);
    }

    public function test_new_procurement_without_batch_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/procurement_batch_id/');

        Delivery::factory()->create([
            'source_type' => 'new_procurement',
            'procurement_batch_id' => null,
        ]);
    }

    public function test_fresh_return_redeploy_with_origin_passes(): void
    {
        $parentSettlement = Settlement::factory()->create();

        $delivery = Delivery::factory()->create([
            'source_type' => 'fresh_return_redeploy',
            'origin_settlement_id' => $parentSettlement->id,
            'procurement_batch_id' => null,
        ]);

        $this->assertDatabaseHas('deliveries', [
            'id' => $delivery->id,
            'source_type' => 'fresh_return_redeploy',
            'origin_settlement_id' => $parentSettlement->id,
        ]);
    }

    public function test_fresh_return_redeploy_without_origin_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/origin_settlement_id/');

        Delivery::factory()->create([
            'source_type' => 'fresh_return_redeploy',
            'origin_settlement_id' => null,
            'procurement_batch_id' => null,
        ]);
    }
}
