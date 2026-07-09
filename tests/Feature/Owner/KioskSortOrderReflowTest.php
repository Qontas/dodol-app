<?php

namespace Tests\Feature\Owner;

use App\Models\Cluster;
use App\Models\Kiosk;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bukti fix "TextInputColumn sort_order boleh duplikat" — Kiosk::reorderWithinCluster()
 * implementasi insert-within-list (ala Notion/Trello): isi angka yang sudah dipakai
 * kios lain di cluster yang SAMA otomatis menggeser kios lain (bukan tolak, bukan
 * duplikat), selalu gapless, dan tak pernah menyentuh cluster/tenant lain.
 */
class KioskSortOrderReflowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, int|null>  $namesAndOrders
     * @return array{0: Cluster, 1: array<string, Kiosk>}
     */
    private function makeClusterWithKiosks(User $owner, array $namesAndOrders): array
    {
        $this->actingAs($owner);
        $cluster = Cluster::factory()->create();

        $kiosks = [];
        foreach ($namesAndOrders as $name => $sortOrder) {
            $kiosks[$name] = Kiosk::factory()->create([
                'name' => $name,
                'cluster_id' => $cluster->id,
                'sort_order' => $sortOrder,
            ]);
        }

        return [$cluster, $kiosks];
    }

    public function test_move_down_shifts_only_the_range_between_old_and_new_position(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        [$cluster, $k] = $this->makeClusterWithKiosks($owner, [
            'A' => 1, 'B' => 2, 'C' => 3, 'D' => 4, 'E' => 5,
        ]);

        // Pindahkan A (posisi 1) ke posisi 3 — menabrak posisi C yang sudah 3.
        $result = Kiosk::reorderWithinCluster($k['A'], 3);

        $this->assertSame(3, $result);
        $this->assertSame(3, $k['A']->refresh()->sort_order);
        $this->assertSame(1, $k['B']->refresh()->sort_order); // mundur 2->1
        $this->assertSame(2, $k['C']->refresh()->sort_order); // mundur 3->2
        $this->assertSame(4, $k['D']->refresh()->sort_order); // di luar rentang, tak berubah
        $this->assertSame(5, $k['E']->refresh()->sort_order); // di luar rentang, tak berubah

        $orders = Kiosk::where('cluster_id', $cluster->id)->orderBy('sort_order')->pluck('sort_order')->all();
        $this->assertSame([1, 2, 3, 4, 5], $orders, 'Tak ada duplikat, tak ada lubang.');
    }

    public function test_move_up_shifts_only_the_range_between_new_and_old_position(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        [$cluster, $k] = $this->makeClusterWithKiosks($owner, [
            'A' => 1, 'B' => 2, 'C' => 3, 'D' => 4, 'E' => 5,
        ]);

        // Pindahkan E (posisi 5) ke posisi 2 — menabrak posisi B.
        $result = Kiosk::reorderWithinCluster($k['E'], 2);

        $this->assertSame(2, $result);
        $this->assertSame(2, $k['E']->refresh()->sort_order);
        $this->assertSame(1, $k['A']->refresh()->sort_order); // di luar rentang, tak berubah
        $this->assertSame(3, $k['B']->refresh()->sort_order); // maju 2->3
        $this->assertSame(4, $k['C']->refresh()->sort_order); // maju 3->4
        $this->assertSame(5, $k['D']->refresh()->sort_order); // maju 4->5

        $orders = Kiosk::where('cluster_id', $cluster->id)->orderBy('sort_order')->pluck('sort_order')->all();
        $this->assertSame([1, 2, 3, 4, 5], $orders, 'Tak ada duplikat, tak ada lubang.');
    }

    public function test_inserting_from_null_pushes_existing_kiosks_down(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        [$cluster, $k] = $this->makeClusterWithKiosks($owner, ['A' => 1, 'B' => 2, 'C' => 3]);
        $new = Kiosk::factory()->create(['name' => 'NEW', 'cluster_id' => $cluster->id, 'sort_order' => null]);

        $result = Kiosk::reorderWithinCluster($new, 2);

        $this->assertSame(2, $result);
        $this->assertSame(1, $k['A']->refresh()->sort_order);
        $this->assertSame(2, $new->refresh()->sort_order);
        $this->assertSame(3, $k['B']->refresh()->sort_order);
        $this->assertSame(4, $k['C']->refresh()->sort_order);
    }

    public function test_setting_to_null_removes_from_order_without_touching_others(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        [, $k] = $this->makeClusterWithKiosks($owner, ['A' => 1, 'B' => 2, 'C' => 3]);

        $result = Kiosk::reorderWithinCluster($k['B'], null);

        $this->assertNull($result);
        $this->assertNull($k['B']->refresh()->sort_order);
        $this->assertSame(1, $k['A']->refresh()->sort_order, 'Melepas satu kios tak mengompres sisanya.');
        $this->assertSame(3, $k['C']->refresh()->sort_order);
    }

    public function test_target_beyond_range_is_clamped_to_end(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        [$cluster, $k] = $this->makeClusterWithKiosks($owner, ['A' => 1, 'B' => 2, 'C' => 3]);

        $result = Kiosk::reorderWithinCluster($k['A'], 999);

        $this->assertSame(3, $result, 'Target di luar rentang harus di-clamp ke akhir (maxOther+1).');
        $orders = Kiosk::where('cluster_id', $cluster->id)->orderBy('sort_order')->pluck('sort_order')->all();
        $this->assertSame([1, 2, 3], $orders);
    }

    public function test_target_below_one_is_clamped_to_one(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        [$cluster, $k] = $this->makeClusterWithKiosks($owner, ['A' => 1, 'B' => 2, 'C' => 3]);

        $result = Kiosk::reorderWithinCluster($k['C'], -5);

        $this->assertSame(1, $result);
        $orders = Kiosk::where('cluster_id', $cluster->id)->orderBy('sort_order')->pluck('sort_order')->all();
        $this->assertSame([1, 2, 3], $orders);
    }

    public function test_moving_to_same_position_is_a_no_op(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        [, $k] = $this->makeClusterWithKiosks($owner, ['A' => 1, 'B' => 2, 'C' => 3]);

        $result = Kiosk::reorderWithinCluster($k['B'], 2);

        $this->assertSame(2, $result);
        $this->assertSame(1, $k['A']->refresh()->sort_order);
        $this->assertSame(3, $k['C']->refresh()->sort_order);
    }

    public function test_reflow_does_not_touch_a_different_cluster(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $this->actingAs($owner);

        $clusterA = Cluster::factory()->create();
        $clusterB = Cluster::factory()->create();

        $a1 = Kiosk::factory()->create(['name' => 'A1', 'cluster_id' => $clusterA->id, 'sort_order' => 1]);
        Kiosk::factory()->create(['name' => 'A2', 'cluster_id' => $clusterA->id, 'sort_order' => 2]);
        $b1 = Kiosk::factory()->create(['name' => 'B1', 'cluster_id' => $clusterB->id, 'sort_order' => 1]);
        $b2 = Kiosk::factory()->create(['name' => 'B2', 'cluster_id' => $clusterB->id, 'sort_order' => 2]);

        Kiosk::reorderWithinCluster($a1, 2); // tabrak A2 dalam cluster A

        $this->assertSame(1, $b1->refresh()->sort_order, 'Cluster B (area lain) tak boleh ikut tergeser.');
        $this->assertSame(2, $b2->refresh()->sort_order, 'Cluster B (area lain) tak boleh ikut tergeser.');
    }

    public function test_reflow_does_not_touch_a_different_owner_tenant(): void
    {
        $ownerA = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $ownerB = User::factory()->create(['role' => 'owner', 'is_active' => true]);

        $this->actingAs($ownerA);
        $clusterA = Cluster::factory()->create();
        $ka1 = Kiosk::factory()->create(['name' => 'KA1', 'cluster_id' => $clusterA->id, 'sort_order' => 1]);
        Kiosk::factory()->create(['name' => 'KA2', 'cluster_id' => $clusterA->id, 'sort_order' => 2]);

        $this->actingAs($ownerB);
        $clusterB = Cluster::factory()->create();
        $kb1 = Kiosk::factory()->create(['name' => 'KB1', 'cluster_id' => $clusterB->id, 'sort_order' => 1]);
        $kb2 = Kiosk::factory()->create(['name' => 'KB2', 'cluster_id' => $clusterB->id, 'sort_order' => 2]);

        $this->actingAs($ownerA);
        Kiosk::reorderWithinCluster($ka1->refresh(), 2);

        $this->assertSame(1, $kb1->refresh()->sort_order, 'Owner B (tenant lain) tak boleh ikut tergeser.');
        $this->assertSame(2, $kb2->refresh()->sort_order, 'Owner B (tenant lain) tak boleh ikut tergeser.');
    }
}
