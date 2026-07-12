<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * KEAMANAN: self-register DITUTUP total. Dulu /register PUBLIK + kolom role DEFAULT
 * 'owner' → siapa pun di internet bisa membuat akun OWNER penuh. Test ini mengunci:
 *   1. Route 'register' tidak ada lagi (GET & POST → 404).
 *   2. Default kolom role = 'operator' (bukan 'owner') — pertahanan berlapis.
 */
class RegisterDisabledTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_route_does_not_exist(): void
    {
        $this->assertFalse(
            \Illuminate\Support\Facades\Route::has('register'),
            'Route register masih terdaftar — self-register harus ditutup total.'
        );
    }

    public function test_guest_cannot_access_register_page(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', [
            'name' => 'Penyusup',
            'email' => 'penyusup@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'penyusup@example.com']);
    }

    public function test_user_without_explicit_role_is_not_owner(): void
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'Tanpa Role',
            'email' => 'tanpa-role@example.com',
            'password' => bcrypt('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $role = DB::table('users')->where('id', $id)->value('role');

        $this->assertSame('operator', $role, 'Default role harus operator, TIDAK boleh owner.');
        $this->assertNotSame('owner', $role);
    }
}
