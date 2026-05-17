<?php

namespace Tests\Unit\Observers;

use App\Models\Delivery;
use App\Models\Settlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class SettlementObserverTest extends TestCase
{
    use RefreshDatabase;

    public function test_settlement_valid_qty_sum_passes(): void
    {
        $delivery = Delivery::factory()->create(['qty_delivered' => 15]);

        $settlement = Settlement::factory()->create([
            'delivery_id' => $delivery->id,
            'qty_sold' => 10,
            'qty_returned_fresh' => 2,
            'qty_returned_expired' => 3,
            'amount_due' => 120000,
            'amount_paid' => 120000,
        ]);

        $this->assertDatabaseHas('settlements', [
            'id' => $settlement->id,
            'delivery_id' => $delivery->id,
        ]);
    }

    public function test_settlement_invalid_qty_sum_throws(): void
    {
        $delivery = Delivery::factory()->create(['qty_delivered' => 20]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Settlement qty mismatch for delivery #' . $delivery->id . '/');

        Settlement::factory()->create([
            'delivery_id' => $delivery->id,
            'qty_sold' => 10,
            'qty_returned_fresh' => 2,
            'qty_returned_expired' => 3,
        ]);
    }

    public function test_settlement_all_zero_returns_passes(): void
    {
        $delivery = Delivery::factory()->create(['qty_delivered' => 15]);

        Settlement::factory()->create([
            'delivery_id' => $delivery->id,
            'qty_sold' => 15,
            'qty_returned_fresh' => 0,
            'qty_returned_expired' => 0,
            'amount_due' => 180000,
            'amount_paid' => 180000,
        ]);

        $this->assertDatabaseCount('settlements', 1);
    }

    public function test_settlement_full_payment_auto_sets_status_paid(): void
    {
        $delivery = Delivery::factory()->create([
            'qty_delivered' => 10,
            'unit_price' => 12000,
        ]);

        $settlement = Settlement::factory()->create([
            'delivery_id' => $delivery->id,
            'qty_sold' => 10,
            'qty_returned_fresh' => 0,
            'qty_returned_expired' => 0,
            'amount_due' => 120000,
            'amount_paid' => 120000,
            'status' => 'pending',
            'paid_at' => null,
        ]);

        $settlement->refresh();

        $this->assertSame('paid', $settlement->status);
        $this->assertNotNull($settlement->paid_at);
    }

    public function test_settlement_partial_payment_stays_pending(): void
    {
        $delivery = Delivery::factory()->create([
            'qty_delivered' => 10,
            'unit_price' => 12000,
        ]);

        $settlement = Settlement::factory()->create([
            'delivery_id' => $delivery->id,
            'qty_sold' => 10,
            'qty_returned_fresh' => 0,
            'qty_returned_expired' => 0,
            'amount_due' => 120000,
            'amount_paid' => 50000,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $settlement->refresh();

        $this->assertSame('pending', $settlement->status);
        $this->assertNull($settlement->paid_at);
    }

    public function test_settlement_overpayment_marked_paid(): void
    {
        $delivery = Delivery::factory()->create([
            'qty_delivered' => 10,
            'unit_price' => 12000,
        ]);

        $settlement = Settlement::factory()->create([
            'delivery_id' => $delivery->id,
            'qty_sold' => 10,
            'qty_returned_fresh' => 0,
            'qty_returned_expired' => 0,
            'amount_due' => 120000,
            'amount_paid' => 150000,
            'status' => 'pending',
            'paid_at' => null,
        ]);

        $settlement->refresh();

        $this->assertSame('paid', $settlement->status);
        $this->assertNotNull($settlement->paid_at);
    }
}
