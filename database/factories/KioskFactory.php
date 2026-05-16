<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Kiosk>
 */
class KioskFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Kios ' . fake()->lastName(),
            'owner_name' => fake()->name(),
            'phone' => fake()->numerify('08##########'),
            'cluster_id' => null,
            'target_visit_interval_days' => 14,
            'warning_visit_interval_days' => 10,
            'is_active' => true,
        ];
    }
}
