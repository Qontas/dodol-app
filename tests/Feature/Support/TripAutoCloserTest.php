<?php

namespace Tests\Feature\Support;

use App\Http\Controllers\Owner\MonthlyReportController;
use App\Models\Cluster;
use App\Models\Commission;
use App\Models\Delivery;
use App\Models\Kiosk;
use App\Models\KioskVisit;
use App\Models\ProductVariant;
use App\Models\Trip;
use App\Models\TripStockAddition;
use App\Models\User;
use App\Support\AppFeeCalculator;
use App\Support\TripAutoCloser;
use App\Support\TripCloser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * BRIEF 27 Okt 2026 "TRIP LUPA DIAKHIRI" — App\Support\TripAutoCloser (item 2)
 * & App\Support\TripCloser (item 1, "SATU JALUR AKHIRI TRIP").
 *
 * BRIEF lanjutan "SYARAT IDLE MINIMAL" — tutup otomatis sekarang juga menuntut
 * trip tidak aktif LEBIH dari config('trips.auto_close_idle_hours') jam
 * (default 6), SELAIN trip_date < hari ini. Test di bawah pakai
 * Carbon::setTestNow() supaya "sekarang" bisa dikontrol presisi ke menit
 * (skenario lewat tengah malam) — dikembalikan di tearDown() apapun hasilnya.
 */
class TripAutoCloserTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function owner(): User
    {
        return User::factory()->create([
            'role' => 'owner', 'is_active' => true,
            'hpp_per_mika' => 9500, 'komisi_per_mika' => 1000,
        ]);
    }

    private function operator(User $owner, string $name = 'Operator'): User
    {
        return User::factory()->create([
            'role' => 'operator', 'is_active' => true, 'name' => $name, 'owner_id' => $owner->id,
        ]);
    }

    /**
     * staleQuery() hanya menangkap trip NYATA (bukan sentinel migrasi), BELUM
     * diakhiri, dan trip_date-nya SUDAH LEWAT — bukan trip hari ini, bukan trip
     * yang sudah diakhiri, bukan trip terarsip (SoftDeletes Trip menyembunyikannya
     * otomatis dari semua query model).
     */
    public function test_stale_query_matches_only_past_dated_unended_real_trips(): void
    {
        $owner = $this->owner();
        $operator = $this->operator($owner);
        $cluster = Cluster::create(['name' => 'C', 'owner_id' => $owner->id]);

        $stale = Trip::factory()->create([
            'owner_id' => $owner->id, 'operator_id' => $operator->id,
            'starting_cluster_id' => $cluster->id,
            'trip_date' => today()->subDays(3), 'trip_number_of_day' => 1,
            'started_at' => now()->subDays(3), 'ended_at' => null,
        ]);

        // Trip hari ini belum diakhiri — BUKAN stale.
        Trip::factory()->create([
            'owner_id' => $owner->id, 'operator_id' => $operator->id,
            'starting_cluster_id' => $cluster->id,
            'trip_date' => today(), 'trip_number_of_day' => 1,
            'started_at' => now(), 'ended_at' => null,
        ]);

        // Trip kemarin tapi SUDAH diakhiri — BUKAN stale.
        Trip::factory()->create([
            'owner_id' => $owner->id, 'operator_id' => $operator->id,
            'starting_cluster_id' => $cluster->id,
            'trip_date' => today()->subDay(), 'trip_number_of_day' => 2,
            'started_at' => now()->subDay(), 'ended_at' => now()->subDay(),
        ]);

        // Trip kemarin, belum diakhiri, TAPI operator sistem migrasi — BUKAN stale.
        $sysOperator = $this->operator($owner, 'Sistem');
        $sysOperator->forceFill(['email' => User::SYSTEM_OPERATOR_EMAIL_PREFIX.'x@example.test'])->save();
        Trip::factory()->create([
            'owner_id' => $owner->id, 'operator_id' => $sysOperator->id,
            'starting_cluster_id' => $cluster->id,
            'trip_date' => today()->subDay(), 'trip_number_of_day' => 3,
            'started_at' => now()->subDay(), 'ended_at' => null,
        ]);

        // Trip kemarin, belum diakhiri, TAPI sudah diarsip — BUKAN stale (SoftDeletes).
        $archived = Trip::factory()->create([
            'owner_id' => $owner->id, 'operator_id' => $operator->id,
            'starting_cluster_id' => $cluster->id,
            'trip_date' => today()->subDay(), 'trip_number_of_day' => 4,
            'started_at' => now()->subDay(), 'ended_at' => null,
        ]);
        $archived->delete();

        $ids = TripAutoCloser::staleQuery()->pluck('id')->all();

        $this->assertSame([$stale->id], $ids);
    }

    /**
     * ended_at trip yang ditutup otomatis = waktu aktivitas TERAKHIR (kunjungan/
     * delivery/tambah stok), BUKAN "sekarang" — supaya laporan tidak salah
     * menampilkan trip itu "berakhir" di jam saat admin kebetulan membuka halaman.
     */
    public function test_closing_stale_trip_uses_latest_activity_timestamp_as_ended_at(): void
    {
        $owner = $this->owner();
        $operator = $this->operator($owner);
        $cluster = Cluster::create(['name' => 'C', 'owner_id' => $owner->id]);
        $kiosk = Kiosk::factory()->create(['cluster_id' => $cluster->id]);

        $trip = Trip::factory()->create([
            'owner_id' => $owner->id, 'operator_id' => $operator->id,
            'starting_cluster_id' => $cluster->id,
            'trip_date' => today()->subDays(2), 'trip_number_of_day' => 1,
            'started_at' => now()->subDays(2)->setTime(8, 0), 'ended_at' => null,
        ]);

        $lastVisit = now()->subDays(2)->setTime(10, 0);
        $lastStockAddition = now()->subDays(2)->setTime(14, 30); // paling akhir
        $lastDelivery = now()->subDays(2)->setTime(11, 0);

        KioskVisit::create([
            'trip_id' => $trip->id, 'kiosk_id' => $kiosk->id, 'visited_at' => $lastVisit,
            'visit_action' => 'drop_only', 'extension_granted' => false,
        ]);
        $variant = ProductVariant::factory()->create(['is_active' => true]);
        // created_at TIDAK fillable (timestamp Eloquent biasa) — set manual lewat
        // forceFill()->save() setelah create(), bukan lewat array factory.
        Delivery::factory()->create([
            'trip_id' => $trip->id, 'kiosk_id' => $kiosk->id, 'product_variant_id' => $variant->id,
            'qty_delivered' => 5,
        ])->forceFill(['created_at' => $lastDelivery])->save();
        TripStockAddition::create([
            'trip_id' => $trip->id, 'qty' => 20, 'added_by' => $operator->id,
        ])->forceFill(['created_at' => $lastStockAddition])->save();

        $closed = TripAutoCloser::closeStaleTrip($trip);
        $trip->refresh();

        $this->assertTrue($closed);
        $this->assertSame('auto_closed', $trip->ended_reason);
        $this->assertNotNull($trip->ended_at);
        $this->assertTrue($trip->ended_at->equalTo($lastStockAddition), 'ended_at harus sama dengan aktivitas TERAKHIR (tambah stok 14:30), bukan now().');
    }

    /** Tanpa aktivitas apa pun, ended_at jatuh ke started_at. */
    public function test_closing_stale_trip_without_activity_falls_back_to_started_at(): void
    {
        $owner = $this->owner();
        $operator = $this->operator($owner);
        $cluster = Cluster::create(['name' => 'C', 'owner_id' => $owner->id]);

        $startedAt = now()->subDays(2)->setTime(9, 15);
        $trip = Trip::factory()->create([
            'owner_id' => $owner->id, 'operator_id' => $operator->id,
            'starting_cluster_id' => $cluster->id,
            'trip_date' => today()->subDays(2), 'trip_number_of_day' => 1,
            'started_at' => $startedAt, 'ended_at' => null,
        ]);

        TripAutoCloser::closeStaleTrip($trip);
        $trip->refresh();

        $this->assertTrue($trip->ended_at->equalTo($startedAt));
    }

    /**
     * Idempotent — "SATU JALUR AKHIRI TRIP" (item 1): trip yang sudah berakhir
     * tidak diproses ulang, apapun jalurnya (tombol manual atau otomatis).
     */
    public function test_closing_an_already_ended_trip_is_a_no_op(): void
    {
        $owner = $this->owner();
        $operator = $this->operator($owner);
        $cluster = Cluster::create(['name' => 'C', 'owner_id' => $owner->id]);

        $trip = Trip::factory()->create([
            'owner_id' => $owner->id, 'operator_id' => $operator->id,
            'starting_cluster_id' => $cluster->id,
            'trip_date' => today()->subDay(), 'trip_number_of_day' => 1,
            'started_at' => now()->subDay(), 'ended_at' => null,
        ]);

        // Trip diakhiri MANUAL (jalur tombol "Akhiri Trip") lewat TripCloser —
        // satu-satunya cara baris commissions tercipta.
        TripCloser::close($trip, 'target_done');
        $trip->refresh();
        $this->assertSame(1, Commission::where('trip_id', $trip->id)->count());

        $result = TripCloser::close($trip, 'auto_closed');
        $trip->refresh();

        $this->assertFalse($result, 'TripCloser::close() harus menolak trip yang sudah berakhir.');
        $this->assertSame('target_done', $trip->ended_reason, 'ended_reason asli tidak boleh tertimpa.');
        $this->assertSame(1, Commission::where('trip_id', $trip->id)->count(), 'Tidak boleh ada baris commissions ganda.');
    }

    /** Isolasi tenant: menutup trip stale operator A tidak menyentuh trip operator B. */
    public function test_closing_stale_trips_for_one_operator_does_not_touch_another(): void
    {
        $owner = $this->owner();
        $operatorA = $this->operator($owner, 'A');
        $operatorB = $this->operator($owner, 'B');
        $cluster = Cluster::create(['name' => 'C', 'owner_id' => $owner->id]);

        $tripA = Trip::factory()->create([
            'owner_id' => $owner->id, 'operator_id' => $operatorA->id, 'starting_cluster_id' => $cluster->id,
            'trip_date' => today()->subDay(), 'trip_number_of_day' => 1,
            'started_at' => now()->subDay(), 'ended_at' => null,
        ]);
        $tripB = Trip::factory()->create([
            'owner_id' => $owner->id, 'operator_id' => $operatorB->id, 'starting_cluster_id' => $cluster->id,
            // trip_number_of_day beda dari tripA — unik per (owner_id, trip_date,
            // trip_number_of_day), BUKAN per operator.
            'trip_date' => today()->subDay(), 'trip_number_of_day' => 2,
            'started_at' => now()->subDay(), 'ended_at' => null,
        ]);

        $closed = TripAutoCloser::closeStaleForOperator($operatorA->id);

        $this->assertSame(1, $closed);
        $this->assertNotNull($tripA->refresh()->ended_at);
        $this->assertNull($tripB->refresh()->ended_at, 'Trip operator B tidak boleh ikut ditutup saat memproses operator A.');
    }

    /**
     * BRIEF item 2 "Ringan: satu query cek, tidak boleh memperlambat halaman" —
     * kasus umum (tidak ada trip stale) hanya menambah SATU query (cek stale),
     * tanpa query tambahan untuk menutup apa pun.
     */
    public function test_closing_stale_trips_is_a_single_query_when_nothing_is_stale(): void
    {
        $owner = $this->owner();
        $operator = $this->operator($owner);
        $cluster = Cluster::create(['name' => 'C', 'owner_id' => $owner->id]);

        // Trip hari ini, belum diakhiri — TIDAK stale, tidak boleh memicu query tambahan.
        Trip::factory()->create([
            'owner_id' => $owner->id, 'operator_id' => $operator->id, 'starting_cluster_id' => $cluster->id,
            'trip_date' => today(), 'trip_number_of_day' => 1,
            'started_at' => now(), 'ended_at' => null,
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $closed = TripAutoCloser::closeStaleForOperator($operator->id);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(0, $closed);
        $this->assertSame(1, $count, "Kasus tanpa trip stale harus TEPAT 1 query (cek stale), didapat {$count}.");
    }

    /**
     * BRIEF item 5 — setelah trip lama ditutup otomatis, angkanya muncul di
     * Laporan Bulanan bulan trip itu (MonthlyReportController hanya menghitung
     * trip ->whereNotNull('ended_at')).
     */
    public function test_auto_closed_trip_appears_in_monthly_report_for_its_month(): void
    {
        $owner = $this->owner();
        $operator = $this->operator($owner);
        $cluster = Cluster::create(['name' => 'C', 'owner_id' => $owner->id]);

        // Trip bulan lalu, belum diakhiri — "menggantung", tidak masuk laporan.
        $lastMonth = today()->subMonthNoOverflow()->startOfMonth()->addDays(5);
        $trip = Trip::factory()->create([
            'owner_id' => $owner->id, 'operator_id' => $operator->id, 'starting_cluster_id' => $cluster->id,
            'trip_date' => $lastMonth->toDateString(), 'trip_number_of_day' => 1,
            'started_at' => $lastMonth->copy()->setTime(8, 0), 'ended_at' => null,
            'qty_carried_total' => 50,
        ]);

        $before = MonthlyReportController::buildMonthlyData($lastMonth->format('Y-m'), $owner->id);
        $this->assertCount(0, $before['trips'], 'Sebelum ditutup, trip menggantung tidak boleh muncul di laporan bulanan.');

        TripAutoCloser::closeStaleTrip($trip);

        $after = MonthlyReportController::buildMonthlyData($lastMonth->format('Y-m'), $owner->id);
        $this->assertCount(1, $after['trips'], 'Setelah ditutup otomatis, trip harus muncul di laporan bulan trip itu sendiri.');
        $this->assertSame($trip->id, $after['trips'][0]->id);
    }

    /**
     * BRIEF item 5 — "fee aplikasi TIDAK berubah" karena AppFeeCalculator SUDAH
     * menghitung deliveries dari trip berjalan (tanpa filter ended_at) sejak
     * awal; menutup trip hanya mengubah ended_at/ended_reason + baris commissions,
     * tidak mengubah deliveries sama sekali.
     */
    public function test_app_fee_total_does_not_change_when_a_stale_trip_is_closed(): void
    {
        $owner = $this->owner();
        $owner->forceFill(['app_fee_per_pack' => 200])->save();
        $operator = $this->operator($owner);
        $cluster = Cluster::create(['name' => 'C', 'owner_id' => $owner->id]);
        $kiosk = Kiosk::factory()->create(['cluster_id' => $cluster->id]);
        $variant = ProductVariant::factory()->create(['is_active' => true]);

        $trip = Trip::factory()->create([
            'owner_id' => $owner->id, 'operator_id' => $operator->id, 'starting_cluster_id' => $cluster->id,
            'trip_date' => today()->subDays(3), 'trip_number_of_day' => 1,
            'started_at' => now()->subDays(3), 'ended_at' => null,
            'app_fee_per_pack_snapshot' => 200,
        ]);
        Delivery::factory()->create([
            'trip_id' => $trip->id, 'kiosk_id' => $kiosk->id, 'product_variant_id' => $variant->id,
            'qty_delivered' => 7, 'delivery_type' => 'consignment',
        ]);

        $before = AppFeeCalculator::summaryForOwners()[$owner->id];

        TripAutoCloser::closeStaleTrip($trip);

        $after = AppFeeCalculator::summaryForOwners()[$owner->id];

        $this->assertSame($before['mika_total'], $after['mika_total']);
        $this->assertEqualsWithDelta($before['fee_total'], $after['fee_total'], 0.0001);
    }

    /**
     * BRIEF lanjutan skenario (a) — aktivitas 23:50, halaman dibuka 00:20 (trip_date
     * sudah "kemarin"). Masih idle < 6 jam (default) → TIDAK ditutup, walau syarat
     * tanggal (trip_date < hari ini) sudah terpenuhi.
     */
    public function test_trip_still_idle_within_default_window_across_midnight_is_not_closed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-28 00:20:00'));

        $owner = $this->owner();
        $operator = $this->operator($owner);
        $cluster = Cluster::create(['name' => 'C', 'owner_id' => $owner->id]);
        $kiosk = Kiosk::factory()->create(['cluster_id' => $cluster->id]);

        $trip = Trip::factory()->create([
            'owner_id' => $owner->id, 'operator_id' => $operator->id,
            'starting_cluster_id' => $cluster->id,
            'trip_date' => '2026-10-27', 'trip_number_of_day' => 1,
            'started_at' => Carbon::parse('2026-10-27 18:00:00'), 'ended_at' => null,
        ]);
        KioskVisit::create([
            'trip_id' => $trip->id, 'kiosk_id' => $kiosk->id,
            'visited_at' => Carbon::parse('2026-10-27 23:50:00'),
            'visit_action' => 'drop_only', 'extension_granted' => false,
        ]);

        $closed = TripAutoCloser::closeStaleForOperator($operator->id);

        $this->assertSame(0, $closed);
        $this->assertNull($trip->refresh()->ended_at, 'Trip yang aktivitas terakhirnya belum 6 jam tidak boleh ditutup.');

        // Operator TETAP bisa titip (kunjungan baru) & akhiri trip di trip yang sama.
        KioskVisit::create([
            'trip_id' => $trip->id, 'kiosk_id' => $kiosk->id,
            'visited_at' => Carbon::parse('2026-10-28 00:15:00'),
            'visit_action' => 'drop_only', 'extension_granted' => false,
        ]);
        $this->assertTrue(TripCloser::close($trip, 'target_done'));
        $this->assertNotNull($trip->refresh()->ended_at);
    }

    /**
     * BRIEF lanjutan skenario (b) — aktivitas terakhir jam 02:00, halaman dibuka
     * 09:00 (idle 7 jam > 6 jam) → ditutup, ended_at = 02:00 (aktivitas terakhir),
     * BUKAN 09:00 (jam dicek).
     */
    public function test_trip_idle_past_default_threshold_across_midnight_is_closed_with_last_activity_as_ended_at(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-28 09:00:00'));

        $owner = $this->owner();
        $operator = $this->operator($owner);
        $cluster = Cluster::create(['name' => 'C', 'owner_id' => $owner->id]);
        $kiosk = Kiosk::factory()->create(['cluster_id' => $cluster->id]);

        $trip = Trip::factory()->create([
            'owner_id' => $owner->id, 'operator_id' => $operator->id,
            'starting_cluster_id' => $cluster->id,
            'trip_date' => '2026-10-27', 'trip_number_of_day' => 1,
            'started_at' => Carbon::parse('2026-10-27 18:00:00'), 'ended_at' => null,
        ]);
        $lastActivity = Carbon::parse('2026-10-28 02:00:00');
        KioskVisit::create([
            'trip_id' => $trip->id, 'kiosk_id' => $kiosk->id,
            'visited_at' => $lastActivity,
            'visit_action' => 'drop_only', 'extension_granted' => false,
        ]);

        $closed = TripAutoCloser::closeStaleForOperator($operator->id);

        $this->assertSame(1, $closed);
        $trip->refresh();
        $this->assertNotNull($trip->ended_at);
        $this->assertSame('auto_closed', $trip->ended_reason);
        $this->assertTrue($trip->ended_at->equalTo($lastActivity), 'ended_at harus sama dengan aktivitas terakhir (02:00), bukan jam saat dicek (09:00).');
    }

    /**
     * BRIEF lanjutan skenario (c) — aktivitas terakhir 17:36, halaman dibuka besok
     * 08:00 (idle ~14 jam > 6 jam) → ditutup.
     */
    public function test_trip_idle_overnight_without_crossing_near_midnight_is_closed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-28 08:00:00'));

        $owner = $this->owner();
        $operator = $this->operator($owner);
        $cluster = Cluster::create(['name' => 'C', 'owner_id' => $owner->id]);

        $startedAt = Carbon::parse('2026-10-27 17:36:00');
        $trip = Trip::factory()->create([
            'owner_id' => $owner->id, 'operator_id' => $operator->id,
            'starting_cluster_id' => $cluster->id,
            'trip_date' => '2026-10-27', 'trip_number_of_day' => 1,
            'started_at' => $startedAt, 'ended_at' => null,
        ]);

        $closed = TripAutoCloser::closeStaleForOperator($operator->id);

        $this->assertSame(1, $closed);
        $this->assertTrue($trip->refresh()->ended_at->equalTo($startedAt));
    }

    /** BRIEF lanjutan skenario (e) — ambang N jam bisa diubah lewat config (env). */
    public function test_idle_threshold_is_configurable_via_config(): void
    {
        Config::set('trips.auto_close_idle_hours', 1);
        $this->assertSame(1, TripAutoCloser::idleHoursThreshold());

        Carbon::setTestNow(Carbon::parse('2026-10-28 00:20:00'));

        $owner = $this->owner();
        $operator = $this->operator($owner);
        $cluster = Cluster::create(['name' => 'C', 'owner_id' => $owner->id]);
        $kiosk = Kiosk::factory()->create(['cluster_id' => $cluster->id]);

        // Aktivitas terakhir 23:50 — idle 30 menit. Dengan ambang default (6 jam)
        // TIDAK ditutup, tapi dengan ambang 1 jam juga belum (30 menit < 1 jam).
        $trip = Trip::factory()->create([
            'owner_id' => $owner->id, 'operator_id' => $operator->id,
            'starting_cluster_id' => $cluster->id,
            'trip_date' => '2026-10-27', 'trip_number_of_day' => 1,
            'started_at' => Carbon::parse('2026-10-27 18:00:00'), 'ended_at' => null,
        ]);
        KioskVisit::create([
            'trip_id' => $trip->id, 'kiosk_id' => $kiosk->id,
            'visited_at' => Carbon::parse('2026-10-27 23:50:00'),
            'visit_action' => 'drop_only', 'extension_granted' => false,
        ]);

        $this->assertSame(0, TripAutoCloser::closeStaleForOperator($operator->id));
        $this->assertNull($trip->refresh()->ended_at);

        // Majukan "sekarang" melewati ambang 1 jam sejak aktivitas terakhir (23:50 + 1 jam = 00:50).
        Carbon::setTestNow(Carbon::parse('2026-10-28 01:00:00'));

        $this->assertSame(1, TripAutoCloser::closeStaleForOperator($operator->id));
        $this->assertNotNull($trip->refresh()->ended_at);
    }

    /** trip hari ini (trip_date = hari ini) tidak pernah masuk kandidat, berapa pun idle-nya. */
    public function test_trip_from_today_is_never_auto_closed_regardless_of_idle_hours(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-28 23:59:00'));

        $owner = $this->owner();
        $operator = $this->operator($owner);
        $cluster = Cluster::create(['name' => 'C', 'owner_id' => $owner->id]);

        $trip = Trip::factory()->create([
            'owner_id' => $owner->id, 'operator_id' => $operator->id,
            'starting_cluster_id' => $cluster->id,
            'trip_date' => '2026-10-28', 'trip_number_of_day' => 1,
            'started_at' => Carbon::parse('2026-10-28 06:00:00'), 'ended_at' => null,
        ]);

        $this->assertSame(0, TripAutoCloser::closeStaleForOperator($operator->id));
        $this->assertNull($trip->refresh()->ended_at);
    }

    /** idleInfo() melaporkan aktivitas terakhir & jam idle yang konsisten dengan closeStaleTrip(). */
    public function test_idle_info_reports_last_activity_and_idle_hours(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-28 09:30:00'));

        $owner = $this->owner();
        $operator = $this->operator($owner);
        $cluster = Cluster::create(['name' => 'C', 'owner_id' => $owner->id]);

        $trip = Trip::factory()->create([
            'owner_id' => $owner->id, 'operator_id' => $operator->id,
            'starting_cluster_id' => $cluster->id,
            'trip_date' => '2026-10-27', 'trip_number_of_day' => 1,
            'started_at' => Carbon::parse('2026-10-28 02:00:00'), 'ended_at' => null,
        ]);

        $info = TripAutoCloser::idleInfo($trip);

        $this->assertTrue($info['last_activity_at']->equalTo(Carbon::parse('2026-10-28 02:00:00')));
        $this->assertSame(7.5, $info['idle_hours']);
        $this->assertTrue($info['is_idle_long_enough']);
    }
}
