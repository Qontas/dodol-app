<?php

namespace Tests\Feature\MultiTenant;

use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Livewire\Operator\ActiveTrip;
use App\Livewire\Operator\StartTrip;
use App\Models\Cluster;
use App\Models\Delivery;
use App\Models\Kiosk;
use App\Models\ProcurementBatch;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Residual keamanan minor dari audit Langkah 1/2 — scoping owner:
 *  B1 resolveActiveVariant (varian via product.owner_id)
 *  B2 StartTrip exists:clusters (owner-scoped)
 *  B3 UserResource EditUser re-force owner_id/role (parity CreateUser)
 *  B4 ProcurementBatch batch_number per-owner
 */
class ResidualScopingTest extends TestCase
{
    use RefreshDatabase;

    private function operatorWorld(string $tag): array
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $owner->id]);
        $cluster = Cluster::create(['name' => "Area $tag", 'owner_id' => $owner->id]);

        return compact('owner', 'operator', 'cluster');
    }

    private function activeTripFor(array $w): Trip
    {
        return Trip::factory()->create([
            'operator_id' => $w['operator']->id, 'owner_id' => $w['owner']->id,
            'started_at' => now(), 'ended_at' => null, 'trip_date' => today()->format('Y-m-d'),
            'starting_cluster_id' => $w['cluster']->id, 'qty_carried_total' => 50,
        ]);
    }

    // ============ B1: resolveActiveVariant ter-scope owner ============
    public function test_cash_sale_uses_only_own_owner_variant(): void
    {
        $a = $this->operatorWorld('A');
        $this->activeTripFor($a);
        $productA = Product::factory()->create(['owner_id' => $a['owner']->id]);
        $variantA = ProductVariant::factory()->create(['product_id' => $productA->id, 'is_active' => true, 'sale_price_per_pack' => 12000]);

        // Owner B punya varian aktif — TIDAK boleh kepakai operator A.
        $ownerB = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $productB = Product::factory()->create(['owner_id' => $ownerB->id]);
        ProductVariant::factory()->create(['product_id' => $productB->id, 'is_active' => true, 'sale_price_per_pack' => 9999]);

        $cashKiosk = Kiosk::factory()->create(['cluster_id' => $a['cluster']->id, 'is_cash_only' => true, 'default_qty_mika' => 5]);

        $this->actingAs($a['operator']);
        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $cashKiosk->id)
            ->set('dropBaru', 3)
            ->call('saveVisit')
            ->assertHasNoErrors();

        $delivery = Delivery::where('kiosk_id', $cashKiosk->id)->first();
        $this->assertNotNull($delivery);
        $this->assertSame($variantA->id, $delivery->product_variant_id, 'Cash sale harus pakai varian owner sendiri, bukan owner B.');
    }

    public function test_cash_sale_rejected_when_owner_has_no_variant_even_if_other_owner_has(): void
    {
        $a = $this->operatorWorld('A');
        $this->activeTripFor($a);

        // HANYA owner B yang punya varian aktif; owner A tidak punya.
        $ownerB = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $productB = Product::factory()->create(['owner_id' => $ownerB->id]);
        ProductVariant::factory()->create(['product_id' => $productB->id, 'is_active' => true]);

        $cashKiosk = Kiosk::factory()->create(['cluster_id' => $a['cluster']->id, 'is_cash_only' => true, 'default_qty_mika' => 5]);

        $this->actingAs($a['operator']);
        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $cashKiosk->id)
            ->set('dropBaru', 3)
            ->call('saveVisit')
            ->assertHasErrors('general');

        // Varian owner B TIDAK dipakai → tak ada delivery tercipta.
        $this->assertSame(0, Delivery::count());
    }

    // ============ B2: StartTrip exists:clusters owner-scoped ============
    public function test_operator_cannot_start_trip_with_other_owners_cluster(): void
    {
        $a = $this->operatorWorld('A');
        $clusterB = Cluster::create(['name' => 'Area B', 'owner_id' => User::factory()->create(['role' => 'owner'])->id]);

        $this->actingAs($a['operator']);
        Livewire::test(StartTrip::class)
            ->set('selectedClusterId', $clusterB->id)
            ->set('qtyCarried', 10)
            ->call('startTrip')
            ->assertHasErrors('selectedClusterId');

        $this->assertSame(0, Trip::where('operator_id', $a['operator']->id)->count());
    }

    public function test_operator_can_start_trip_with_own_cluster(): void
    {
        $a = $this->operatorWorld('A');

        $this->actingAs($a['operator']);
        Livewire::test(StartTrip::class)
            ->set('selectedClusterId', $a['cluster']->id)
            ->set('qtyCarried', 10)
            ->call('startTrip')
            ->assertHasNoErrors();

        $this->assertSame(1, Trip::where('operator_id', $a['operator']->id)->count());
    }

    // ============ B3: EditUser re-force owner_id/role ============
    public function test_edit_user_forces_operator_role_and_owner_for_owner(): void
    {
        $ownerA = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $ownerB = User::factory()->create(['role' => 'owner', 'is_active' => true]);

        $this->actingAs($ownerA);
        $method = new \ReflectionMethod(EditUser::class, 'mutateFormDataBeforeSave');
        $method->setAccessible(true);
        $out = $method->invoke(new EditUser, ['name' => 'X', 'role' => 'owner', 'owner_id' => $ownerB->id]);

        $this->assertSame('operator', $out['role'], 'Owner tak boleh eskalasi operatornya jadi owner.');
        $this->assertSame($ownerA->id, $out['owner_id'], 'Owner tak boleh pindahkan operator ke owner lain.');
    }

    public function test_edit_user_does_not_force_for_super_admin(): void
    {
        $super = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($super);
        $method = new \ReflectionMethod(EditUser::class, 'mutateFormDataBeforeSave');
        $method->setAccessible(true);
        $out = $method->invoke(new EditUser, ['name' => 'X', 'role' => 'owner', 'owner_id' => 123]);

        $this->assertSame('owner', $out['role']); // super admin tidak dipaksa
        $this->assertSame(123, $out['owner_id']);
    }

    // ============ B4: ProcurementBatch batch_number per-owner ============
    public function test_batch_number_counter_is_scoped_per_owner(): void
    {
        $ownerA = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $ownerB = User::factory()->create(['role' => 'owner', 'is_active' => true]);

        $a1 = ProcurementBatch::factory()->create(['owner_id' => $ownerA->id]);
        ProcurementBatch::factory()->create(['owner_id' => $ownerB->id]); // b1 di antara → tak boleh dihitung
        $a2 = ProcurementBatch::factory()->create(['owner_id' => $ownerA->id]);

        // Nomor per-owner: a1 = 001, a2 = 002 (TIDAK 003 walau ada batch owner B di tengah).
        $this->assertStringEndsWith('-001', $a1->fresh()->batch_number);
        $this->assertStringEndsWith('-002', $a2->fresh()->batch_number);
    }
}
