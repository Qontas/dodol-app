<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Super admin (Ismi) — pantau semua owner. owner_id = null.
        User::updateOrCreate(
            ['email' => 'admin@cemilanqontas.id'],
            [
                'name' => 'Super Admin',
                'password' => bcrypt('password'),
                'role' => 'super_admin',
                'owner_id' => null,
                'commission_rate' => null,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        // Owner bisnis. owner_id = null (dia owner, bukan operator).
        $owner = User::updateOrCreate(
            ['email' => 'owner@cemilanqontas.id'],
            [
                'name' => 'Ismi Qontas Lubis',
                'password' => bcrypt('password'),
                'role' => 'owner',
                'owner_id' => null,
                'commission_rate' => null,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        // Operator dummy untuk testing manual selama development.
        // Production deployment: owner remove user ini lewat UI dan bikin operator real.
        // owner_id menunjuk ke owner-nya.
        User::updateOrCreate(
            ['email' => 'operator@cemilanqontas.id'],
            [
                'name' => 'Test Operator',
                'password' => bcrypt('password'),
                'role' => 'operator',
                'owner_id' => $owner->id,
                'commission_rate' => 0.2000,
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );
    }
}
