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

    public function test_fresh_return_redeploy_with_correct_biji_passes(): void
    {
        $origin = Settlement::factory()->create();

        $delivery = Delivery::factory()
            ->freshReturn($origin->id, 75)
            ->create(['qty_delivered' => 5]);

        $this->assertDatabaseHas('deliveries', [
            'id' => $delivery->id,
            'source_type' => 'fresh_return_redeploy',
            'procurement_batch_id' => null,
        ]);
        $this->assertDatabaseHas('delivery_origins', [
            'delivery_id' => $delivery->id,
            'settlement_id' => $origin->id,
            'biji_count' => 75,
        ]);
    }

    public function test_fresh_return_redeploy_without_origin_throws(): void
    {
        $delivery = Delivery::factory()->create([
            'source_type' => 'fresh_return_redeploy',
            'procurement_batch_id' => null,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/requires at least 1 delivery_origins row/');

        $delivery->validateOrigins();
    }

    public function test_fresh_return_redeploy_with_wrong_biji_sum_throws(): void
    {
        $origin = Settlement::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/sum\(biji_count\)=50 must equal qty_delivered\*15=75/');

        Delivery::factory()
            ->freshReturn($origin->id, 50)
            ->create(['qty_delivered' => 5]);
    }

    public function test_fresh_return_redeploy_with_procurement_batch_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must not have procurement_batch_id/');

        Delivery::factory()->create([
            'source_type' => 'fresh_return_redeploy',
        ]);
    }

    public function test_new_procurement_with_delivery_origins_throws(): void
    {
        $delivery = Delivery::factory()->create();
        $origin = Settlement::factory()->create();
        $delivery->originSettlements()->attach($origin->id, ['biji_count' => 50]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must not have delivery_origins rows/');

        $delivery->validateOrigins();
    }
}
