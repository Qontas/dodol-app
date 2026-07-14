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
use Tests\TestCase;

/**
 * Widget "Ringkasan Bulan Ini" di dashboard owner: omset, untung bersih, komisi
 * operator untuk bulan berjalan. Angka HARUS:
 *   - untung bersih = omset − HPP − komisi (komisi benar-benar terpotong),
 *   - identik totals MonthlyReportController (satu sumber kebenaran),
 *   - ter-scope tenant (owner A tak lihat owner B).
 */
class RingkasanBulanIniTest extends TestCase
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
        $this->cluster = Cluster::create(['name' => 'Cluster Ringkasan', 'owner_id' => $this->owner->id]);
        $this->variant = ProductVariant::factory()->create([
            'is_active' => true, 'sale_price_per_pack' => 12000,
        ]);
    }

    private function endedTrip(User $owner, User $operator, Cluster $cluster, $date, int $number = 1): Trip
    {
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

    /** Titip konsinyasi yang ditagih (settle) — menghasilkan omset & mika_terjual. */
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

    public function test_widget_shows_omset_untung_bersih_dan_komisi_bulan_ini(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id]);
        $trip = $this->endedTrip($this->owner, $this->operator, $this->cluster, today());

        // Titipan tertagih: 10 mika (150 biji) laku, dibayar Rp 120.000.
        $this->dropAndSettle($trip, $kiosk, 10, 150, 120000, today());
        // Cash walk-in: 3 mika (drop → masuk komisi, tak menambah omset di sini).
        $this->drop($trip, $kiosk, 3, 'cash_sale');
        // BS daur-ulang: 2 mika → TIDAK dihitung komisi.
        $this->drop($trip, $kiosk, 2, 'bs_redistribution');

        $response = $this->actingAs($this->owner)->get('/owner/dashboard');
        $response->assertOk();

        $r = $response->viewData('ringkasanBulanIni');

        // omset = SUM(amount_paid) = 120.000
        $this->assertEqualsWithDelta(120000, $r['omset'], 0.01);
        // drop komisi = 10 (konsinyasi) + 3 (cash) = 13; BS 2 dikecualikan → 13 x 1.000
        $this->assertEqualsWithDelta(13000, $r['komisi'], 0.01);
        // untung_kotor = 10 mika x (12000-9500) = 25.000
        $this->assertEqualsWithDelta(25000, $r['untung_kotor'], 0.01);
        // untung_bersih = 25.000 - 13.000 = 12.000
        $this->assertEqualsWithDelta(12000, $r['untung_bersih'], 0.01);

        // Terlihat di halaman + label periode bulan berjalan.
        $response->assertSee('Ringkasan Bulan Ini');
        $response->assertSee('Rp 120.000');
        $response->assertSee(today()->format('Y'));
    }

    /** Komisi BENAR-BENAR terpotong: untung_bersih = untung_kotor − komisi. */
    public function test_untung_bersih_adalah_untung_kotor_minus_komisi(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id]);
        $trip = $this->endedTrip($this->owner, $this->operator, $this->cluster, today());
        $this->dropAndSettle($trip, $kiosk, 10, 150, 120000, today());
        $this->drop($trip, $kiosk, 3, 'cash_sale');

        $r = $this->actingAs($this->owner)->get('/owner/dashboard')->viewData('ringkasanBulanIni');

        $this->assertGreaterThan(0, $r['komisi']);
        $this->assertEqualsWithDelta($r['untung_kotor'] - $r['komisi'], $r['untung_bersih'], 0.01);
    }

    /** Konsistensi: angka widget IDENTIK totals MonthlyReportController (satu kebenaran). */
    public function test_widget_konsisten_dengan_laporan_bulanan(): void
    {
        $kioskA = Kiosk::factory()->create(['cluster_id' => $this->cluster->id]);
        $kioskB = Kiosk::factory()->create(['cluster_id' => $this->cluster->id]);

        $trip1 = $this->endedTrip($this->owner, $this->operator, $this->cluster, today(), 1);
        $this->dropAndSettle($trip1, $kioskA, 10, 150, 120000, today());
        $this->drop($trip1, $kioskA, 3, 'cash_sale');
        $this->drop($trip1, $kioskA, 2, 'bs_redistribution');

        $trip2 = $this->endedTrip($this->owner, $this->operator, $this->cluster, today(), 2);
        $this->dropAndSettle($trip2, $kioskB, 5, 75, 60000, today());

        $widget = $this->actingAs($this->owner)->get('/owner/dashboard')->viewData('ringkasanBulanIni');

        $report = MonthlyReportController::buildMonthlyData(today()->format('Y-m'), $this->owner->id);
        $totals = $report['totals'];

        $this->assertEqualsWithDelta($totals['omset'], $widget['omset'], 0.01);
        $this->assertEqualsWithDelta($totals['komisi'], $widget['komisi'], 0.01);
        $this->assertEqualsWithDelta($totals['untung_bersih'], $widget['untung_bersih'], 0.01);
    }

    /** Isolasi tenant: data owner B tak bocor ke ringkasan owner A. */
    public function test_ringkasan_terisolasi_per_tenant(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id]);
        $trip = $this->endedTrip($this->owner, $this->operator, $this->cluster, today());
        $this->dropAndSettle($trip, $kiosk, 10, 150, 120000, today());
        $this->drop($trip, $kiosk, 3, 'cash_sale');

        // Owner B dengan angka besar — tak boleh muncul di ringkasan owner A.
        $ownerB = User::factory()->create(['role' => 'owner', 'is_active' => true, 'hpp_per_mika' => 9500, 'komisi_per_mika' => 1000]);
        $operatorB = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $ownerB->id]);
        $clusterB = Cluster::create(['name' => 'Cluster B', 'owner_id' => $ownerB->id]);
        $kioskB = Kiosk::factory()->create(['cluster_id' => $clusterB->id]);
        $tripB = $this->endedTrip($ownerB, $operatorB, $clusterB, today(), 9);
        $this->dropAndSettle($tripB, $kioskB, 50, 750, 999999, today());
        $this->drop($tripB, $kioskB, 40, 'cash_sale');

        $r = $this->actingAs($this->owner)->get('/owner/dashboard')->viewData('ringkasanBulanIni');

        // Hanya angka owner A.
        $this->assertEqualsWithDelta(120000, $r['omset'], 0.01);
        $this->assertEqualsWithDelta(13000, $r['komisi'], 0.01);
    }

    /** Bulan berjalan saja: trip belum selesai & trip bulan lalu tak masuk "bulan ini". */
    public function test_hanya_trip_selesai_bulan_berjalan(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id]);

        // Trip selesai bulan ini → dihitung.
        $tripNow = $this->endedTrip($this->owner, $this->operator, $this->cluster, today(), 1);
        $this->dropAndSettle($tripNow, $kiosk, 10, 150, 120000, today());

        // Trip bulan LALU → tak masuk "bulan ini" (tapi masuk pembanding).
        $lastMonth = today()->copy()->subMonthNoOverflow()->startOfMonth()->addDays(3);
        $tripLast = $this->endedTrip($this->owner, $this->operator, $this->cluster, $lastMonth, 1);
        $this->dropAndSettle($tripLast, $kiosk, 4, 60, 48000, $lastMonth);

        // Trip belum selesai (ended_at null) bulan ini → tak dihitung.
        $tripOpen = Trip::factory()->create([
            'owner_id' => $this->owner->id, 'operator_id' => $this->operator->id,
            'starting_cluster_id' => $this->cluster->id, 'trip_date' => today()->format('Y-m-d'),
            'trip_number_of_day' => 5, 'started_at' => now(), 'ended_at' => null,
        ]);
        $this->drop($tripOpen, $kiosk, 99, 'cash_sale');

        $response = $this->actingAs($this->owner)->get('/owner/dashboard');
        $bulanIni = $response->viewData('ringkasanBulanIni');
        $bulanLalu = $response->viewData('ringkasanBulanLalu');

        // Bulan ini: hanya trip selesai bulan ini (omset 120.000, komisi 10x1.000).
        $this->assertEqualsWithDelta(120000, $bulanIni['omset'], 0.01);
        $this->assertEqualsWithDelta(10000, $bulanIni['komisi'], 0.01);
        // Bulan lalu terhitung untuk pembanding.
        $this->assertEqualsWithDelta(48000, $bulanLalu['omset'], 0.01);
    }

    /** Rincian komisi per-operator muncul saat owner punya >1 operator. */
    public function test_rincian_komisi_per_operator(): void
    {
        $operator2 = User::factory()->create(['role' => 'operator', 'is_active' => true, 'name' => 'Budi', 'owner_id' => $this->owner->id]);
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id]);

        $trip1 = $this->endedTrip($this->owner, $this->operator, $this->cluster, today(), 1);
        $this->drop($trip1, $kiosk, 10, 'cash_sale'); // Rian: 10 x 1.000

        $trip2 = $this->endedTrip($this->owner, $operator2, $this->cluster, today(), 2);
        $this->drop($trip2, $kiosk, 4, 'cash_sale'); // Budi: 4 x 1.000

        $perOp = $this->actingAs($this->owner)->get('/owner/dashboard')->viewData('komisiPerOperator');

        $this->assertCount(2, $perOp);
        // Diurutkan komisi desc: Rian (10.000) dulu, Budi (4.000).
        $this->assertSame('Rian', $perOp[0]['operator']);
        $this->assertSame(10000, $perOp[0]['komisi']);
        $this->assertSame('Budi', $perOp[1]['operator']);
        $this->assertSame(4000, $perOp[1]['komisi']);
    }
}
