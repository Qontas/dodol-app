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

        // Operator dummy untuk testing manual selama development.
        // Production deployment: owner remove user ini lewat UI dan bikin operator real.
        User::updateOrCreate(
            ['email' => 'operator@cemilanqontas.id'],
            [
                'name' => 'Test Operator',
                'password' => bcrypt('password'),
                'role' => 'operator',
                'commission_rate' => 0.2000,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );
    }
}
