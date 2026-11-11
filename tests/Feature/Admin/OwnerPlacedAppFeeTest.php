<?php

namespace Tests\Feature\Admin;

use App\Models\Cluster;
use App\Models\Delivery;
use App\Models\Kiosk;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Trip;
use App\Models\User;
use App\Support\AppFeeCalculator;
use App\Support\OpeningBalance;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OwnerPlacedAppFeeTest extends TestCase
{
    use RefreshDatabase;

    private function ownerData(float $rate = 300): array
    {
        $owner = User::factory()->create(['role' => 'owner', 'app_fee_per_pack' => $rate]);
        $cluster = Cluster::factory()->create(['owner_id' => $owner->id]);
        $product = Product::factory()->create(['owner_id' => $owner->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'is_active' => true]);

        return [$owner, $cluster, $variant];
    }

    public function test_owner_placed_freezes_each_delivery_rate_not_shared_sentinel_rate(): void
    {
        [$owner, $cluster] = $this->ownerData();
        $first = OpeningBalance::create(Kiosk::factory()->create(['cluster_id' => $cluster->id]), 3, 'owner_placed');
        $owner->update(['app_fee_per_pack' => 500]);
        $second = OpeningBalance::create(Kiosk::factory()->create(['cluster_id' => $cluster->id]), 2, 'owner_placed');
        $this->assertSame($first->trip_id, $second->trip_id);
        $this->assertSame(300.0, (float) $first->app_fee_per_pack_snapshot);
        $this->assertSame(500.0, (float) $second->app_fee_per_pack_snapshot);
        $first->trip->update(['app_fee_per_pack_snapshot' => 9999]);
        $owner->update(['app_fee_per_pack' => 700]);

        $row = AppFeeCalculator::summaryForOwners()[$owner->id];
        $this->assertSame(5, $row['mika_total']);
        $this->assertSame(1900.0, $row['fee_total']);
        $this->assertSame(5, $row['mika_today']);
        $this->assertSame(1900.0, $row['fee_today']);
        $this->assertSame(700.0, $row['rate']);
        $this->assertSame(0.0, $first->trip->fresh()->mika_komisi);
        $this->assertSame(0.0, $first->trip->fresh()->komisi_rian);
        $this->assertSame(0, $first->trip->fresh()->getTotalDropReal());
        $this->assertSame(0, $first->trip->fresh()->getStokDiMotor());
        $this->assertDatabaseCount('commissions', 0);
    }

    public function test_owner_placed_uses_creation_day_in_application_timezone_across_month_boundary(): void
    {
        $originalTimezone = date_default_timezone_get();
        config(['app.timezone' => 'Asia/Jakarta']);
        date_default_timezone_set('Asia/Jakarta');
        try {
            $this->travelTo(Carbon::parse('2026-11-01 00:15:00', 'Asia/Jakarta'));
            [$owner, $cluster] = $this->ownerData();
            $current = OpeningBalance::create(Kiosk::factory()->create(['cluster_id' => $cluster->id]), 2, 'owner_placed');
            $prior = OpeningBalance::create(Kiosk::factory()->create(['cluster_id' => $cluster->id]), 3, 'owner_placed');
            $prior->forceFill(['created_at' => '2026-10-31 23:59:59'])->save();
            $this->assertSame('2000-01-01', $current->trip->trip_date->toDateString());
            $row = AppFeeCalculator::summaryForOwners()[$owner->id];
            $this->assertSame(2, $row['mika_today']);
            $this->assertSame(2, $row['mika_month']);
            $this->assertSame(5, $row['mika_total']);
            $november = AppFeeCalculator::summaryByOwnerAndDate(2026, 11);
            $this->assertCount(1, $november);
            $this->assertSame('2026-11-01', $november[0]['date']);
            $this->assertSame(600.0, $november[0]['fee']);
            $october = AppFeeCalculator::summaryByOwnerAndDate(2026, 10);
            $this->assertSame('2026-10-31', $october[0]['date']);
            $this->assertSame(900.0, $october[0]['fee']);
        } finally {
            $this->travelBack();
            date_default_timezone_set($originalTimezone);
        }
    }

    public function test_migration_bs_archived_and_legacy_sentinel_are_excluded_normal_snapshot_unchanged(): void
    {
        [$owner, $cluster, $variant] = $this->ownerData();
        $kiosk = Kiosk::factory()->create(['cluster_id' => $cluster->id]);
        $ownerPlaced = OpeningBalance::create($kiosk, 2, 'owner_placed');
        $operator = User::factory()->create(['role' => 'operator', 'owner_id' => $owner->id]);
        $trip = Trip::factory()->create(['owner_id' => $owner->id, 'operator_id' => $operator->id, 'trip_date' => today(), 'app_fee_per_pack_snapshot' => 100]);
        $base = ['kiosk_id' => $kiosk->id, 'product_variant_id' => $variant->id, 'trip_id' => $trip->id];
        Delivery::factory()->create($base + ['source_type' => 'new_procurement', 'qty_delivered' => 4, 'delivery_type' => 'cash_sale']);
        Delivery::factory()->create($base + ['source_type' => 'migration', 'qty_delivered' => 9]);
        Delivery::factory()->create($base + ['source_type' => 'owner_placed', 'qty_delivered' => 10, 'delivery_type' => 'bs_redistribution']);
        Delivery::factory()->create(array_replace($base, ['trip_id' => $ownerPlaced->trip_id, 'source_type' => 'new_procurement', 'qty_delivered' => 8]));
        $archived = Trip::factory()->create(['owner_id' => $owner->id, 'operator_id' => $operator->id, 'trip_date' => today(), 'trip_number_of_day' => 2]);
        Delivery::factory()->create(array_replace($base, ['trip_id' => $archived->id, 'source_type' => 'owner_placed', 'qty_delivered' => 12]));
        $archived->delete();
        $owner->update(['app_fee_per_pack' => 999]);
        $summary = AppFeeCalculator::summaryForOwners()[$owner->id];
        $this->assertSame(6, $summary['mika_total']);
        $this->assertSame(1000.0, $summary['fee_total']);
        $daily = AppFeeCalculator::summaryByOwnerAndDate((int) today()->year, (int) today()->month);
        $this->assertSame($summary['fee_month'], array_sum(array_column($daily, 'fee')));
    }

    public function test_owner_placed_null_delivery_snapshot_never_falls_back_to_trip_or_live_owner(): void
    {
        [$owner, $cluster] = $this->ownerData();
        $delivery = OpeningBalance::create(Kiosk::factory()->create(['cluster_id' => $cluster->id]), 2, 'owner_placed');
        $delivery->forceFill(['app_fee_per_pack_snapshot' => null])->save();
        $delivery->trip->update(['app_fee_per_pack_snapshot' => 900]);
        $row = AppFeeCalculator::summaryForOwners()[$owner->id];
        $this->assertSame(2, $row['mika_total']);
        $this->assertSame(0.0, $row['fee_total']);
    }

    public function test_default_migration_is_excluded_and_owner_totals_do_not_mix(): void
    {
        [$firstOwner, $firstCluster] = $this->ownerData(300);
        [$secondOwner, $secondCluster] = $this->ownerData(700);
        $migration = OpeningBalance::create(Kiosk::factory()->create(['cluster_id' => $firstCluster->id]), 99);
        OpeningBalance::create(Kiosk::factory()->create(['cluster_id' => $firstCluster->id]), 2, 'owner_placed');
        OpeningBalance::create(Kiosk::factory()->create(['cluster_id' => $secondCluster->id]), 3, 'owner_placed');
        $this->assertSame('migration', $migration->source_type);
        $rows = AppFeeCalculator::summaryForOwners();
        $this->assertSame(600.0, $rows[$firstOwner->id]['fee_total']);
        $this->assertSame(2100.0, $rows[$secondOwner->id]['fee_total']);
    }
}
