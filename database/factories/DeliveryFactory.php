<?php

namespace Database\Factories;

use App\Models\Kiosk;
use App\Models\ProcurementBatch;
use App\Models\ProductVariant;
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
            'origin_settlement_id' => null,
            'delivery_type' => 'consignment',
            'qty_delivered' => 5,
            'unit_price' => 12000,
            'cost_snapshot' => 10000,
        ];
    }
}
