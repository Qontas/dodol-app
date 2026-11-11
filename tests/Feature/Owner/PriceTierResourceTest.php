<?php

namespace Tests\Feature\Owner;

use App\Filament\Resources\PriceTierResource;
use App\Filament\Resources\PriceTierResource\Pages\ManagePriceTiers;
use App\Models\PriceTier;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PriceTierResourceTest extends TestCase
{
    use RefreshDatabase;

    private function variant(User $owner): ProductVariant
    {
        return ProductVariant::factory()->create([
            'product_id' => Product::factory()->create(['owner_id' => $owner->id])->id,
            'units_per_pack' => 20,
        ]);
    }

    public function test_owner_can_create_edit_and_see_readable_prices(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $variant = $this->variant($owner);
        $this->actingAs($owner);
        Livewire::test(ManagePriceTiers::class)->callAction('create', [
            'product_variant_id' => $variant->id, 'name' => 'Premium',
            'sale_price_per_pack' => 16000, 'is_default' => false, 'is_active' => true,
        ])->assertHasNoErrors();
        $tier = PriceTier::where('name', 'Premium')->firstOrFail();
        $this->assertSame($owner->id, $tier->owner_id);
        Livewire::test(ManagePriceTiers::class)->assertSee('Rp 16.000/mika · Rp 800/biji')
            ->callTableAction('edit', $tier, ['name' => 'Harga Khusus', 'sale_price_per_pack' => 18000])
            ->assertHasNoErrors();
        $this->assertSame('18000.00', $tier->fresh()->sale_price_per_pack);
    }

    public function test_owner_only_sees_own_tiers_and_variants_and_cannot_edit_other_tenant(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $mine = $this->variant($owner);
        $foreign = $this->variant(User::factory()->create(['role' => 'owner']));
        $tier = $foreign->defaultPriceTier;
        $this->actingAs($owner);
        $this->assertArrayHasKey($mine->id, PriceTierResource::variantOptions());
        $this->assertArrayNotHasKey($foreign->id, PriceTierResource::variantOptions());
        $this->assertFalse(PriceTierResource::getEloquentQuery()->whereKey($tier->id)->exists());
        Livewire::test(ManagePriceTiers::class)->mountTableAction('edit', $tier)->assertTableActionNotMounted('edit');
        $this->assertDatabaseHas('price_tiers', ['id' => $tier->id, 'name' => 'Standar']);
    }

    public function test_forged_foreign_variant_is_rejected_on_create(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $foreign = $this->variant(User::factory()->create(['role' => 'owner']));
        $this->actingAs($owner);
        Livewire::test(ManagePriceTiers::class)->callAction('create', [
            'product_variant_id' => $foreign->id, 'name' => 'Forged',
            'sale_price_per_pack' => 16000, 'is_default' => false, 'is_active' => true,
        ])->assertHasErrors();
        $this->assertDatabaseMissing('price_tiers', ['name' => 'Forged']);
    }

    public function test_owner_can_switch_default_but_not_delete_default(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $variant = $this->variant($owner);
        $this->actingAs($owner);
        Livewire::test(ManagePriceTiers::class)->callAction('create', [
            'product_variant_id' => $variant->id, 'name' => 'Default Baru',
            'sale_price_per_pack' => 16000, 'is_default' => true, 'is_active' => true,
        ])->assertHasNoErrors();
        $tier = PriceTier::where('name', 'Default Baru')->firstOrFail();
        $this->assertTrue($tier->is_default);
        $this->assertSame(1, $variant->priceTiers()->where('is_default', true)->count());
        $this->assertFalse(PriceTierResource::canDelete($tier));
        Livewire::test(ManagePriceTiers::class)->assertTableActionHidden('delete', $tier);
    }

    public function test_resource_is_owner_only(): void
    {
        foreach (['operator', 'super_admin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->assertFalse(PriceTierResource::canAccess());
            Livewire::test(ManagePriceTiers::class)->assertForbidden();
        }
    }
}
