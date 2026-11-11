<?php

namespace Tests\Feature\Console;

use App\Models\Cluster;
use App\Models\Delivery;
use App\Models\Kiosk;
use App\Models\PriceTier;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Settlement;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepricePendingDeliveriesCommandTest extends TestCase
{
    use RefreshDatabase;

    private function ownerData(): array
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $cluster = Cluster::factory()->create(['owner_id' => $owner->id]);
        $product = Product::factory()->create(['owner_id' => $owner->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'is_active' => true, 'sale_price_per_pack' => 12000, 'units_per_pack' => 15]);
        $tier = PriceTier::create(['owner_id' => $owner->id, 'product_variant_id' => $variant->id, 'name' => 'Khusus', 'sale_price_per_pack' => 14000, 'is_default' => false, 'is_active' => true]);
        $kiosk = Kiosk::factory()->create(['cluster_id' => $cluster->id, 'price_tier_id' => $tier->id]);
        $operator = User::factory()->create(['role' => 'operator', 'owner_id' => $owner->id]);
        $trip = Trip::factory()->create(['owner_id' => $owner->id, 'operator_id' => $operator->id]);

        return [$owner, $kiosk, $variant, $trip, $tier];
    }

    private function delivery(Kiosk $kiosk, ProductVariant $variant, Trip $trip, array $extra = []): Delivery
    {
        return Delivery::factory()->create(array_replace([
            'kiosk_id' => $kiosk->id, 'product_variant_id' => $variant->id, 'trip_id' => $trip->id,
            'delivery_type' => 'consignment', 'qty_delivered' => 3, 'unit_price' => 12000,
            'units_per_pack_snapshot' => 10, 'cost_snapshot' => 5555,
            'created_at' => '2026-10-01 08:00:00', 'updated_at' => '2026-10-02 09:00:00',
        ], $extra));
    }

    public function test_requires_explicit_valid_owner(): void
    {
        $this->artisan('deliveries:reprice-pending')->assertFailed();
        $this->artisan('deliveries:reprice-pending', ['--owner' => 999999])->assertFailed();
        $operator = User::factory()->create(['role' => 'operator']);
        $this->artisan('deliveries:reprice-pending', ['--owner' => $operator->id])->assertFailed();
    }

    public function test_dry_run_and_force_change_only_unit_price_with_snapshot_divisor_and_delta(): void
    {
        [$owner, $kiosk, $variant, $trip] = $this->ownerData();
        $delivery = $this->delivery($kiosk, $variant, $trip);
        $before = $delivery->fresh()->getAttributes();
        $this->artisan('deliveries:reprice-pending', ['--owner' => $owner->id])
            ->expectsOutputToContain('[DRY-RUN] 1')
            ->expectsOutputToContain('TOTAL DELTA: Rp 6.000,00')
            ->assertSuccessful();
        $this->assertSame($before, $delivery->fresh()->getAttributes());
        $this->artisan('deliveries:reprice-pending', ['--owner' => $owner->id, '--force' => true])
            ->expectsTable(['ID', 'Kios', 'Tanggal', 'Mika', 'Isi Snapshot', 'Lama/Mika', 'Baru/Mika', 'Lama/Biji', 'Baru/Biji', 'Delta (Rp)'], [[
                $delivery->id, $kiosk->name, $delivery->created_at->toDateString(), 3, 10, '12.000,00', '14.000,00', '1.200,00', '1.400,00', '6.000,00',
            ]])->assertSuccessful();
        $after = $delivery->fresh()->getAttributes();
        $this->assertSame(14000.0, (float) $after['unit_price']);
        unset($after['unit_price'], $before['unit_price']);
        $this->assertSame($before, $after);
        $this->artisan('deliveries:reprice-pending', ['--owner' => $owner->id, '--force' => true])->expectsOutputToContain('[APPLY] 0')->assertSuccessful();
    }

    public function test_excludes_settlements_of_any_status_cash_bs_archived_and_other_tenants(): void
    {
        [$owner, $kiosk, $variant, $trip] = $this->ownerData();
        [$other, $otherKiosk, $otherVariant, $otherTrip] = $this->ownerData();
        $included = $this->delivery($kiosk, $variant, $trip);
        $paid = $this->delivery($kiosk, $variant, $trip);
        $unpaid = $this->delivery($kiosk, $variant, $trip);
        Settlement::factory()->create(['delivery_id' => $paid->id, 'qty_sold' => 30, 'amount_due' => 36000, 'amount_paid' => 36000]);
        Settlement::factory()->create(['delivery_id' => $unpaid->id, 'qty_sold' => 30, 'amount_due' => 36000, 'amount_paid' => 0]);
        $cash = $this->delivery($kiosk, $variant, $trip, ['delivery_type' => 'cash_sale']);
        $bs = $this->delivery($kiosk, $variant, $trip, ['delivery_type' => 'bs_redistribution']);
        $foreign = $this->delivery($otherKiosk, $otherVariant, $otherTrip);
        $crossKiosk = $this->delivery($otherKiosk, $variant, $trip);
        $crossTrip = $this->delivery($kiosk, $variant, $otherTrip);
        $archivedTrip = Trip::factory()->create(['owner_id' => $owner->id, 'operator_id' => $trip->operator_id]);
        $archived = $this->delivery($kiosk, $variant, $archivedTrip);
        $archivedTrip->delete();
        $unchanged = collect([$paid, $unpaid, $cash, $bs, $foreign, $crossKiosk, $crossTrip, $archived])->mapWithKeys(fn ($d) => [$d->id => $d->fresh()->getAttributes()]);
        $settlementsBefore = Settlement::orderBy('id')->get()->map(fn ($s) => $s->getAttributes())->all();
        $tripBefore = $trip->fresh()->getAttributes();
        $commissionBefore = $trip->fresh()->komisi_rian;
        $stockBefore = $trip->fresh()->getStokDiMotor();
        $this->artisan('deliveries:reprice-pending', ['--owner' => $owner->id, '--force' => true])->assertSuccessful();
        $this->assertSame(14000.0, (float) $included->fresh()->unit_price);
        foreach ($unchanged as $id => $attributes) {
            $this->assertSame($attributes, Delivery::findOrFail($id)->getAttributes());
        }
        $this->assertSame($settlementsBefore, Settlement::orderBy('id')->get()->map(fn ($s) => $s->getAttributes())->all());
        $this->assertSame($tripBefore, $trip->fresh()->getAttributes());
        $this->assertSame($commissionBefore, $trip->fresh()->komisi_rian);
        $this->assertSame($stockBefore, $trip->fresh()->getStokDiMotor());
    }

    public function test_uses_variant_fallback_for_kiosk_without_tier(): void
    {
        [$owner, $kiosk, $variant, $trip] = $this->ownerData();
        $kiosk->update(['price_tier_id' => null]);
        $delivery = $this->delivery($kiosk, $variant, $trip, ['unit_price' => 10000]);
        $this->artisan('deliveries:reprice-pending', ['--owner' => $owner->id, '--force' => true])->assertSuccessful();
        $this->assertSame(12000.0, (float) $delivery->fresh()->unit_price);
    }
}
