<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Endpoint /csrf-token dipakai pwa-token-refresh saat app resume agar token form
 * (termasuk logout) selalu sinkron dengan sesi (akar fix 419). Juga mengembalikan
 * uid untuk deteksi pergantian identitas (multi-tenant).
 */
class CsrfTokenRefreshTest extends TestCase
{
    use RefreshDatabase;

    public function test_csrf_token_endpoint_requires_auth(): void
    {
        $this->get('/csrf-token')->assertRedirect(route('login'));
    }

    public function test_csrf_token_endpoint_returns_token_and_uid_for_authenticated_user(): void
    {
        $user = User::factory()->create(['role' => 'operator', 'is_active' => true]);

        $res = $this->actingAs($user)->getJson('/csrf-token');

        $res->assertOk()
            ->assertJsonStructure(['token', 'uid']);
        $this->assertEquals($user->id, $res->json('uid'));
        $this->assertNotEmpty($res->json('token'));
        // Tak boleh di-cache (token usang = 419).
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
    }

    /**
     * Logout route tetap melakukan logout + redirect ke login (kebal CSRF: rute
     * 'logout' dikecualikan dari verifikasi CSRF di bootstrap/app.php).
     */
    public function test_logout_route_logs_out_and_redirects_to_login(): void
    {
        $user = User::factory()->create(['role' => 'operator', 'is_active' => true]);

        $this->actingAs($user)
            ->post('/logout')
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }
}
