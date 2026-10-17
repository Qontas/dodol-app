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

class RingkasanQueryCountTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Query widget "Ringkasan Bulan Ini" TIDAK bertambah saat jumlah trip naik
     * (agregat DB, bukan N+1). Kita bandingkan jumlah query yg menyentuh
     * settlements/deliveries antara bulan 1-trip vs bulan 6-trip.
     */
    public function test_ringkasan_queries_constant_regardless_of_trip_count(): void
    {
        $countDashboardQueries = function (int $trips): int {
            // Fresh tenant tiap kali.
            $owner = User::factory()->create(['role' => 'owner', 'is_active' => true, 'hpp_per_mika' => 9500, 'komisi_per_mika' => 1000]);
            $operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $owner->id]);
            $cluster = Cluster::create(['name' => 'C '.$owner->id, 'owner_id' => $owner->id]);
            $variant = ProductVariant::factory()->create(['is_active' => true, 'sale_price_per_pack' => 12000]);
            $kiosk = Kiosk::factory()->create(['cluster_id' => $cluster->id]);

            for ($i = 1; $i <= $trips; $i++) {
                $trip = Trip::factory()->create([
                    'owner_id' => $owner->id, 'operator_id' => $operator->id,
                    'starting_cluster_id' => $cluster->id, 'trip_date' => today()->format('Y-m-d'),
                    'trip_number_of_day' => $i, 'started_at' => now(), 'ended_at' => now(),
                ]);
                $delivery = Delivery::factory()->create([
                    'kiosk_id' => $kiosk->id, 'trip_id' => $trip->id,
                    'qty_delivered' => 5, 'product_variant_id' => $variant->id, 'delivery_type' => 'consignment',
                ]);
                Settlement::create([
                    'delivery_id' => $delivery->id, 'visit_date' => today()->format('Y-m-d'),
                    'qty_sold' => 75, 'qty_returned_fresh' => 0, 'qty_returned_expired' => 0,
                    'amount_due' => 60000, 'amount_paid' => 60000, 'status' => 'paid',
                ]);
                KioskVisit::create([
                    'trip_id' => $trip->id, 'kiosk_id' => $kiosk->id, 'visited_at' => now(),
                    'visit_action' => 'drop_and_settle', 'new_delivery_id' => $delivery->id,
                    'settled_delivery_id' => $delivery->id, 'extension_granted' => false,
                ]);
            }

            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($owner)->get('/owner/dashboard')->assertOk();
            $log = DB::getQueryLog();
            DB::disableQueryLog();

            // Hitung HANYA query milik widget Ringkasan (signature unik), bukan widget
            // dashboard lain yg memang scaling (mis. completedTrips accessor).
            return collect($log)->filter(function ($q) {
                $sql = strtolower($q['query']);

                // 1. pluck settled_delivery_ids (bulan ini + lalu)
                $pluckIds = str_contains($sql, 'settled_delivery_id') && str_contains($sql, 'kiosk_visits') && str_contains($sql, 'exists');
                // 2. join settlements→deliveries per BARIS (amount_paid+qty_sold+unit_price,
                //    bulan ini + lalu) — pengganti SUM(amount_paid)+SUM(qty_sold) agregat lama;
                //    sekarang harus per-baris karena unit_price/cost_snapshot milik delivery,
                //    tapi TETAP 1 query per bulan (bukan N+1). Dibedakan dari query serupa
                //    widget "Kerugian Titipan" (yang cuma select qty_returned_expired, tanpa
                //    amount_paid/qty_sold/unit_price) DAN dari query TripAggregator::for()
                //    milik completedAgg (shape sama tapi select tambahan settlements.delivery_id
                //    di kolom pertama, untuk grouping per-delivery-nya sendiri — bukan Ringkasan).
                $saleRowsJoin = str_contains($sql, 'amount_paid') && str_contains($sql, 'qty_sold') && str_contains($sql, 'unit_price') && str_contains($sql, 'inner join `deliveries`') && ! str_contains($sql, 'select `settlements`.`delivery_id`,');
                // 4. komisi per-operator (1x)
                $perOperator = str_contains($sql, 'as operator_name');
                // 3. drop-sum komisi (bulan ini + lalu) — sum(qty_delivered) via whereHas year(),
                //    beda dari accessor per-trip (trip_id = ?, tanpa year). Kecualikan per-operator.
                $dropSum = str_contains($sql, 'sum(`qty_delivered`)') && str_contains($sql, 'year(') && ! $perOperator;

                return $pluckIds || $saleRowsJoin || $dropSum || $perOperator;
            })->count();
        };

        $few = $countDashboardQueries(1);
        $many = $countDashboardQueries(6);

        // Konstan: 6x lebih banyak trip → jumlah query widget Ringkasan SAMA (agregat, no N+1).
        $this->assertSame($few, $many, "Query widget Ringkasan harus konstan (few=$few, many=$many)");
        // 7 query: 2 pluck-id + 2 join settlement+delivery per-baris + 2 drop-sum + 1 per-operator (bulan ini & lalu).
        $this->assertSame(7, $many, "Widget Ringkasan = $many query");
    }
}
