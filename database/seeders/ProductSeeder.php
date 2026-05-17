<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $product = Product::updateOrCreate(
            ['name' => 'Dodol Coklat Susu'],
            [
                'category' => 'dodol',
                'is_active' => true,
                'notes' => 'Produk default Cemilan Qontas',
            ]
        );

        ProductVariant::updateOrCreate(
            ['sku' => 'DDL-CSU-MK15'],
            [
                'product_id' => $product->id,
                'name' => 'Mika 15 biji',
                'units_per_pack' => 15,
                'sale_price_per_pack' => 12000.00,
                'is_active' => true,
            ]
        );
    }
}
