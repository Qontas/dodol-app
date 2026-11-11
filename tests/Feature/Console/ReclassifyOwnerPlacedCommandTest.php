<?php

namespace Tests\Feature\Console;

use App\Models\Cluster;
use App\Models\Delivery;
use App\Models\Kiosk;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Settlement;
use App\Models\Trip;
use App\Models\User;
use App\Support\AppFeeCalculator;
use App\Support\OpeningBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReclassifyOwnerPlacedCommandTest extends TestCase
{
    use RefreshDatabase;

    private function ownerData(int $rate = 300): array
    {
        $owner = User::factory()->create(['role' => 'owner', 'app_fee_per_pack' => $rate]);
        $cluster = Cluster::factory()->create(['owner_id' => $owner->id]);
        $product = Product::factory()->create(['owner_id' => $owner->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'is_active' => true]);

        return [$owner, $cluster, $variant];
    }

    private function manual(Cluster $cluster, string $created = '2026-11-01 00:00:00'): Delivery
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $cluster->id, 'created_at' => $created]);

        return OpeningBalance::create($kiosk, 2);
    }

    public function test_requires_valid_owner_and_strict_since_date(): void
    {
        [$owner] = $this->ownerData();
        $this->artisan('fee:reclassify-owner-placed')->assertFailed();
        $this->artisan('fee:reclassify-owner-placed', ['--owner' => $owner->id])->assertFailed();
        $this->artisan('fee:reclassify-owner-placed', ['--owner' => $owner->id, '--since' => '2026-02-30'])->assertFailed();
        $this->artisan('fee:reclassify-owner-placed', ['--owner' => $owner->id, '--since' => '11/01/2026'])->assertFailed();
        $operator = User::factory()->create(['role' => 'operator']);
        $this->artisan('fee:reclassify-owner-placed', ['--owner' => $operator->id, '--since' => '2026-11-01'])->assertFailed();
    }

    public function test_default_dry_run_and_force_freeze_current_tariff_idempotently(): void
    {
        [$owner, $cluster] = $this->ownerData();
        $delivery = $this->manual($cluster);
        // Legacy manual entries were new_procurement, new entries are migration.
        $delivery->forceFill(['source_type' => 'new_procurement'])->save();
        $original = $delivery->fresh()->getAttributes();
        $options = ['--owner' => $owner->id, '--since' => '2026-11-01'];
        $this->artisan('fee:reclassify-owner-placed', $options)->expectsOutputToContain('[DRY-RUN] 1')->assertSuccessful();
        $this->assertSame($original, $delivery->fresh()->getAttributes());
        $owner->update(['app_fee_per_pack' => 450]);
        $this->artisan('fee:reclassify-owner-placed', $options + ['--force' => true])->expectsOutputToContain('[APPLY] 1')->assertSuccessful();
        $after = $delivery->fresh()->getAttributes();
        $this->assertSame('owner_placed', $after['source_type']);
        $this->assertSame(450.0, (float) $after['app_fee_per_pack_snapshot']);
        unset($after['source_type'], $after['app_fee_per_pack_snapshot'], $original['source_type'], $original['app_fee_per_pack_snapshot']);
        $this->assertSame($original, $after);
        $owner->update(['app_fee_per_pack' => 900]);
        $this->artisan('fee:reclassify-owner-placed', $options + ['--force' => true])->expectsOutputToContain('[APPLY] 0')->assertSuccessful();
        $this->assertSame(450.0, (float) $delivery->fresh()->app_fee_per_pack_snapshot);
        $this->assertSame(900.0, AppFeeCalculator::summaryForOwners()[$owner->id]['fee_total']);
    }

    public function test_only_manual_matching_owner_and_kiosk_creation_cutoff_inclusive(): void
    {
        [$owner, $cluster, $variant] = $this->ownerData();
        [$other, $otherCluster] = $this->ownerData();
        $included = $this->manual($cluster);
        $old = $this->manual($cluster, '2026-10-31 23:59:59');
        $foreign = $this->manual($otherCluster);
        $spreadsheet = $this->manual($cluster);
        $sheetTrip = Trip::factory()->create([
            'owner_id' => $owner->id, 'operator_id' => $included->trip->operator_id,
            'ended_reason' => 'Migrasi saldo awal dari spreadsheet',
        ]);
        $spreadsheet->forceFill(['trip_id' => $sheetTrip->id, 'notes' => 'Saldo awal migrasi (titipan lapangan sebelum sistem).'])->save();
        // Corrupt cross-tenant relationship: matching trip owner is not sufficient.
        $cross = Delivery::factory()->create([
            'trip_id' => $included->trip_id, 'kiosk_id' => $foreign->kiosk_id,
            'product_variant_id' => $variant->id, 'source_type' => 'new_procurement',
            'notes' => $included->notes,
        ]);
        $this->artisan('fee:reclassify-owner-placed', ['--owner' => $owner->id, '--since' => '2026-11-01', '--force' => true])->assertSuccessful();
        $this->assertSame('owner_placed', $included->fresh()->source_type);
        foreach ([$old, $foreign, $spreadsheet] as $excluded) {
            $this->assertSame('migration', $excluded->fresh()->source_type);
        }
        $this->assertSame('new_procurement', $cross->fresh()->source_type);
    }

    public function test_settled_included_without_changing_finance_commission_stock_or_settlement(): void
    {
        [$owner, $cluster] = $this->ownerData();
        $delivery = $this->manual($cluster);
        $settlement = Settlement::factory()->create([
            'delivery_id' => $delivery->id, 'qty_sold' => $delivery->qty_biji_delivered,
            'amount_due' => 24000, 'amount_paid' => 10000,
        ]);
        $beforeSettlement = $settlement->fresh()->getAttributes();
        $beforeDelivery = $delivery->fresh()->getAttributes();
        $trip = $delivery->trip->fresh();
        $beforeTrip = $trip->getAttributes();
        $beforeCommission = $trip->komisi_rian;
        $beforeStock = $trip->getStokDiMotor();
        $this->artisan('fee:reclassify-owner-placed', ['--owner' => $owner->id, '--since' => '2026-11-01', '--force' => true])->assertSuccessful();
        $this->assertSame('owner_placed', $delivery->fresh()->source_type);
        $this->assertSame($beforeSettlement, $settlement->fresh()->getAttributes());
        $this->assertSame($beforeTrip, $trip->fresh()->getAttributes());
        $this->assertSame($beforeCommission, $trip->fresh()->komisi_rian);
        $this->assertSame($beforeStock, $trip->fresh()->getStokDiMotor());
        $afterDelivery = $delivery->fresh()->getAttributes();
        unset($afterDelivery['source_type'], $afterDelivery['app_fee_per_pack_snapshot'], $beforeDelivery['source_type'], $beforeDelivery['app_fee_per_pack_snapshot']);
        $this->assertSame($beforeDelivery, $afterDelivery);
        $this->assertDatabaseCount('commissions', 0);
    }
}
