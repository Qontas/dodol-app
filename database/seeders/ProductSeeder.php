<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        // Multi-tenant: produk default milik owner utama.
        $ownerId = User::where('role', 'owner')->orderBy('id')->value('id');

        $product = Product::updateOrCreate(
            ['name' => 'Dodol Coklat Susu'],
            [
                'owner_id' => $ownerId,
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
