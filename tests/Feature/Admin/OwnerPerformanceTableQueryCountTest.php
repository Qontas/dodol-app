<?php

namespace Tests\Feature\Admin;

use App\Filament\Widgets\OwnerPerformanceTable;
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
use Livewire\Livewire;
use Tests\TestCase;

/**
 * BRIEF 2 Section 4 — PENGAMAN UTAMA rombakan OwnerPerformanceTable: query HARUS
 * KONSTAN berapa pun banyak owner (dulu N×7, sekarang ~2 + konstanta internal
 * TripAggregator). Test ini membangun 1 owner lalu 5 owner dengan data IDENTIK
 * per owner dan membuktikan jumlah query TIDAK naik proporsional.
 */
class OwnerPerformanceTableQueryCountTest extends TestCase
{
    use RefreshDatabase;

    private function seedOwnerWithTodayTrip(): User
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true, 'komisi_per_mika' => 1000]);
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $owner->id]);
        $cluster = Cluster::create(['name' => 'C-'.$owner->id, 'owner_id' => $owner->id]);
        $variant = ProductVariant::factory()->create(['is_active' => true, 'sale_price_per_pack' => 12000]);
        $kiosk = Kiosk::factory()->create(['cluster_id' => $cluster->id]);

        $trip = Trip::factory()->create([
            'owner_id' => $owner->id, 'operator_id' => $operator->id,
            'starting_cluster_id' => $cluster->id, 'trip_date' => today()->format('Y-m-d'),
            'trip_number_of_day' => 1, 'started_at' => now(), 'ended_at' => now(),
            'qty_carried_total' => 50, 'komisi_per_mika_snapshot' => 1000,
        ]);

        $delivery = Delivery::factory()->create(['kiosk_id' => $kiosk->id, 'trip_id' => $trip->id, 'qty_delivered' => 10, 'product_variant_id' => $variant->id, 'delivery_type' => 'consignment']);
        Settlement::create(['delivery_id' => $delivery->id, 'visit_date' => today()->format('Y-m-d'), 'qty_sold' => 150, 'qty_returned_fresh' => 0, 'qty_returned_expired' => 0, 'amount_due' => 120000, 'amount_paid' => 120000, 'status' => 'paid']);
        KioskVisit::create(['trip_id' => $trip->id, 'kiosk_id' => $kiosk->id, 'visited_at' => now(), 'visit_action' => 'drop_and_settle', 'new_delivery_id' => $delivery->id, 'settled_delivery_id' => $delivery->id, 'extension_granted' => false]);

        return $owner;
    }

    private function renderAndCountQueries(): int
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($superAdmin);

        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(OwnerPerformanceTable::class)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_query_count_for_one_owner(): void
    {
        $this->seedOwnerWithTodayTrip();
        $count = $this->renderAndCountQueries();

        // Diukur: 10 query untuk 1 owner (2 query owner-level + 8 query internal
        // TripAggregator + auth/panel). Ambang 15 memberi sedikit ruang napas tanpa
        // kehilangan daya deteksi N+1 (baseline lama per-baris adalah 7 query HANYA
        // untuk SATU owner, jadi 20+ untuk 5 owner — ambang ini jauh di bawah itu).
        $this->assertLessThan(15, $count, "1 owner: {$count} query.");
    }

    public function test_query_count_for_five_owners_stays_in_same_order_of_magnitude(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->seedOwnerWithTodayTrip();
        }
        $count = $this->renderAndCountQueries();

        // Diukur: 10 query untuk 5 owner — IDENTIK dengan 1 owner (lihat test di
        // atas). Dulu (N×7 per-baris) 5 owner akan jadi ≈35+ query. Ambang 15 sama
        // dengan test 1-owner membuktikan jumlah query TIDAK proporsional terhadap
        // jumlah owner.
        $this->assertLessThan(15, $count, "5 owner: {$count} query — indikasi N+1 per-baris owner kembali.");
    }

    public function test_concrete_numbers_match_trip_aggregator_basis(): void
    {
        $owner = $this->seedOwnerWithTodayTrip();
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $component = Livewire::actingAs($superAdmin)->test(OwnerPerformanceTable::class);
        $component->assertOk()
            ->assertSee($owner->name)
            ->assertSee('Rp 120.000')  // omset hari ini
            ->assertSee('Rp 10.000');  // komisi hari ini: 10 mika × Rp1.000
    }
}
