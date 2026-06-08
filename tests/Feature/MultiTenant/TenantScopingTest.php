<?php

namespace Tests\Feature\MultiTenant;

use App\Filament\Resources\ClusterResource\Pages\ListClusters;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\Cluster;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TenantScopingTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_model_auto_assigns_owner_id_for_owner(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);

        $this->actingAs($owner);
        $cluster = Cluster::create(['name' => 'Cluster Owner A']);

        $this->assertSame($owner->id, $cluster->owner_id);
    }

    public function test_creating_model_auto_assigns_operator_owner_id(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $operator = User::factory()->create([
            'role' => 'operator',
            'is_active' => true,
            'owner_id' => $owner->id,
        ]);

        $this->actingAs($operator);
        $cluster = Cluster::create(['name' => 'Cluster via Operator']);

        $this->assertSame($owner->id, $cluster->owner_id);
    }

    public function test_owner_only_sees_own_clusters_in_filament(): void
    {
        $ownerA = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $ownerB = User::factory()->create(['role' => 'owner', 'is_active' => true]);

        $this->actingAs($ownerA);
        Cluster::create(['name' => 'Milik A']);

        $this->actingAs($ownerB);
        Cluster::create(['name' => 'Milik B']);

        Livewire::actingAs($ownerA);
        Livewire::test(ListClusters::class)
            ->assertCanSeeTableRecords(Cluster::where('owner_id', $ownerA->id)->get())
            ->assertCanNotSeeTableRecords(Cluster::where('owner_id', $ownerB->id)->get());
    }

    public function test_super_admin_sees_all_clusters_in_filament(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);

        $this->actingAs($owner);
        Cluster::create(['name' => 'Milik Owner']);

        Livewire::actingAs($admin);
        Livewire::test(ListClusters::class)
            ->assertCanSeeTableRecords(Cluster::all());
    }

    public function test_super_admin_can_access_admin_panel(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($admin)->get('/admin')->assertOk();
    }

    public function test_super_admin_dashboard_redirects_to_admin(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($admin)->get('/dashboard')->assertRedirect('/admin');
    }

    public function test_owner_only_sees_own_operators_in_anggota(): void
    {
        $ownerA = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $ownerB = User::factory()->create(['role' => 'owner', 'is_active' => true]);

        $operatorA = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $ownerA->id]);
        $operatorB = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $ownerB->id]);

        Livewire::actingAs($ownerA);
        Livewire::test(ListUsers::class)
            ->assertCanSeeTableRecords([$operatorA])
            ->assertCanNotSeeTableRecords([$operatorB, $ownerA, $ownerB]);
    }

    public function test_role_helper_methods(): void
    {
        $admin = User::factory()->make(['role' => 'super_admin']);
        $owner = User::factory()->make(['role' => 'owner']);
        $operator = User::factory()->make(['role' => 'operator']);

        $this->assertTrue($admin->isSuperAdmin());
        $this->assertTrue($owner->isOwner());
        $this->assertTrue($operator->isOperator());
        $this->assertFalse($owner->isSuperAdmin());
    }
}
