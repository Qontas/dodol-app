<?php

namespace Database\Factories;

use App\Models\Delivery;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Settlement>
 */
class SettlementFactory extends Factory
{
    public function definition(): array
    {
        // Default: delivery 1 mika = 15 biji, semua terjual (sum biji === qty_delivered*15)
        return [
            'delivery_id' => Delivery::factory()->state(['qty_delivered' => 1]),
            'visit_date' => now()->toDateString(),
            'qty_sold' => 15,
            'qty_returned_fresh' => 0,
            'qty_returned_expired' => 0,
            'amount_due' => 60000,
            'amount_paid' => 60000,
            'paid_at' => now(),
            'status' => 'paid',
        ];
    }
}
