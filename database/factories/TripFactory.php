<?php

namespace Database\Factories;

use App\Models\Trip;
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

    /**
     * Di produksi owner_id trip selalu terisi otomatis (BelongsToOwner saat operator
     * membuat trip). Factory dipakai tanpa auth → hook itu tak jalan, jadi backfill
     * di sini dari owner operator agar data test realistis & lolos global OwnerScope.
     * Tidak menimpa owner_id yang sudah diisi eksplisit oleh test.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Trip $trip) {
            if ($trip->owner_id === null && $trip->operator?->owner_id !== null) {
                $trip->owner_id = $trip->operator->owner_id;
                $trip->saveQuietly();
            }
        });
    }
}
