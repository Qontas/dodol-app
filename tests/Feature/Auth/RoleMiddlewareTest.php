<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_access_owner_dashboard(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);

        $response = $this->actingAs($owner)->get('/owner/dashboard');

        $response->assertOk();
    }

    public function test_operator_cannot_access_owner_dashboard(): void
    {
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true]);

        $response = $this->actingAs($operator)->get('/owner/dashboard');

        $response->assertForbidden();
    }

    public function test_operator_can_access_operator_dashboard(): void
    {
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true]);

        $response = $this->actingAs($operator)->get('/operator/dashboard');

        $response->assertOk();
    }

    public function test_owner_cannot_access_operator_dashboard(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);

        $response = $this->actingAs($owner)->get('/operator/dashboard');

        $response->assertForbidden();
    }

    public function test_dashboard_redirects_based_on_role(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $this->actingAs($owner)
            ->get('/dashboard')
            ->assertRedirect(route('owner.dashboard'));

        auth()->logout();

        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true]);
        $this->actingAs($operator)
            ->get('/dashboard')
            ->assertRedirect(route('operator.dashboard'));
    }

    public function test_inactive_user_cannot_access_dashboard(): void
    {
        $inactive = User::factory()->create(['role' => 'owner', 'is_active' => false]);

        $response = $this->actingAs($inactive)->get('/owner/dashboard');

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_unauthenticated_redirected_to_login(): void
    {
        $this->get('/owner/dashboard')->assertRedirect(route('login'));
        $this->get('/operator/dashboard')->assertRedirect(route('login'));
    }
}
