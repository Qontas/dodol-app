<?php

namespace Tests\Feature\Operator;

use App\Livewire\Operator\ActiveTrip;
use App\Livewire\Operator\CreateKiosk;
use App\Models\Cluster;
use App\Models\Delivery;
use App\Models\Kiosk;
use App\Models\KioskVisit;
use App\Models\PriceTier;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Settlement;
use App\Models\Trip;
use App\Models\User;
use App\Support\AppFeeCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class KioskPriceTierTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $operator;
    private Cluster $cluster;
    private Trip $trip;
    private ProductVariant $variant;
    private PriceTier $tier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner', 'komisi_per_mika' => 1000, 'app_fee_per_pack' => 300]);
        $this->operator = User::factory()->create(['role' => 'operator', 'owner_id' => $this->owner->id, 'is_active' => true]);
        $this->cluster = Cluster::create(['name' => 'Area Harga', 'owner_id' => $this->owner->id, 'is_active' => true]);
        $this->variant = $this->variantFor($this->owner);
        $this->tier = $this->tierFor($this->owner, $this->variant, 'Khusus', 15000);
        $this->trip = Trip::factory()->create([
            'owner_id' => $this->owner->id, 'operator_id' => $this->operator->id,
            'starting_cluster_id' => $this->cluster->id, 'trip_date' => today(),
            'started_at' => now(), 'ended_at' => null, 'qty_carried_total' => 100,
            'komisi_per_mika_snapshot' => 1000, 'app_fee_per_pack_snapshot' => 300,
        ]);
        $this->actingAs($this->operator);
    }

    private function variantFor(User $owner): ProductVariant
    {
        return ProductVariant::factory()->create([
            'product_id' => Product::factory()->create(['owner_id' => $owner->id, 'is_active' => true])->id,
            'units_per_pack' => 15, 'sale_price_per_pack' => 12000, 'cost_per_pack' => 9500, 'is_active' => true,
        ]);
    }

    private function tierFor(User $owner, ProductVariant $variant, string $name, int $price, bool $active = true): PriceTier
    {
        return PriceTier::create([
            'owner_id' => $owner->id, 'product_variant_id' => $variant->id,
            'name' => $name, 'sale_price_per_pack' => $price, 'is_default' => false, 'is_active' => $active,
        ]);
    }

    private function kiosk(?int $tierId = null, bool $cash = false): Kiosk
    {
        return Kiosk::factory()->create([
            'cluster_id' => $this->cluster->id, 'price_tier_id' => $tierId,
            'default_qty_mika' => 2, 'is_cash_only' => $cash,
        ]);
    }

    public function test_default_and_custom_consignment_snapshot_price_without_changing_units_or_hpp(): void
    {
        foreach ([[null, 12000], [$this->tier->id, 15000]] as [$tierId, $price]) {
            $kiosk = $this->kiosk($tierId);
            Livewire::test(ActiveTrip::class)->call('openVisitModal', $kiosk->id)
                ->set('dropBaru', 2)->call('saveVisit')->assertHasNoErrors();
            $delivery = Delivery::where('kiosk_id', $kiosk->id)->sole();
            $this->assertEquals($price, $delivery->unit_price);
            $this->assertEquals(9500, $delivery->cost_snapshot);
            $this->assertEquals(15, $delivery->units_per_pack_snapshot);
            $this->assertSame('consignment', $delivery->delivery_type);
        }
        $this->assertSame(0, Settlement::count());
    }

    public function test_default_and_custom_cash_use_tier_but_fee_and_commission_stay_quantity_based(): void
    {
        foreach ([[null, 12000], [$this->tier->id, 15000]] as [$tierId, $price]) {
            $kiosk = $this->kiosk($tierId, true);
            Livewire::test(ActiveTrip::class)->call('openVisitModal', $kiosk->id)
                ->set('dropBaru', 2)->call('saveVisit')->assertHasNoErrors();
            $delivery = Delivery::where('kiosk_id', $kiosk->id)->sole();
            $this->assertEquals($price, $delivery->unit_price);
            $this->assertEquals(2 * $price, $delivery->settlement->amount_paid);
            $this->assertEquals(9500, $delivery->cost_snapshot);
            $this->assertEquals(15, $delivery->units_per_pack_snapshot);
        }
        $this->assertEquals(4000, $this->trip->fresh()->komisi_rian);
        $fees = AppFeeCalculator::summaryForOwners()[$this->owner->id];
        $this->assertEquals(4, $fees['mika_total']);
        $this->assertEquals(1200, $fees['fee_total']);
    }

    public function test_titip_cash_bs_preview_and_payment_use_kiosk_tier(): void
    {
        $kiosk = $this->kiosk($this->tier->id);
        $component = Livewire::test(ActiveTrip::class)->call('openVisitModal', $kiosk->id)
            ->call('chooseAction', 'titip_cash')->set('dropBaru', 2)->set('qtyBsCash', 3);
        $this->assertEquals(15000, $component->instance()->previewVariant()->sale_price_per_pack);
        $component->assertSee('Rp 1.000/biji')->call('saveVisit')->assertHasNoErrors();
        $delivery = Delivery::where('kiosk_id', $kiosk->id)->sole();
        $this->assertEquals(27000, $delivery->settlement->amount_paid);
        $this->assertEquals(3, $delivery->settlement->qty_returned_expired);
        $this->assertEquals(2000, $this->trip->fresh()->komisi_rian);
    }

    public function test_billing_old_snapshot_is_distinct_from_new_tier_and_redistribution(): void
    {
        $kiosk = $this->kiosk($this->tier->id);
        $oldTrip = Trip::factory()->create(['owner_id' => $this->owner->id, 'operator_id' => $this->operator->id, 'trip_date' => today()->subDay(), 'ended_at' => now()]);
        $old = Delivery::create([
            'kiosk_id' => $kiosk->id, 'trip_id' => $oldTrip->id, 'product_variant_id' => $this->variant->id,
            'source_type' => 'new_procurement', 'delivery_type' => 'consignment', 'qty_delivered' => 2,
            'unit_price' => 12000, 'cost_snapshot' => 9500, 'units_per_pack_snapshot' => 15,
        ]);
        Livewire::test(ActiveTrip::class)->call('openVisitModal', $kiosk->id)->call('chooseAction', 'tagih_titip')
            ->assertSee('Rp 800/biji')->assertSee('Rp 1.000/biji')
            ->set('adaBsRedistribusi', true)->set('qtyBsMika', 1)->call('saveVisit')->assertHasNoErrors();
        $this->assertEquals(24000, $old->fresh()->settlement->amount_due);
        $new = Delivery::where('trip_id', $this->trip->id)->get();
        $this->assertCount(2, $new);
        foreach ($new as $delivery) {
            $this->assertEquals(15000, $delivery->unit_price);
        }
        $this->assertEquals(0, $new->firstWhere('delivery_type', 'bs_redistribution')->cost_snapshot);
    }

    public function test_walkin_uses_standard_not_selected_kiosk_tier(): void
    {
        $component = Livewire::test(ActiveTrip::class)->call('openVisitModal', $this->kiosk($this->tier->id)->id);
        $this->assertEquals(12000, $component->instance()->previewVariant(true)->sale_price_per_pack);
        $component->call('closeVisitModal')->call('openWalkInModal')->set('walkInMika', 2)
            ->call('saveWalkInCash')->assertHasNoErrors();
        $this->assertEquals(12000, Delivery::sole()->unit_price);
        $this->assertEquals(24000, Settlement::sole()->amount_paid);
    }

    public function test_create_kiosk_persists_custom_tier_and_initial_consignment_price(): void
    {
        Livewire::test(CreateKiosk::class)->set('namaKios', 'Kios Harga Khusus')->set('namaPemilik', 'Pemilik')
            ->set('clusterId', $this->cluster->id)->set('price_tier_id', $this->tier->id)
            ->set('defaultQtyMika', 2)->call('saveKiosk')->assertHasNoErrors();
        $kiosk = Kiosk::where('name', 'Kios Harga Khusus')->sole();
        $this->assertEquals($this->tier->id, $kiosk->price_tier_id);
        $this->assertEquals(15000, $kiosk->deliveries()->sole()->unit_price);
    }

    public function test_create_kiosk_null_tier_is_standard(): void
    {
        Livewire::test(CreateKiosk::class)->set('namaKios', 'Kios Standard')->set('namaPemilik', 'Pemilik')
            ->set('clusterId', $this->cluster->id)->set('defaultQtyMika', 2)->call('saveKiosk')->assertHasNoErrors();
        $kiosk = Kiosk::where('name', 'Kios Standard')->sole();
        $this->assertNull($kiosk->price_tier_id);
        $this->assertEquals(12000, $kiosk->deliveries()->sole()->unit_price);
    }

    public function test_create_rejects_forged_other_owner_wrong_variant_and_inactive_tiers(): void
    {
        $otherOwner = User::factory()->create(['role' => 'owner']);
        auth()->logout();
        $foreign = $this->tierFor($otherOwner, $this->variantFor($otherOwner), 'Foreign secret', 17000);
        $this->actingAs($this->operator);
        $wrongVariant = $this->tierFor($this->owner, $this->variantFor($this->owner), 'Other product secret', 18000);
        $inactive = $this->tierFor($this->owner, $this->variant, 'Inactive secret', 19000, false);
        foreach ([$foreign, $wrongVariant, $inactive] as $tier) {
            Livewire::test(CreateKiosk::class)->assertDontSee($tier->name)
                ->set('namaKios', 'Forged tier')->set('namaPemilik', 'Pemilik')->set('clusterId', $this->cluster->id)
                ->set('jenisKedai', 'booking')->set('price_tier_id', $tier->id)->call('saveKiosk')
                ->assertHasErrors(['price_tier_id']);
        }
        $this->assertDatabaseMissing('kiosks', ['name' => 'Forged tier']);
    }

    public function test_correction_preserves_consignment_and_bs_snapshots_after_tier_and_variant_change(): void
    {
        $kiosk = $this->kiosk($this->tier->id);
        $component = Livewire::test(ActiveTrip::class)->call('openVisitModal', $kiosk->id)
            ->set('dropBaru', 2)->set('adaBsRedistribusi', true)->set('qtyBsMika', 1)->call('saveVisit')->assertHasNoErrors();
        $visit = KioskVisit::active()->where('kiosk_id', $kiosk->id)->sole();
        $this->tier->update(['sale_price_per_pack' => 21000]);
        $this->variant->update(['units_per_pack' => 20, 'cost_per_pack' => 14000, 'is_active' => false]);
        $this->variantFor($this->owner);
        $component->call('correctVisit', $visit->id, 3, 0, 0, 0)->assertHasNoErrors();
        $deliveries = Delivery::where('kiosk_id', $kiosk->id)->get();
        $this->assertCount(2, $deliveries);
        foreach ($deliveries as $delivery) {
            $this->assertEquals($this->variant->id, $delivery->product_variant_id);
            $this->assertEquals(15000, $delivery->unit_price);
            $this->assertEquals(15, $delivery->units_per_pack_snapshot);
            $this->assertEquals($delivery->delivery_type === 'bs_redistribution' ? 0 : 9500, $delivery->cost_snapshot);
        }
        $this->assertEquals(1, $deliveries->firstWhere('delivery_type', 'bs_redistribution')->qty_delivered);
        $this->assertEquals(1, $this->trip->fresh()->qty_bs_redistributed);
    }

    public function test_correction_preserves_titip_cash_mode_bs_and_snapshot_even_without_active_variant(): void
    {
        // Existing consignment kiosk cash-extra must remain cash, not replay as a new consignment.
        $kiosk = $this->kiosk($this->tier->id);
        $component = Livewire::test(ActiveTrip::class)->call('openVisitModal', $kiosk->id)->call('chooseAction', 'titip_cash')
            ->set('dropBaru', 2)->set('qtyBsCash', 3)->call('saveVisit')->assertHasNoErrors();
        $visit = KioskVisit::active()->where('kiosk_id', $kiosk->id)->sole();
        $this->tier->update(['sale_price_per_pack' => 21000]);
        $this->variant->update(['units_per_pack' => 20, 'cost_per_pack' => 14000, 'is_active' => false]);
        $component->call('correctVisit', $visit->id, 3, 0, 0, 0)->assertHasNoErrors();
        $delivery = Delivery::where('kiosk_id', $kiosk->id)->sole();
        $this->assertSame('cash_sale', $delivery->delivery_type);
        $this->assertEquals(15000, $delivery->unit_price);
        $this->assertEquals(9500, $delivery->cost_snapshot);
        $this->assertEquals(15, $delivery->units_per_pack_snapshot);
        $this->assertEquals(42000, $delivery->settlement->amount_paid);
        $this->assertEquals(3, $delivery->settlement->qty_returned_expired);
        $this->assertFalse($kiosk->fresh()->is_cash_only);
    }

    public function test_owner_preset_jatah_still_shows_never_delivered_and_prefills_start(): void
    {
        $kiosk = $this->kiosk();
        Livewire::test(ActiveTrip::class)->assertSee('Belum pernah dititip')->call('openVisitModal', $kiosk->id)
            ->call('chooseAction', 'mulai_titipan')->assertSet('jatahMulai', 2)->assertSet('dropBaru', 2);
    }
}
