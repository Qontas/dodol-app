<?php

namespace Tests\Feature\Owner;

use App\Filament\Resources\KioskResource\Pages\CreateKiosk;
use App\Filament\Resources\KioskResource\Pages\EditKiosk;
use App\Livewire\Operator\ActiveTrip;
use App\Models\{Cluster, Delivery, Kiosk, PriceTier, Product, ProductVariant, Trip, User};
use App\Support\AppFeeCalculator;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class KioskFirstPlacementTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Cluster $cluster;
    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner', 'app_fee_per_pack' => 300, 'komisi_per_mika' => 1000]);
        $this->cluster = Cluster::factory()->create(['owner_id' => $this->owner->id]);
        $product = Product::factory()->create(['owner_id' => $this->owner->id]);
        $this->variant = ProductVariant::factory()->create(['product_id' => $product->id, 'units_per_pack' => 20, 'sale_price_per_pack' => 16000, 'cost_per_pack' => 9500]);
        Filament::setCurrentPanel(Filament::getPanel('owner'));
        $this->actingAs($this->owner);
    }

    private function form(array $extra = [])
    {
        return Livewire::test(CreateKiosk::class)->fillForm(array_merge([
            'name' => 'Kedai Tes', 'cluster_id' => $this->cluster->id,
            'jenis_kedai' => 'konsinyasi', 'default_qty_mika' => 4,
        ], $extra));
    }

    public function test_first_placement_is_required_and_migration_is_not_an_option(): void
    {
        $this->form()->call('create')->assertHasFormErrors(['first_placement' => 'required']);
        $this->assertDatabaseMissing('kiosks', ['name' => 'Kedai Tes']);
        $this->form(['first_placement' => 'migration'])->call('create')->assertHasFormErrors(['first_placement']);
        $this->assertDatabaseMissing('kiosks', ['name' => 'Kedai Tes']);
    }

    public function test_owner_placed_has_fee_but_no_commission_and_no_motor_drop(): void
    {
        $tier = PriceTier::create(['owner_id' => $this->owner->id, 'product_variant_id' => $this->variant->id,
            'name' => 'Khusus', 'sale_price_per_pack' => 14000, 'is_default' => false, 'is_active' => true]);
        $this->form(['first_placement' => 'owner_placed', 'price_tier_id' => $tier->id])
            ->call('create')->assertHasNoFormErrors();
        $kiosk = Kiosk::where('name', 'Kedai Tes')->firstOrFail();
        $delivery = Delivery::where('kiosk_id', $kiosk->id)->sole();
        $this->assertSame('owner_placed', $delivery->source_type);
        $this->assertSame(14000.0, (float) $delivery->unit_price);
        $this->assertSame(9500.0, (float) $delivery->cost_snapshot);
        $this->assertTrue($delivery->trip->isMigrationSentinel());
        $this->assertEquals(0, $delivery->trip->komisi_rian);
        $this->assertEquals(0, $delivery->trip->qty_carried_total);
        $summary = AppFeeCalculator::summaryForOwners()[$this->owner->id];
        $this->assertSame(4, $summary['mika_today']);
        $this->assertEquals(1200, $summary['fee_today']);
        $this->owner->update(['app_fee_per_pack' => 900]);
        $this->assertEquals(1200, AppFeeCalculator::summaryForOwners()[$this->owner->id]['fee_today']);
    }

    public function test_operator_placement_waits_for_mulai_titipan_with_prefilled_quota(): void
    {
        $this->form(['first_placement' => 'operator'])->call('create')->assertHasNoFormErrors();
        $kiosk = Kiosk::where('name', 'Kedai Tes')->firstOrFail();
        $this->assertSame(4, (int) $kiosk->default_qty_mika);
        $this->assertSame(0, Delivery::where('kiosk_id', $kiosk->id)->count());
        $operator = User::factory()->create(['role' => 'operator', 'owner_id' => $this->owner->id]);
        $trip = Trip::factory()->create(['owner_id' => $this->owner->id, 'operator_id' => $operator->id,
            'starting_cluster_id' => $this->cluster->id, 'trip_date' => today(), 'started_at' => now(),
            'ended_at' => null, 'qty_carried_total' => 20, 'app_fee_per_pack_snapshot' => 300,
            'komisi_per_mika_snapshot' => 1000]);
        $this->actingAs($operator);
        Livewire::test(ActiveTrip::class)->assertSee('Belum pernah dititip')
            ->call('openVisitModal', $kiosk->id)->call('chooseAction', 'mulai_titipan')
            ->assertSet('jatahMulai', 4)->set('dropBaru', 4)->call('saveVisit')->assertHasNoErrors();
        $delivery = Delivery::where('kiosk_id', $kiosk->id)->sole();
        $this->assertSame($trip->id, (int) $delivery->trip_id);
        $this->assertEquals(4000, $trip->fresh()->komisi_rian);
        $this->assertEquals(16, $trip->fresh()->getStokDiMotor());
        $this->assertEquals(1200, AppFeeCalculator::summaryForOwners()[$this->owner->id]['fee_today']);
    }

    public function test_owner_form_rejects_other_tenant_and_wrong_variant_tiers(): void
    {
        $other = User::factory()->create(['role' => 'owner']);
        $product = Product::factory()->create(['owner_id' => $other->id]);
        // Create foreign data without an authenticated tenant scope.
        auth()->logout();
        $foreignVariant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $foreignTier = PriceTier::where('product_variant_id', $foreignVariant->id)->firstOrFail();
        $this->actingAs($this->owner);
        $this->form(['first_placement' => 'operator', 'price_tier_id' => $foreignTier->id])
            ->call('create')->assertHasFormErrors(['price_tier_id']);
        $variant2 = ProductVariant::factory()->create(['product_id' => $this->variant->product_id]);
        $tier2 = PriceTier::where('product_variant_id', $variant2->id)->firstOrFail();
        $this->form(['first_placement' => 'operator', 'price_tier_id' => $tier2->id])
            ->call('create')->assertHasFormErrors(['price_tier_id']);
    }

    public function test_edit_keeps_existing_inactive_tier_without_repricing_history(): void
    {
        $tier = PriceTier::create(['product_variant_id' => $this->variant->id, 'name' => 'Lama',
            'sale_price_per_pack' => 14000, 'is_active' => true]);
        $this->form(['first_placement' => 'owner_placed', 'price_tier_id' => $tier->id])
            ->call('create')->assertHasNoFormErrors();
        $kiosk = Kiosk::where('name', 'Kedai Tes')->firstOrFail();
        $tier->update(['is_active' => false]);
        Livewire::test(EditKiosk::class, ['record' => $kiosk->id])
            ->fillForm(['name' => 'Kedai Diperbarui'])->call('save')->assertHasNoFormErrors();
        $this->assertSame($tier->id, (int) $kiosk->fresh()->price_tier_id);
        $this->assertEquals(14000, $kiosk->deliveries()->sole()->unit_price);
    }

    public function test_owner_can_change_kiosk_tier_without_repricing_existing_delivery(): void
    {
        $this->form(['first_placement' => 'owner_placed'])->call('create')->assertHasNoFormErrors();
        $kiosk = Kiosk::where('name', 'Kedai Tes')->firstOrFail();
        $tier = PriceTier::create(['owner_id' => $this->owner->id, 'product_variant_id' => $this->variant->id,
            'name' => 'Hemat', 'sale_price_per_pack' => 14000, 'is_default' => false, 'is_active' => true]);
        Livewire::test(EditKiosk::class, ['record' => $kiosk->id])->fillForm(['price_tier_id' => $tier->id])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame($tier->id, (int) $kiosk->fresh()->price_tier_id);
        $this->assertEquals(16000, Delivery::where('kiosk_id', $kiosk->id)->sole()->unit_price);
    }
}
