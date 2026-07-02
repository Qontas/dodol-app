<?php

namespace Tests\Feature\MultiTenant;

use App\Filament\Resources\ClusterResource\Pages\ListClusters;
use App\Filament\Resources\KioskResource\Pages\CreateKiosk;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\Cluster;
use App\Models\User;
use Filament\Facades\Filament;
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

        // ClusterResource kini hidup di owner panel; set konteks panel agar URL
        // tabel (edit/index) ter-resolve ke route owner, bukan admin yang sudah dibersihkan.
        Filament::setCurrentPanel(Filament::getPanel('owner'));
        Livewire::actingAs($ownerA);
        Livewire::test(ListClusters::class)
            ->assertCanSeeTableRecords(Cluster::where('owner_id', $ownerA->id)->get())
            ->assertCanNotSeeTableRecords(Cluster::where('owner_id', $ownerB->id)->get());
    }

    public function test_owner_kiosk_form_cluster_dropdown_only_shows_own_clusters(): void
    {
        // 🔒 Regresi: dropdown "Area" di form Buat Kios (owner panel) HARUS cuma nampilin
        // cluster milik owner yang login — bukan cluster owner lain (bug "kios tidak muncul
        // di list" + bocor multi-tenant).
        $ownerA = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $ownerB = User::factory()->create(['role' => 'owner', 'is_active' => true]);

        $this->actingAs($ownerA);
        $clusterA = Cluster::create(['name' => 'Area Milik A', 'is_active' => true]);
        $this->actingAs($ownerB);
        $clusterB = Cluster::create(['name' => 'Area Milik B', 'is_active' => true]);

        Filament::setCurrentPanel(Filament::getPanel('owner'));
        Livewire::actingAs($ownerA);
        $options = Livewire::test(CreateKiosk::class)
            ->instance()
            ->getForm('form')
            ->getComponent('data.cluster_id')
            ->getOptions();

        $this->assertArrayHasKey($clusterA->id, $options, 'Owner A harus lihat area miliknya');
        $this->assertArrayNotHasKey($clusterB->id, $options, 'Owner A TIDAK boleh lihat area owner B');
    }

    public function test_super_admin_sees_all_clusters_in_filament(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);

        $this->actingAs($owner);
        Cluster::create(['name' => 'Milik Owner']);

        Filament::setCurrentPanel(Filament::getPanel('owner'));
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
