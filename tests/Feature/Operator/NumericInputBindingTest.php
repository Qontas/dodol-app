<?php

namespace Tests\Feature\Operator;

use App\Livewire\Operator\ActiveTrip;
use App\Models\Cluster;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Server-side complements to cash-input-binding.cjs (DOM typing + real clicks).
 * These tests alone do NOT prove the front-end binding works.
 */
class NumericInputBindingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $owner = User::factory()->create(['role' => 'owner']);
        $operator = User::factory()->create(['role' => 'operator', 'owner_id' => $owner->id]);
        $cluster = Cluster::factory()->create(['owner_id' => $owner->id]);
        $product = Product::factory()->create(['owner_id' => $owner->id]);
        ProductVariant::factory()->create([
            'product_id' => $product->id, 'is_active' => true,
            'units_per_pack' => 20, 'sale_price_per_pack' => 16000,
        ]);
        Trip::factory()->create([
            'owner_id' => $owner->id, 'operator_id' => $operator->id,
            'starting_cluster_id' => $cluster->id, 'trip_date' => today(),
            'started_at' => now(), 'qty_carried_total' => 100,
        ]);
        $this->actingAs($operator);
    }

    public function test_walk_in_can_be_cleared_then_retyped_and_saved_at_variant_price(): void
    {
        Livewire::test(ActiveTrip::class)
            ->call('openWalkInModal')
            ->set('walkInMika', '')
            ->assertSet('walkInMika', null)
            ->set('walkInMika', '20')
            ->assertSee('20 mika × 20 biji × Rp 800')
            ->assertSee('Rp 320.000')
            ->call('saveWalkInCash')->assertHasNoErrors()
            ->assertSet('isWalkInModalOpen', false);

        $this->assertDatabaseHas('settlements', ['qty_sold' => 400, 'amount_paid' => 320000]);
    }

    public function test_empty_walk_in_and_stock_are_validation_errors_not_uninitialized_properties(): void
    {
        Livewire::test(ActiveTrip::class)
            ->call('openWalkInModal')->set('walkInMika', '')
            ->call('saveWalkInCash')->assertHasErrors(['walkInMika']);
        Livewire::test(ActiveTrip::class)
            ->call('openAddStockModal')->set('tambahStokQty', '')
            ->call('confirmAddStock')->assertHasErrors(['tambahStokQty']);
    }

    public function test_stock_can_be_cleared_then_retyped_and_saved(): void
    {
        Livewire::test(ActiveTrip::class)
            ->call('openAddStockModal')->set('tambahStokQty', '')
            ->assertSet('tambahStokQty', null)
            ->set('tambahStokQty', '20')
            ->assertSee('Tambah 20 mika ke stok motor?')
            ->call('confirmAddStock')->assertHasNoErrors()
            ->assertSet('isAddStockModalOpen', false);
        $this->assertDatabaseHas('trip_stock_additions', ['qty' => 20]);
    }

    public function test_shared_drop_steppers_accept_empty_and_string_input(): void
    {
        Livewire::test(ActiveTrip::class)
            ->set('dropBaru', '')->call('incrementDrop')->assertSet('dropBaru', 1)
            ->set('dropBaru', '')->call('decrementDrop')->assertSet('dropBaru', 0)
            ->set('dropBaru', '20')->call('incrementDrop')->assertSet('dropBaru', 21)
            ->call('decrementDrop')->assertSet('dropBaru', 20);
    }
}
