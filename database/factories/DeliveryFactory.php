<?php

namespace Database\Factories;

use App\Models\Delivery;
use App\Models\Kiosk;
use App\Models\ProcurementBatch;
use App\Models\ProductVariant;
use App\Models\Settlement;
use App\Models\Trip;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Delivery>
 */
class DeliveryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'kiosk_id' => Kiosk::factory(),
            'trip_id' => Trip::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'procurement_batch_id' => ProcurementBatch::factory(),
            'source_type' => 'new_procurement',
            'delivery_type' => 'consignment',
            'qty_delivered' => 5,
            'unit_price' => 12000,
            'cost_snapshot' => 10000,
        ];
    }

    /**
     * State untuk fresh_return_redeploy. Otomatis attach 1 origin settlement
     * dengan biji_count = qty_delivered * 15 (memenuhi Rule B accounting).
     * Override $bijiCount untuk test skenario violation.
     */
    public function freshReturn(?int $settlementId = null, ?int $bijiCount = null): self
    {
        return $this->state([
            'source_type' => 'fresh_return_redeploy',
            'procurement_batch_id' => null,
        ])->afterCreating(function (Delivery $delivery) use ($settlementId, $bijiCount) {
            $settlementId = $settlementId ?? Settlement::factory()->create()->id;
            $biji = $bijiCount ?? ((int) $delivery->qty_delivered * 15);
            $delivery->originSettlements()->attach($settlementId, ['biji_count' => $biji]);
            $delivery->validateOrigins();
        });
    }
}
