<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'owner@cemilanqontas.id'],
            [
                'name' => 'Ismi Qontas Lubis',
                'password' => bcrypt('password'),
                'role' => 'owner',
                'commission_rate' => null,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );
    }
}
