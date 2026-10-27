<?php

namespace Database\Factories;

use App\Models\Trip;
use App\Models\TripStockAddition;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TripStockAddition>
 */
class TripStockAdditionFactory extends Factory
{
    protected $model = TripStockAddition::class;

    public function definition(): array
    {
        return [
            'trip_id' => Trip::factory(),
            'qty' => 10,
            'added_by' => User::factory(),
        ];
    }
}
