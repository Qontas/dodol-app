<?php

namespace Tests\Feature\Owner;

use App\Http\Controllers\Owner\MonthlyReportController;
use App\Http\Controllers\Owner\TripExportController;
use App\Livewire\Owner\LiveTripProgress;
use App\Models\Cluster;
use App\Models\Delivery;
use App\Models\Kiosk;
use App\Models\ProductVariant;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TEST REGRESI PERMANEN — BRIEF "Satu Sumber Kebenaran Komisi" (24 Okt 2026).
 *
 * Membangun ulang skenario investigasi asli: owner default komisi_per_mika
 * Rp 1.000; operator A tarif KHUSUS Rp 800; operator B ikut tarif default
 * owner. Satu trip B bulan LALU (tarif saat itu 1.000). Bulan ini: trip A
 * (tarif 800) dan trip B1 (tarif masih 1.000) dibuat SEBELUM owner mengganti
 * tarif default ke 1.200, lalu trip B2 (tarif 1.200) dibuat SESUDAHNYA. Satu
 * trip AKTIF (belum ended_at) milik operator A untuk uji LiveTripProgress &
 * widget admin "Hari Ini".
 *
 * Tiap trip membekukan tarifnya sendiri di komisi_per_mika_snapshot SAAT
 * DIBUAT (di sini diisi langsung lewat factory, meniru apa yang seharusnya
 * ditulis StartTrip::startTrip() dari User::getEffectiveKomisiPerMikaValue()
 * — lihat Trip::getKomisiPerMika()) — SENGAJA TIDAK dipengaruhi oleh
 * perubahan tarif owner belakangan. Angka target (sama dengan investigasi
 * asli & brief):
 *   - Trip A (ended, tarif 800)  : komisi = 20 mika x Rp 800  = 16.000
 *   - Trip B1 (ended, tarif 1000): komisi = 10 mika x Rp 1.000 = 10.000
 *   - Trip B2 (ended, tarif 1200): komisi = 10 mika x Rp 1.200 = 12.000
 *   - Trip B-lalu (ended, tarif 1000, BULAN LALU): komisi = 10 mika x Rp 1.000 = 10.000
 *   - Trip A-aktif (belum ended, tarif 800): komisi sejauh ini = 2 mika x Rp 800 = 1.600
 *   - Total bulan ini (HANYA trip ended) = 16.000 + 10.000 + 12.000 = 38.000
 *   - Total bulan lalu (HANYA trip ended, TAK berubah walau tarif owner sudah
 *     diganti) = 10.000
 *
 * SETIAP tempat yang membaca komisi HARUS menunjukkan angka ini — kalau ada
 * satu yang masih baca tabel `commissions` atau tarif LIVE, test ini gagal.
 * Tabel `commissions` SENGAJA tidak disentuh sama sekali di test ini (tidak
 * ada baris dibuat) — justru untuk membuktikan tampilan tak lagi bergantung
 * padanya sama sekali (kalau masih baca commissions, semua angka akan 0,
 * bukan cuma salah).
 */
