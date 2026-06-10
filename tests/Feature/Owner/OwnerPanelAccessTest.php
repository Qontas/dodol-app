<?php

namespace Tests\Feature\Owner;

use App\Models\Cluster;
use App\Models\Kiosk;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OwnerPanelAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_access_owner_panel_dashboard_and_resources(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);

        $this->actingAs($owner)->get('/owner-panel')->assertOk();
        $this->actingAs($owner)->get('/owner-panel/kiosks')->assertOk();
        $this->actingAs($owner)->get('/owner-panel/clusters')->assertOk();
        $this->actingAs($owner)->get('/owner-panel/suppliers')->assertOk();
        $this->actingAs($owner)->get('/owner-panel/operators')->assertOk();
        $this->actingAs($owner)->get('/owner-panel/procurement-batches')->assertOk();
    }

    public function test_operator_cannot_access_owner_panel(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $operator = User::factory()->create([
            'role' => 'operator',
            'is_active' => true,
            'owner_id' => $owner->id,
        ]);

        $this->actingAs($operator)->get('/owner-panel')->assertForbidden();
    }

    public function test_owner_panel_kiosk_list_is_scoped_to_owner(): void
    {
        $ownerA = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $ownerB = User::factory()->create(['role' => 'owner', 'is_active' => true]);

        $clusterA = Cluster::create(['name' => 'Area A', 'owner_id' => $ownerA->id]);
        $clusterB = Cluster::create(['name' => 'Area B', 'owner_id' => $ownerB->id]);

        $kioskA = Kiosk::create(['name' => 'Kios Punya A', 'cluster_id' => $clusterA->id, 'is_active' => true]);
        $kioskB = Kiosk::create(['name' => 'Kios Punya B', 'cluster_id' => $clusterB->id, 'is_active' => true]);

        $this->actingAs($ownerA)
            ->get('/owner-panel/kiosks')
            ->assertOk()
            ->assertSee('Kios Punya A')
            ->assertDontSee('Kios Punya B');
    }
}
