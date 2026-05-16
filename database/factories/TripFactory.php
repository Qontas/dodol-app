<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Trip>
 */
class TripFactory extends Factory
{
    public function definition(): array
    {
        return [
            'trip_date' => fake()->unique()->dateTimeBetween('-90 days', '-1 day')->format('Y-m-d'),
            'trip_number_of_day' => 1,
            'operator_id' => User::factory(),
            'qty_carried_total' => 60,
        ];
    }
}