class KomisiSnapshotSingleSourceOfTruthTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected User $operatorA;
    protected User $operatorB;
    protected Cluster $cluster;
    protected Kiosk $kiosk;
    protected ProductVariant $variant;

    protected Trip $tripBLalu;
    protected Trip $tripA;
    protected Trip $tripB1;
    protected Trip $tripB2;
    protected Trip $tripAAktif;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create([
            'role' => 'owner',
            'is_active' => true,
            'komisi_per_mika' => 1000,
        ]);

        $this->operatorA = User::factory()->create([
            'role' => 'operator',
            'is_active' => true,
            'owner_id' => $this->owner->id,
            'komisi_per_mika_khusus' => 800,
        ]);

        $this->operatorB = User::factory()->create([
            'role' => 'operator',
            'is_active' => true,
            'owner_id' => $this->owner->id,
            'komisi_per_mika_khusus' => null,
        ]);

        $this->cluster = Cluster::create(['name' => 'Cluster Snapshot', 'owner_id' => $this->owner->id]);
        $this->kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id]);
        $this->variant = ProductVariant::factory()->create(['is_active' => true, 'sale_price_per_pack' => 12000]);

        $lastMonth = today()->subMonthNoOverflow();

        // --- Trip B bulan LALU: tarif saat itu = default owner (1000). ---
        $this->tripBLalu = $this->buatTripSelesai($this->operatorB, $lastMonth->copy()->day(10), 1000, qtyDrop: 10);

        // --- Bulan ini, SEBELUM owner ganti tarif default: trip A & B1. ---
        $this->tripA = $this->buatTripSelesai($this->operatorA, today()->startOfMonth()->addDays(2), 800, qtyDrop: 20);
        $this->tripB1 = $this->buatTripSelesai($this->operatorB, today()->startOfMonth()->addDays(3), 1000, qtyDrop: 10);

        // --- Owner ganti tarif default: 1000 -> 1200. Trip LAMA di atas harus TAK berubah. ---
        $this->owner->update(['komisi_per_mika' => 1200]);

        // --- Trip B2 dibuat SESUDAH perubahan tarif (snapshot 1200). ---
        $this->tripB2 = $this->buatTripSelesai($this->operatorB, today()->startOfMonth()->addDays(4), 1200, qtyDrop: 10);

        // --- Trip AKTIF operator A, tarif khusus 800, tanggal HARI INI (untuk isolasi
        //     assertion widget admin "Hari Ini" dari trip lain yang tak bertanggal hari ini). ---
        $this->tripAAktif = Trip::factory()->create([
            'owner_id' => $this->owner->id,
            'operator_id' => $this->operatorA->id,
            'starting_cluster_id' => $this->cluster->id,
            'trip_date' => today()->format('Y-m-d'),
            'started_at' => now(),
            'ended_at' => null,
            'komisi_per_mika_snapshot' => 800,
            'qty_carried_total' => 10,
        ]);
        Delivery::factory()->create([
            'kiosk_id' => $this->kiosk->id,
            'trip_id' => $this->tripAAktif->id,
            'product_variant_id' => $this->variant->id,
            'delivery_type' => 'consignment',
            'qty_delivered' => 2,
        ]);
    }

    /**
     * Trip SELESAI dengan satu delivery basis komisi (bukan BS) sebesar $qtyDrop mika,
     * tarif beku $tarifSnapshot. Tanpa Settlement — komisi basis DROP (lihat
     * TripAggregator::for() §1/§7) TIDAK butuh settlement sama sekali, dan tanpa
     * baris Settlement/KioskVisit di sini kita juga tak perlu memenuhi invariant
     * SettlementObserver::validateQtySum() (qty_sold+qty_returned = qty_biji_delivered).
     */
    protected function buatTripSelesai(User $operator, \Illuminate\Support\Carbon $tanggal, float $tarifSnapshot, int $qtyDrop): Trip
    {
        $trip = Trip::factory()->create([
            'owner_id' => $this->owner->id,
            'operator_id' => $operator->id,
            'starting_cluster_id' => $this->cluster->id,
            'trip_date' => $tanggal->format('Y-m-d'),
            'started_at' => $tanggal,
            'ended_at' => $tanggal->copy()->addHours(4),
            'komisi_per_mika_snapshot' => $tarifSnapshot,
            'qty_carried_total' => $qtyDrop,
        ]);

        Delivery::factory()->create([
            'kiosk_id' => $this->kiosk->id,
            'trip_id' => $trip->id,
            'product_variant_id' => $this->variant->id,
            'delivery_type' => 'consignment',
            'qty_delivered' => $qtyDrop,
        ]);

        return $trip;
    }

    /** FIX A: $ringkasanBulanIni['komisi'] = 38.000, $ringkasanBulanLalu['komisi'] TETAP 10.000. */
    public function test_dashboard_ringkasan_bulan_ini_dan_lalu(): void
    {
        $response = $this->actingAs($this->owner)->get('/owner/dashboard');
        $response->assertOk();

        $ringkasanBulanIni = $response->viewData('ringkasanBulanIni');
        $ringkasanBulanLalu = $response->viewData('ringkasanBulanLalu');

        $this->assertEquals(38000.0, $ringkasanBulanIni['komisi']);
        // Angka bulan lalu TIDAK berubah walau owner sudah ganti tarif default ke 1200.
        $this->assertEquals(10000.0, $ringkasanBulanLalu['komisi']);
    }

    /** FIX B: $komisiPerOperator — per operator_id, dari TripAggregator (tarif beku), bukan SUM(qty) x tarif live. */
    public function test_dashboard_komisi_per_operator(): void
    {
        $response = $this->actingAs($this->owner)->get('/owner/dashboard');
        $response->assertOk();

        $komisiPerOperator = $response->viewData('komisiPerOperator')->keyBy('operator');

        // Operator A: hanya trip A (ended) — trip aktif dikecualikan ($tripsBulanIni
        // whereNotNull('ended_at')) — 20 mika x Rp 800 = 16.000.
        $this->assertSame(16000, $komisiPerOperator[$this->operatorA->name]['komisi']);
        // Operator B: B1 (10 mika x 1000) + B2 (10 mika x 1200) = 10.000 + 12.000 = 22.000.
        $this->assertSame(22000, $komisiPerOperator[$this->operatorB->name]['komisi']);
    }

    /** FIX C: LiveTripProgress "Komisi Sejauh Ini" trip aktif A pakai tarif snapshot (800), bukan tarif owner LIVE (1200). */
    public function test_live_trip_progress_komisi_sejauh_ini_pakai_tarif_snapshot(): void
    {
        $this->actingAs($this->owner);

        $tripStats = Livewire::test(LiveTripProgress::class)->viewData('tripStats');

        // 2 mika x Rp 800 (snapshot) = 1.600 — BUKAN 2 x Rp 1.200 (tarif owner live
        // sekarang) = 2.400, yang akan muncul kalau fix C regresi.
        $this->assertEquals(1600.0, $tripStats[$this->tripAAktif->id]['komisi']);
    }

    /** FIX D: TripExportController label "Rp X x mika drop" pakai Trip::getKomisiPerMika() (snapshot), X x mika = total_komisi. */
    public function test_trip_export_label_komisi_konsisten_dengan_total(): void
    {
        $data = TripExportController::buildTripData($this->tripA);

        // Trip A snapshot 800 — BUKAN tarif owner live (1200).
        $this->assertSame(800, $data['komisiPerMika']);
        $this->assertSame(16000, $data['finansial']['total_komisi']);
        // X x mika drop = total yang tampil (tak boleh beda, itulah inti fix D).
        $this->assertSame($data['komisiPerMika'] * $data['finansial']['mika_komisi'], $data['finansial']['total_komisi']);
    }

    /** FIX E: Laporan Bulanan — tabel "Rekap Per Trip" DAN kartu "Rekap Komisi Operator" sama-sama dari $totals['komisi']. */
    public function test_laporan_bulanan_rekap_per_trip_dan_kartu_komisi(): void
    {
        $bulanIni = today()->format('Y-m');
        $data = MonthlyReportController::buildMonthlyData($bulanIni, $this->owner->id);

        // Baris per trip: tak ada trip_id di 'rows', jadi verifikasi via jumlah baris +
        // himpunan nilai komisi (A, B1, B2 — trip bulan lalu & aktif tak ikut bulan ini).
        $this->assertCount(3, $data['rows']);
        $this->assertEqualsCanonicalizing([16000, 10000, 12000], $data['rows']->pluck('komisi')->all());

        // Kartu "Rekap Komisi Operator" = $totals['komisi'] = SAMA dgn total tabel di atas.
        $this->assertEquals(38000.0, $data['totals']['komisi']);
        $this->assertEquals(38000.0, $data['rekap_komisi']);

        // Lewat HTTP juga (render blade sungguhan).
        $response = $this->actingAs($this->owner)->get("/owner/reports/monthly?month={$bulanIni}");
        $response->assertOk();
        $response->assertViewHas('rekap_komisi', 38000.0);
        $response->assertSee('Rp 38.000');
    }

    /** Riwayat Trip (TripHistoryController) — per-trip, sudah benar sebelumnya; dites ulang di sini untuk kelengkapan skenario. */
    public function test_riwayat_trip_per_baris(): void
    {
        $response = $this->actingAs($this->owner)->get('/owner/trips?status=aktif');
        $response->assertOk();

        $aggregates = $response->viewData('aggregates');

        $this->assertEquals(16000.0, $aggregates[$this->tripA->id]['komisi']);
        $this->assertEquals(10000.0, $aggregates[$this->tripB1->id]['komisi']);
        $this->assertEquals(12000.0, $aggregates[$this->tripB2->id]['komisi']);
        // Trip bulan lalu tetap tampil di Riwayat (bukan cuma dashboard bulan-ini/lalu) —
        // tarifnya pun tetap 10.000 walau tarif owner sudah berubah.
        $this->assertEquals(10000.0, $aggregates[$this->tripBLalu->id]['komisi']);
    }

    /** FIX G part 1: SuperAdminStatsOverview "Total Komisi Operator Global Hari Ini" — isolasi ke trip tanggal hari ini saja. */
    public function test_admin_stats_overview_komisi_hari_ini(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($superAdmin);

        $widget = new \App\Filament\Widgets\SuperAdminStatsOverview();
        $getStats = new \ReflectionMethod($widget, 'getStats');
        $getStats->setAccessible(true);
        $stats = $getStats->invoke($widget);

        $komisiStat = null;
        foreach ($stats as $stat) {
            if ($stat->getLabel() === 'Total Komisi Operator Global Hari Ini') {
                $komisiStat = $stat->getValue();
            }
        }

        // Hanya tripAAktif bertanggal HARI INI (2 mika x Rp 800 = 1.600) — trip lain
        // (A/B1/B2/B-lalu) bertanggal hari-hari lain di bulan ini/lalu, tak ikut.
        $this->assertSame('Rp 1.600', $komisiStat);
    }

    /** FIX G part 2: OperatorKomisiTable "Komisi Bulan Ini" per operator — lintas ended+aktif (tanpa filter ended_at). */
    public function test_admin_operator_komisi_table_komisi_bulan_ini(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($superAdmin);

        $widget = new \App\Filament\Widgets\OperatorKomisiTable();
        $operators = User::where('role', 'operator')->where('is_active', true)->get(['id']);

        $computeMethod = new \ReflectionMethod($widget, 'computeKomisiByOperator');
        $computeMethod->setAccessible(true);
        $compute = $computeMethod->invoke($widget, $operators, now()->month, now()->year);

        // Operator A: trip A ended (16.000) + trip aktif A (1.600, TIDAK difilter
        // ended_at di widget ini) = 17.600.
        $this->assertSame(17600, $compute[$this->operatorA->id]);
        // Operator B: B1 (10.000) + B2 (12.000), tak ada trip aktif = 22.000.
        $this->assertSame(22000, $compute[$this->operatorB->id]);
    }
}
