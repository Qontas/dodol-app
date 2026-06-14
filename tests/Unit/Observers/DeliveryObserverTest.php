<?php

namespace Tests\Unit\Observers;

use App\Models\Delivery;
use App\Models\Kiosk;
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

    public function test_new_procurement_without_batch_passes(): void
    {
        // Operasional bebas: operator tidak wajib link ke batch.
        // Batch = catatan owner saja, procurement_batch_id boleh null.
        $delivery = Delivery::factory()->create([
            'source_type' => 'new_procurement',
            'procurement_batch_id' => null,
        ]);

        $this->assertDatabaseHas('deliveries', [
            'id' => $delivery->id,
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

    public function test_first_consignment_sets_first_titip_date_when_null(): void
    {
        $kiosk = Kiosk::factory()->create(['first_titip_date' => null]);

        $delivery = Delivery::factory()->create([
            'kiosk_id' => $kiosk->id,
            'delivery_type' => 'consignment',
        ]);

        $this->assertSame(
            $delivery->created_at->toDateString(),
            $kiosk->fresh()->first_titip_date->toDateString(),
        );
    }

    public function test_existing_first_titip_date_is_not_overridden(): void
    {
        $kiosk = Kiosk::factory()->create(['first_titip_date' => '2025-01-15']);

        Delivery::factory()->create([
            'kiosk_id' => $kiosk->id,
            'delivery_type' => 'consignment',
        ]);

        $this->assertSame('2025-01-15', $kiosk->fresh()->first_titip_date->toDateString());
    }

    public function test_non_consignment_does_not_set_first_titip_date(): void
    {
        foreach (['cash_sale', 'bs_redistribution'] as $type) {
            $kiosk = Kiosk::factory()->create(['first_titip_date' => null]);

            Delivery::factory()->create([
                'kiosk_id' => $kiosk->id,
                'delivery_type' => $type,
            ]);

            $this->assertNull($kiosk->fresh()->first_titip_date, "type {$type} should not set first_titip_date");
        }
    }
}
