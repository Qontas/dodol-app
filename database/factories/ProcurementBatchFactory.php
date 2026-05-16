<?php

namespace Database\Factories;

use App\Models\ProductVariant;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProcurementBatch>
 */
class ProcurementBatchFactory extends Factory
{
    public function definition(): array
    {
        $purchaseDate = now()->subDays(2)->toDateString();

        return [
            'product_variant_id' => ProductVariant::factory(),
            'supplier_id' => Supplier::factory(),
            'purchase_date' => $purchaseDate,
            'qty_kg_raw' => 20.00,
            'qty_packs' => 72,
            'cost_raw_material' => 720000,
            'cost_packing' => 0,
            'cost_packaging' => 0,
            'cost_other' => 0,
            'expiry_date' => now()->subDays(2)->addDays(21)->toDateString(),
        ];
    }
}
