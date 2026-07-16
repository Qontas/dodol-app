<?php

namespace Tests\Feature\Owner;

use App\Http\Controllers\Owner\MonthlyReportController;
use App\Models\Cluster;
use App\Models\Commission;
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
 * ARSIP trip (soft delete): tombol "Arsipkan" di dashboard hanya MENYEMBUNYIKAN trip
 * dari laporan/agregat (deleted_at terisi), TANPA menghapus data anak secara fisik &
 * bisa dipulihkan. WAJIB:
 *   - trip terarsip HILANG dari laporan bulanan + agregat omset/untung/komisi + dashboard,
 *   - trip AKTIF (tak diarsip) angkanya PERSIS sama seperti sebelum ada arsip,
 *   - data anak (kiosk_visits, deliveries, settlements, commissions) TIDAK terhapus fisik,
 *   - restore mengembalikan trip ke laporan,
 *   - guard non-owner tetap menolak.
 */
class TripSoftDeleteTest extends TestCase
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
        $this->cluster = Cluster::create(['name' => 'Cluster Arsip', 'owner_id' => $this->owner->id]);
        $this->variant = ProductVariant::factory()->create([
            'is_active' => true, 'sale_price_per_pack' => 12000,
        ]);
    }

    private function endedTrip($date, int $number = 1): Trip
    {
        return Trip::factory()->create([
            'owner_id' => $this->owner->id,
            'operator_id' => $this->operator->id,
            'starting_cluster_id' => $this->cluster->id,
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

    /** ⚠️ KRITIS: mengarsip trip B tidak boleh mengubah angka trip A yang masih aktif. */
    public function test_trip_aktif_angkanya_tidak_berubah_setelah_trip_lain_diarsip(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id]);

        // Trip A (dipertahankan) + trip B (akan diarsip), dua-duanya bulan ini.
        $tripA = $this->endedTrip(today(), 1);
        $this->dropAndSettle($tripA, $kiosk, 10, 150, 120000, today());
        $this->drop($tripA, $kiosk, 3, 'cash_sale');

        $tripB = $this->endedTrip(today(), 2);
        $this->dropAndSettle($tripB, $kiosk, 5, 75, 60000, today());

        // Baseline SEBELUM arsip (kedua trip aktif).
        $before = MonthlyReportController::buildMonthlyData(today()->format('Y-m'), $this->owner->id);
        $this->assertEqualsWithDelta(180000, $before['totals']['omset'], 0.01);

        // Arsip trip B.
        $tripB->delete();

        $after = MonthlyReportController::buildMonthlyData(today()->format('Y-m'), $this->owner->id);

        // Trip A angkanya PERSIS: omset 120.000, komisi (10+3)x1.000 = 13.000,
        // untung_kotor 10x2.500 = 25.000, untung_bersih 12.000.
        $this->assertEqualsWithDelta(120000, $after['totals']['omset'], 0.01);
        $this->assertEqualsWithDelta(13000, $after['totals']['komisi'], 0.01);
        $this->assertEqualsWithDelta(12000, $after['totals']['untung_bersih'], 0.01);

        // Hanya trip A yang tersisa di baris laporan.
        $this->assertCount(1, $after['rows']);

        // Accessor trip A tak tersentuh.
        $tripA->refresh();
        $this->assertEqualsWithDelta(120000, $tripA->omset_val, 0.01);
        $this->assertEqualsWithDelta(13000, $tripA->komisi_rian, 0.01);
    }

    /** Trip terarsip hilang dari laporan bulanan + agregat omset/untung/komisi. */
    public function test_trip_diarsip_hilang_dari_laporan_dan_agregat(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id]);
        $trip = $this->endedTrip(today(), 1);
        $this->dropAndSettle($trip, $kiosk, 10, 150, 120000, today());
        $this->drop($trip, $kiosk, 3, 'cash_sale');

        $trip->delete();

        $report = MonthlyReportController::buildMonthlyData(today()->format('Y-m'), $this->owner->id);

        // Semua agregat yang lewat model / whereHas('trip') → 0.
        $this->assertCount(0, $report['rows']);
        $this->assertEqualsWithDelta(0, $report['totals']['omset'], 0.01);
        $this->assertEqualsWithDelta(0, $report['totals']['komisi'], 0.01);
        $this->assertEqualsWithDelta(0, $report['totals']['untung_bersih'], 0.01);
        $this->assertSame(0, $report['total_mika_diantar']);
        $this->assertEqualsWithDelta(0, $report['rekap_komisi'], 0.01);

        // Dashboard: ringkasan bulan ini & komisi per operator juga 0/kosong.
        $response = $this->actingAs($this->owner)->get('/owner/dashboard');
        $response->assertOk();
        $this->assertEqualsWithDelta(0, $response->viewData('ringkasanBulanIni')['omset'], 0.01);
        $this->assertEqualsWithDelta(0, $response->viewData('ringkasanBulanIni')['komisi'], 0.01);
        $this->assertCount(0, $response->viewData('komisiPerOperator'));
        // Trip terarsip tak muncul di daftar completed trips dashboard.
        $this->assertCount(0, $response->viewData('completedTrips'));
    }

    /** Komisi per-operator (join manual pada tabel trips) mengecualikan trip terarsip. */
    public function test_komisi_per_operator_join_manual_mengecualikan_trip_terarsip(): void
    {
        $operator2 = User::factory()->create(['role' => 'operator', 'is_active' => true, 'name' => 'Budi', 'owner_id' => $this->owner->id]);
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id]);

        $tripRian = $this->endedTrip(today(), 1);
        $this->drop($tripRian, $kiosk, 10, 'cash_sale');

        $tripBudi = $this->endedTrip(today(), 2);
        $this->drop($tripBudi, $kiosk, 4, 'cash_sale');

        // Arsip trip Budi → hanya Rian tersisa di rincian komisi.
        $tripBudi->delete();

        $perOp = $this->actingAs($this->owner)->get('/owner/dashboard')->viewData('komisiPerOperator');

        $this->assertCount(1, $perOp);
        $this->assertSame('Rian', $perOp[0]['operator']);
        $this->assertSame(10000, $perOp[0]['komisi']);
    }

    /** Data anak TIDAK terhapus fisik saat trip diarsip (cukup trip disembunyikan). */
    public function test_data_anak_tidak_terhapus_fisik_saat_arsip(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id]);
        $trip = $this->endedTrip(today(), 1);
        $delivery = $this->dropAndSettle($trip, $kiosk, 10, 150, 120000, today());
        Commission::create([
            'trip_id' => $trip->id, 'operator_id' => $this->operator->id,
            'cash_collected_reported' => 100000.00, 'margin_rate_assumed' => 0.2500,
            'commission_rate' => 0.2000, 'status' => 'paid', 'paid_at' => now(),
        ]);

        $trip->delete();

        // Baris trip masih ADA di DB (hanya deleted_at terisi).
        $this->assertDatabaseHas('trips', ['id' => $trip->id]);
        $this->assertNotNull(Trip::withTrashed()->find($trip->id)->deleted_at);

        // Data anak utuh secara fisik — dihitung TANPA lewat model Trip (raw DB).
        $this->assertSame(1, (int) DB::table('deliveries')->where('trip_id', $trip->id)->count());
        $this->assertSame(1, (int) DB::table('settlements')->where('delivery_id', $delivery->id)->count());
        $this->assertSame(1, (int) DB::table('kiosk_visits')->where('trip_id', $trip->id)->count());
        $this->assertSame(1, (int) DB::table('commissions')->where('trip_id', $trip->id)->count());
    }

    /** Tombol dashboard → SOFT DELETE (arsip), bukan hard delete; pesan pakai "arsip". */
    public function test_tombol_hapus_mengarsipkan_bukan_menghapus_permanen(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id]);
        $trip = $this->endedTrip(today(), 1);
        $delivery = $this->dropAndSettle($trip, $kiosk, 10, 150, 120000, today());

        $response = $this->actingAs($this->owner)
            ->delete(route('owner.trips.destroy', $trip));

        $response->assertRedirect();
        $response->assertSessionHas('status');
        $this->assertStringContainsStringIgnoringCase('arsip', session('status'));

        // Trip diarsip (trashed), TIDAK hilang; data anak utuh.
        $this->assertTrue(Trip::withTrashed()->find($trip->id)->trashed());
        $this->assertSame(1, (int) DB::table('deliveries')->where('trip_id', $trip->id)->count());
        $this->assertSame(1, (int) DB::table('settlements')->where('delivery_id', $delivery->id)->count());
    }

    /** Restore (command artisan) mengembalikan trip ke laporan. */
    public function test_restore_mengembalikan_trip_ke_laporan(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id]);
        $trip = $this->endedTrip(today(), 1);
        $this->dropAndSettle($trip, $kiosk, 10, 150, 120000, today());

        $trip->delete();
        $this->assertEqualsWithDelta(0, MonthlyReportController::buildMonthlyData(today()->format('Y-m'), $this->owner->id)['totals']['omset'], 0.01);

        // Pulihkan via command.
        $this->artisan('trip:restore', ['id' => $trip->id])
            ->assertSuccessful();

        $this->assertFalse(Trip::withTrashed()->find($trip->id)->trashed());

        $report = MonthlyReportController::buildMonthlyData(today()->format('Y-m'), $this->owner->id);
        $this->assertCount(1, $report['rows']);
        $this->assertEqualsWithDelta(120000, $report['totals']['omset'], 0.01);
    }

    /** Guard: owner LAIN tak boleh mengarsip trip milik owner ini. */
    public function test_non_owner_tidak_boleh_mengarsip(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id]);
        $trip = $this->endedTrip(today(), 1);
        $this->dropAndSettle($trip, $kiosk, 10, 150, 120000, today());

        $intruder = User::factory()->create(['role' => 'owner', 'is_active' => true]);

        $response = $this->actingAs($intruder)
            ->delete(route('owner.trips.destroy', $trip));

        // Ditolak (403 abort_unless, atau 404 karena OwnerScope menyembunyikan trip
        // milik owner lain dari route-binding). Yang penting: trip TIDAK diarsip.
        $this->assertContains($response->status(), [403, 404]);
        // Cek via DB langsung (hindari OwnerScope yang memfilter query intruder).
        $this->assertNull(DB::table('trips')->where('id', $trip->id)->value('deleted_at'));
    }
}
