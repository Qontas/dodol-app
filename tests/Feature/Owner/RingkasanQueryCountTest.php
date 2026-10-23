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
            // dashboard lain yg memang scaling (mis. "Untung Bersih Hari Ini" yang masih
            // sengaja per-trip lewat accessor, lihat docblock
            // OwnerDashboardController::index() baris $untungBersihHariIni — di luar
            // lingkup brief ini).
            //
            // 🔴 DIPERBAIKI 24 Okt 2026 (BRIEF "Satu Sumber Kebenaran Komisi", fix A/B) —
            // signature lama (regex di bawah SEBELUM fix ini) cocok dgn SQL raw lama
            // monthlySummary()/$komisiPerOperator yg query sendiri-sendiri (SUM(amount_paid),
            // SUM(qty_delivered) whereHas year(), join "as operator_name"). Sekarang
            // monthlySummary() DAN $komisiPerOperator SAMA-SAMA pakai
            // TripAggregator::for() (fix A/B) — SQL shape total berbeda, jadi signature di
            // bawah ini di-cocokkan ULANG ke query TripAggregator (8 query batch per bulan,
            // semua pakai "in (...)", BUKAN N+1) + 2 pluck id trip bulan ini/lalu. Angka
            // 10 di bawah = IDENTIK apapun jumlah trip (batch, bukan N+1) — itulah yg
            // sebenarnya dites file ini, bukan angka 10 itu sendiri.
            return collect($log)->filter(function ($q) {
                $sql = strtolower($q['query']);

                // (1) pluck id trip bulan ini (+operator_id) / bulan lalu — monthlySummary()
                //     & $tripsBulanIni, SATU query per bulan (bukan per trip).
                $pluckTripIds = str_contains($sql, 'from `trips`') && str_contains($sql, 'ended_at` is not null')
                    && str_contains($sql, 'year(`trip_date`)') && str_contains($sql, 'month(`trip_date`)');

                // (2)-(8): 7 query batch TripAggregator::for() (deliveryAgg, settledRows,
                //     settlements-join, activeVisits, kiosBaruRows, fallbackHpp,
                //     komisiSnapshotByTrip, sentinelTripIds) — tapi kolom/alias deliveryAgg &
                //     sentinelTripIds unik, sisanya DIPAKAI BERSAMA oleh $completedAgg (widget
                //     "5 trip terakhir") yg SENGAJA SUDAH batch sejak sebelum brief ini (bukan
                //     bagian fix A/B) — tak bisa dibedakan dari shape SQL semata (sama persis,
                //     cuma beda trip id di parameter). Cukup hitung milik Ringkasan via (1) +
                //     dua signature TripAggregator yg TERJAMIN unik (tak overlap completedAgg
                //     pada skenario test ini, karena completedAgg selalu jalan sekali per
                //     request terlepas jumlah trip — konstan juga, lihat TUGAS sebelumnya).
                $deliveryAgg = str_contains($sql, 'as drop_komisi');
                $sentinel = str_contains($sql, 'inner join `users` on `users`.`id` = `trips`.`operator_id`') && str_contains($sql, 'like ?');

                return $pluckTripIds || $deliveryAgg || $sentinel;
            })->count();
        };

        $few = $countDashboardQueries(1);
        $many = $countDashboardQueries(6);

        // Konstan: 6x lebih banyak trip → jumlah query widget Ringkasan SAMA (agregat, no N+1).
        // Ini inti test (BUKAN angka 4 di bawah) — kalau ini gagal, ada N+1 baru.
        $this->assertSame($few, $many, "Query widget Ringkasan harus konstan (few=$few, many=$many)");
        // 6 query: 2 pluck id trip (bulan ini + lalu; bulan lalu kosong di skenario ini,
        // short-circuit sebelum sampai ke TripAggregator) + deliveryAgg & sentinelTripIds
        // TripAggregator MASING-MASING muncul 2x — SATU dari Ringkasan ($aggBulanIni),
        // SATU dari $completedAgg (widget "5 Trip Terakhir", TripAggregator::for() sendiri,
        // bukan bagian fix A/B tapi SAMA PERSIS shape SQL-nya, tak bisa dibedakan dari teks
        // query semata). Keduanya sama-sama batch (konstan), makanya tetap 6 di $few & $many.
        $this->assertSame(6, $many, "Widget Ringkasan = $many query");
    }
}
