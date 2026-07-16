<?php

namespace Tests\Feature\Owner;

use App\Http\Controllers\Owner\MonthlyReportController;
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

/**
 * Halaman "Riwayat Trip" owner (/owner/trips): daftar SEMUA trip selesai paginated +
 * filter operator/tanggal/status + detail per-trip + kelola arsip (pulihkan). WAJIB:
 *   - paginated, terbaru dulu, jumlah query KONSTAN (bukan N+1),
 *   - tiap filter & kombinasinya benar di server,
 *   - detail: ringkasan finansial + daftar kunjungan; export tersambung; 403 tenant,
 *   - restore dari UI mengembalikan angka ke laporan,
 *   - tenant isolation (owner A tak lihat/buka trip owner B),
 *   - murni tampilan: angka trip aktif tak berubah.
 */
class TripHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected User $operator;
    protected Cluster $cluster;
    protected ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create([
            'role' => 'owner', 'is_active' => true,
            'hpp_per_mika' => 9500, 'komisi_per_mika' => 1000,
        ]);
        $this->operator = User::factory()->create([
            'role' => 'operator', 'is_active' => true,
            'name' => 'Rian', 'owner_id' => $this->owner->id,
        ]);
        $this->cluster = Cluster::create(['name' => 'Cluster Riwayat', 'owner_id' => $this->owner->id]);
        $this->variant = ProductVariant::factory()->create([
            'is_active' => true, 'sale_price_per_pack' => 12000,
        ]);
    }

    private function endedTrip($date, int $number = 1, ?User $operator = null, ?User $owner = null, ?Cluster $cluster = null): Trip
    {
        $owner ??= $this->owner;
        $operator ??= $this->operator;
        $cluster ??= $this->cluster;

        return Trip::factory()->create([
            'owner_id' => $owner->id,
            'operator_id' => $operator->id,
            'starting_cluster_id' => $cluster->id,
            'trip_date' => \Illuminate\Support\Carbon::parse($date)->format('Y-m-d'),
            'trip_number_of_day' => $number,
            'started_at' => \Illuminate\Support\Carbon::parse($date),
            'ended_at' => \Illuminate\Support\Carbon::parse($date),
            'qty_carried_total' => 75,
        ]);
    }

    private function drop(Trip $trip, Kiosk $kiosk, int $mika, string $type = 'consignment'): Delivery
    {
        return Delivery::factory()->create([
            'kiosk_id' => $kiosk->id, 'trip_id' => $trip->id,
            'qty_delivered' => $mika, 'product_variant_id' => $this->variant->id,
            'delivery_type' => $type,
        ]);
    }

    private function dropAndSettle(Trip $trip, Kiosk $kiosk, int $mika, int $qtySold, int $amountPaid, $visitDate): Delivery
    {
        $delivery = $this->drop($trip, $kiosk, $mika);
        Settlement::create([
            'delivery_id' => $delivery->id,
            'visit_date' => \Illuminate\Support\Carbon::parse($visitDate)->format('Y-m-d'),
            'qty_sold' => $qtySold, 'qty_returned_fresh' => 0, 'qty_returned_expired' => 0,
            'amount_due' => $amountPaid, 'amount_paid' => $amountPaid, 'status' => 'paid',
        ]);
        KioskVisit::create([
            'trip_id' => $trip->id, 'kiosk_id' => $kiosk->id,
            'visited_at' => \Illuminate\Support\Carbon::parse($visitDate),
            'visit_action' => 'drop_and_settle',
            'new_delivery_id' => $delivery->id, 'settled_delivery_id' => $delivery->id,
            'extension_granted' => false,
        ]);

        return $delivery;
    }

    public function test_list_shows_all_ended_trips_paginated_newest_first(): void
    {
        // 22 trip selesai (2 halaman @ 20) + 1 trip BELUM selesai (tak boleh muncul).
        for ($i = 1; $i <= 22; $i++) {
            $this->endedTrip(now()->subDays($i), $i);
        }
        Trip::factory()->create([
            'owner_id' => $this->owner->id, 'operator_id' => $this->operator->id,
            'starting_cluster_id' => $this->cluster->id, 'trip_date' => today()->format('Y-m-d'),
            'trip_number_of_day' => 99, 'started_at' => now(), 'ended_at' => null,
        ]);

        $response = $this->actingAs($this->owner)->get('/owner/trips');
        $response->assertOk();

        $trips = $response->viewData('trips');
        $this->assertSame(20, $trips->count());       // 20 per halaman
        $this->assertSame(22, $trips->total());        // total hanya trip SELESAI

        // Terbaru dulu: item pertama ended_at paling baru.
        $items = collect($trips->items());
        $this->assertTrue($items->first()->ended_at->greaterThanOrEqualTo($items->last()->ended_at));

        // Halaman 2 → sisa 2.
        $page2 = $this->actingAs($this->owner)->get('/owner/trips?page=2');
        $this->assertSame(2, $page2->viewData('trips')->count());
    }

    public function test_list_query_count_bounded_no_n_plus_1(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id]);
        for ($i = 1; $i <= 20; $i++) {
            $trip = $this->endedTrip(now()->subDays($i), $i);
            $this->dropAndSettle($trip, $kiosk, 10, 150, 120000, now()->subDays($i));
            $this->drop($trip, $kiosk, 3, 'cash_sale');
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->owner)->get('/owner/trips')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        // 20 baris. Kalau agregat per-baris (accessor ~5 query/baris) → 100+.
        // Batch → belasan query konstan. Ambang longgar tapi bermakna.
        $this->assertLessThan(30, $count, "Query count {$count} terlalu tinggi — indikasi N+1.");
    }

    public function test_filter_by_operator(): void
    {
        $operator2 = User::factory()->create(['role' => 'operator', 'is_active' => true, 'name' => 'Budi', 'owner_id' => $this->owner->id]);

        $this->endedTrip(now()->subDays(1), 1, $this->operator);
        $this->endedTrip(now()->subDays(2), 2, $operator2);

        $response = $this->actingAs($this->owner)->get('/owner/trips?operator_id='.$operator2->id);
        $trips = $response->viewData('trips');

        $this->assertSame(1, $trips->total());
        $this->assertSame($operator2->id, $trips->first()->operator_id);
    }

    public function test_filter_by_date_range(): void
    {
        $this->endedTrip('2026-06-10', 1);
        $this->endedTrip('2026-06-20', 2);
        $this->endedTrip('2026-07-05', 3);

        $response = $this->actingAs($this->owner)->get('/owner/trips?from=2026-06-15&to=2026-06-30');
        $trips = $response->viewData('trips');

        $this->assertSame(1, $trips->total());
        $this->assertSame('2026-06-20', $trips->first()->trip_date->format('Y-m-d'));
    }

    public function test_filter_by_status_archived_active_all(): void
    {
        $active = $this->endedTrip(now()->subDays(1), 1);
        $archived = $this->endedTrip(now()->subDays(2), 2);
        $archived->delete(); // arsip

        // Default (aktif) → hanya trip aktif.
        $aktif = $this->actingAs($this->owner)->get('/owner/trips')->viewData('trips');
        $this->assertSame(1, $aktif->total());
        $this->assertSame($active->id, $aktif->first()->id);

        // Diarsip → hanya yang terarsip.
        $diarsip = $this->actingAs($this->owner)->get('/owner/trips?status=diarsip')->viewData('trips');
        $this->assertSame(1, $diarsip->total());
        $this->assertSame($archived->id, $diarsip->first()->id);

        // Semua → dua-duanya.
        $semua = $this->actingAs($this->owner)->get('/owner/trips?status=semua')->viewData('trips');
        $this->assertSame(2, $semua->total());
    }

    public function test_combined_filters(): void
    {
        $operator2 = User::factory()->create(['role' => 'operator', 'is_active' => true, 'name' => 'Budi', 'owner_id' => $this->owner->id]);

        // Target: operator2, dalam rentang, terarsip.
        $target = $this->endedTrip('2026-06-20', 1, $operator2);
        $target->delete();
        // Distraktor: operator2 di luar rentang.
        $this->endedTrip('2026-07-20', 2, $operator2)->delete();
        // Distraktor: operator1 dalam rentang, terarsip.
        $this->endedTrip('2026-06-21', 3, $this->operator)->delete();

        $response = $this->actingAs($this->owner)
            ->get('/owner/trips?status=diarsip&operator_id='.$operator2->id.'&from=2026-06-01&to=2026-06-30');
        $trips = $response->viewData('trips');

        $this->assertSame(1, $trips->total());
        $this->assertSame($target->id, $trips->first()->id);
    }

    public function test_list_aggregates_match_report_numbers(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id]);
        $trip = $this->endedTrip(today(), 1);
        $this->dropAndSettle($trip, $kiosk, 10, 150, 120000, today());
        $this->drop($trip, $kiosk, 3, 'cash_sale');
        $this->drop($trip, $kiosk, 2, 'bs_redistribution');

        $agg = $this->actingAs($this->owner)->get('/owner/trips')->viewData('aggregates')[$trip->id];

        // Identik accessor Trip: omset 120.000, komisi (10+3)×1.000, untung_bersih 12.000.
        $this->assertEqualsWithDelta(120000, $agg['omset'], 0.01);
        $this->assertEqualsWithDelta(13000, $agg['komisi'], 0.01);
        $this->assertEqualsWithDelta(12000, $agg['untung_bersih'], 0.01);
        $this->assertSame(15, $agg['mika_diantar']); // 10+3+2 (semua drop)

        // Cocok dengan accessor asli (tak berubah oleh fitur ini).
        $trip->refresh();
        $this->assertEqualsWithDelta($trip->omset_val, $agg['omset'], 0.01);
        $this->assertEqualsWithDelta($trip->komisi_rian, $agg['komisi'], 0.01);
        $this->assertEqualsWithDelta($trip->untung_bersih_owner, $agg['untung_bersih'], 0.01);
    }

    public function test_detail_shows_financials_and_visits(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id, 'name' => 'Kios Mawar']);
        $trip = $this->endedTrip(today(), 1);
        $this->dropAndSettle($trip, $kiosk, 10, 150, 120000, today());

        $response = $this->actingAs($this->owner)->get(route('owner.trips.show', $trip));
        $response->assertOk();

        $response->assertSee('Rp 120.000');    // omset
        $response->assertSee('Kios Mawar');    // baris kunjungan
        $response->assertSee('Tagih + Titip Baru'); // label aksi
        $this->assertCount(1, $response->viewData('visitRows'));

        // Export tersambung.
        $response->assertSee(route('owner.trips.export.pdf', $trip), false);
        $response->assertSee(route('owner.trips.export.excel', $trip), false);
    }

    public function test_detail_of_archived_trip_openable(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id]);
        $trip = $this->endedTrip(today(), 1);
        $this->dropAndSettle($trip, $kiosk, 10, 150, 120000, today());
        $trip->delete();

        $this->actingAs($this->owner)
            ->get(route('owner.trips.show', $trip))
            ->assertOk()
            ->assertSee('Diarsip');
    }

    public function test_restore_from_ui_brings_trip_back_to_report(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id]);
        $trip = $this->endedTrip(today(), 1);
        $this->dropAndSettle($trip, $kiosk, 10, 150, 120000, today());
        $trip->delete();

        // Sebelum: laporan kosong.
        $this->assertEqualsWithDelta(0, MonthlyReportController::buildMonthlyData(today()->format('Y-m'), $this->owner->id)['totals']['omset'], 0.01);

        $this->actingAs($this->owner)
            ->post(route('owner.trips.restore', $trip))
            ->assertRedirect();

        // Trip aktif kembali & angka balik.
        $this->assertFalse(Trip::withTrashed()->find($trip->id)->trashed());
        $this->assertEqualsWithDelta(120000, MonthlyReportController::buildMonthlyData(today()->format('Y-m'), $this->owner->id)['totals']['omset'], 0.01);
    }

    public function test_archive_from_ui_hides_trip(): void
    {
        $trip = $this->endedTrip(today(), 1);

        $this->actingAs($this->owner)
            ->delete(route('owner.trips.destroy', $trip))
            ->assertRedirect();

        $this->assertTrue(Trip::withTrashed()->find($trip->id)->trashed());
        // Hilang dari daftar default (aktif).
        $this->assertSame(0, $this->actingAs($this->owner)->get('/owner/trips')->viewData('trips')->total());
    }

    public function test_tenant_isolation_list_and_detail(): void
    {
        // Owner B dengan trip sendiri.
        $ownerB = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $operatorB = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $ownerB->id]);
        $clusterB = Cluster::create(['name' => 'Cluster B', 'owner_id' => $ownerB->id]);
        $tripB = $this->endedTrip(today(), 1, $operatorB, $ownerB, $clusterB);

        // Trip owner A.
        $this->endedTrip(now()->subDay(), 1);

        // List owner A tak memuat trip owner B.
        $list = $this->actingAs($this->owner)->get('/owner/trips')->viewData('trips');
        $this->assertSame(1, $list->total());
        $this->assertFalse(collect($list->items())->contains(fn ($t) => $t->id === $tripB->id));

        // Owner A tak bisa buka detail trip owner B (403/404 via OwnerScope).
        $response = $this->actingAs($this->owner)->get(route('owner.trips.show', $tripB));
        $this->assertContains($response->status(), [403, 404]);
    }

    public function test_non_owner_cannot_restore(): void
    {
        $trip = $this->endedTrip(today(), 1);
        $trip->delete();

        $intruder = User::factory()->create(['role' => 'owner', 'is_active' => true]);

        $response = $this->actingAs($intruder)->post(route('owner.trips.restore', $trip));
        $this->assertContains($response->status(), [403, 404]);
        // Tetap terarsip — cek via DB langsung (OwnerScope menyembunyikan dari intruder).
        $this->assertNotNull(DB::table('trips')->where('id', $trip->id)->value('deleted_at'));
    }
}
