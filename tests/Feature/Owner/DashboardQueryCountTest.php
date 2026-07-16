<?php

namespace Tests\Feature\Owner;

use App\Models\Cluster;
use App\Models\Delivery;
use App\Models\Kiosk;
use App\Models\KioskVisit;
use App\Models\ProductVariant;
use App\Models\Settlement;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardQueryCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_measure_dashboard_query_count(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true, 'hpp_per_mika' => 9500, 'komisi_per_mika' => 1000]);
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'name' => 'Rian', 'owner_id' => $owner->id]);
        $cluster = Cluster::create(['name' => 'C', 'owner_id' => $owner->id]);
        $variant = ProductVariant::factory()->create(['is_active' => true, 'sale_price_per_pack' => 12000]);

        // 5 trip selesai (batas take(5) dashboard), tiap trip beberapa kunjungan.
        for ($i = 1; $i <= 5; $i++) {
            $trip = Trip::factory()->create([
                'owner_id' => $owner->id, 'operator_id' => $operator->id,
                'starting_cluster_id' => $cluster->id, 'trip_date' => now()->subDays($i)->format('Y-m-d'),
                'trip_number_of_day' => $i, 'started_at' => now()->subDays($i), 'ended_at' => now()->subDays($i),
                'qty_carried_total' => 75,
            ]);
            for ($k = 0; $k < 3; $k++) {
                $kiosk = Kiosk::factory()->create(['cluster_id' => $cluster->id, 'first_titip_date' => now()->subDays($i)->format('Y-m-d')]);
                $delivery = Delivery::factory()->create(['kiosk_id' => $kiosk->id, 'trip_id' => $trip->id, 'qty_delivered' => 10, 'product_variant_id' => $variant->id, 'delivery_type' => 'consignment']);
                Settlement::create(['delivery_id' => $delivery->id, 'visit_date' => now()->subDays($i)->format('Y-m-d'), 'qty_sold' => 150, 'qty_returned_fresh' => 0, 'qty_returned_expired' => 0, 'amount_due' => 120000, 'amount_paid' => 120000, 'status' => 'paid']);
                KioskVisit::create(['trip_id' => $trip->id, 'kiosk_id' => $kiosk->id, 'visited_at' => now()->subDays($i), 'visit_action' => 'drop_only', 'new_delivery_id' => $delivery->id, 'settled_delivery_id' => $delivery->id, 'extension_granted' => false]);
            }
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($owner)->get('/owner/dashboard')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Baseline sebelum optimasi (accessor per-baris) = 138 query untuk 5 trip × 3
        // kunjungan. Setelah TripAggregator batch = ~38. Ambang 60 mengunci perbaikan &
        // mencegah regresi balik ke N+1 (kalau ada yang mengembalikan accessor per-baris,
        // angka meroket > 60 → merah).
        $this->assertLessThan(60, $count, "Dashboard query count {$count} — indikasi N+1 accessor completedTrips kembali.");
    }

    public function test_dashboard_financials_identical_to_accessors(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true, 'hpp_per_mika' => 9500, 'komisi_per_mika' => 1000]);
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'name' => 'Rian', 'owner_id' => $owner->id]);
        $cluster = Cluster::create(['name' => 'C', 'owner_id' => $owner->id]);
        $variant = ProductVariant::factory()->create(['is_active' => true, 'sale_price_per_pack' => 12000]);

        $trip = Trip::factory()->create([
            'owner_id' => $owner->id, 'operator_id' => $operator->id, 'starting_cluster_id' => $cluster->id,
            'trip_date' => today()->format('Y-m-d'), 'trip_number_of_day' => 1,
            'started_at' => now(), 'ended_at' => now(), 'qty_carried_total' => 75,
        ]);
        $kiosk = Kiosk::factory()->create(['cluster_id' => $cluster->id, 'first_titip_date' => today()->format('Y-m-d')]);
        $delivery = Delivery::factory()->create(['kiosk_id' => $kiosk->id, 'trip_id' => $trip->id, 'qty_delivered' => 10, 'product_variant_id' => $variant->id, 'delivery_type' => 'consignment']);
        Settlement::create(['delivery_id' => $delivery->id, 'visit_date' => today()->format('Y-m-d'), 'qty_sold' => 150, 'qty_returned_fresh' => 0, 'qty_returned_expired' => 0, 'amount_due' => 120000, 'amount_paid' => 120000, 'status' => 'paid']);
        KioskVisit::create(['trip_id' => $trip->id, 'kiosk_id' => $kiosk->id, 'visited_at' => now(), 'visit_action' => 'drop_and_settle', 'new_delivery_id' => $delivery->id, 'settled_delivery_id' => $delivery->id, 'extension_granted' => false]);
        Delivery::factory()->create(['kiosk_id' => $kiosk->id, 'trip_id' => $trip->id, 'qty_delivered' => 3, 'product_variant_id' => $variant->id, 'delivery_type' => 'cash_sale']);

        $response = $this->actingAs($owner)->get('/owner/dashboard');
        $response->assertOk();

        // Nilai yang dipakai blade (completedAgg) IDENTIK accessor per-baris.
        $agg = $response->viewData('completedAgg')[$trip->id];
        $trip->refresh();
        $this->assertEqualsWithDelta($trip->omset_val, $agg['omset'], 0.0001);
        $this->assertEqualsWithDelta($trip->komisi_rian, $agg['komisi'], 0.0001);
        $this->assertEqualsWithDelta($trip->untung_bersih_owner, $agg['untung_bersih'], 0.0001);
        $this->assertEqualsWithDelta($trip->mika_terjual, $agg['mika_terjual'], 0.0001);

        // Angka konkret ter-render.
        $response->assertSee('Rp 120.000');   // omset
        $response->assertSee('Rp 13.000');    // komisi (10+3)×1.000
    }
}
