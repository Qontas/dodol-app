<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'name' => 'Mika 15 biji',
            'units_per_pack' => 15,
            'sale_price_per_pack' => 12000,
            'sku' => 'TEST-' . fake()->unique()->numerify('SKU####'),
            'is_active' => true,
        ];
    }
}
