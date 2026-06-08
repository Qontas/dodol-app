<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Models\Kiosk;
use App\Models\Trip;
use App\Models\Cluster;
use App\Models\ProductVariant;
use App\Models\Delivery;
use App\Models\Settlement;
use App\Models\Commission;
use App\Filament\Widgets\SuperAdminStatsOverview;
use App\Filament\Widgets\OwnerPerformanceTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SuperAdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_dashboard_is_accessible_and_contains_widgets()
    {
        $superAdmin = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $owner = User::factory()->create([
            'role' => 'owner',
            'is_active' => true,
        ]);

        // Access dashboard as super admin
        $response = $this->actingAs($superAdmin)->get('/admin');
        $response->assertOk();

        // Test Livewire widgets render without errors
        Livewire::test(SuperAdminStatsOverview::class)
            ->assertOk()
            ->assertSee('Owner &amp; Operator Aktif')
            ->assertSee('Total Kios Terdaftar');

        Livewire::test(OwnerPerformanceTable::class)
            ->assertOk()
            ->assertSee('Leaderboard Owner Hari Ini');
    }

    public function test_widgets_are_hidden_from_non_super_admins()
    {
        $owner = User::factory()->create([
            'role' => 'owner',
            'is_active' => true,
        ]);

        // As owner, accessing /admin is OK, but the widgets should not be visible
        $this->actingAs($owner)
            ->get('/admin')
            ->assertOk();

        Livewire::test(SuperAdminStatsOverview::class)
            ->assertStatus(403);

        Livewire::test(OwnerPerformanceTable::class)
            ->assertStatus(403);
    }
}
