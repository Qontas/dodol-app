<?php

namespace Tests\Feature\Owner;

use App\Http\Controllers\Owner\MonthlyReportController;
use App\Http\Controllers\Owner\PeriodReportController;
use App\Models\Cluster;
use App\Models\Delivery;
use App\Models\Kiosk;
use App\Models\KioskVisit;
use App\Models\ProductVariant;
use App\Models\Settlement;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * BRIEF 26 Okt 2026 item 3 — "Laporan Periode". Fokus test: (a) "Bulan Ini" harus
 * identik Laporan Bulanan lama untuk bulan yang sama (angka uang TIDAK berubah,
 * TripAggregator sebagai satu sumber), (b) sentinel/trip diarsip dikecualikan,
 * (c) isolasi tenant, (d) role operator ditolak, (e) fee aplikasi TIDAK PERNAH
 * muncul, (f) sheet "Data Kunjungan" kolom numerik berupa angka asli (bukan teks).
 */
class PeriodReportTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: User, 2: Trip} [owner, operator, trip] */
    private function ownerWithTripOnDate(string $tripDate, array $tripOverrides = []): array
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true, 'hpp_per_mika' => 9500]);
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $owner->id]);
        // Nama cluster unik global (bukan per-owner) — pakai id owner biar tak
        // bentrok saat helper dipanggil >1x dalam satu test (uji isolasi tenant).
        $cluster = Cluster::create(['name' => 'Cluster Periode '.$owner->id, 'owner_id' => $owner->id]);
        $kiosk = Kiosk::factory()->create(['cluster_id' => $cluster->id]);
        $variant = ProductVariant::factory()->create(['is_active' => true, 'sale_price_per_pack' => 12000]);

        $trip = Trip::factory()->create(array_merge([
            'owner_id' => $owner->id,
            'operator_id' => $operator->id,
            'starting_cluster_id' => $cluster->id,
            'trip_date' => $tripDate,
            'qty_carried_total' => 60,
            'ended_at' => $tripDate.' 17:00:00',
        ], $tripOverrides));

        $delivery = Delivery::factory()->create([
            'kiosk_id' => $kiosk->id,
            'trip_id' => $trip->id,
            'product_variant_id' => $variant->id,
            'qty_delivered' => 10,
        ]);
        // qty_sold 150 = qty_delivered(10) x units_per_pack default(15) — HARUS pas
        // (SettlementObserver::validateQtySum() menolak kalau tak sama), biar bisa
        // sekaligus uji "piutang baru" lewat amount_paid < amount_due (bukan qty).
        $settlement = Settlement::factory()->create([
            'delivery_id' => $delivery->id,
            'visit_date' => $trip->trip_date,
            'qty_sold' => 150,
            'qty_returned_fresh' => 0,
            'qty_returned_expired' => 0,
            'amount_due' => 150000,
            'amount_paid' => 80000, // sebagian — sisanya jadi "piutang baru"
            'status' => 'pending',
        ]);
        KioskVisit::create([
            'trip_id' => $trip->id,
            'kiosk_id' => $kiosk->id,
            'visited_at' => $tripDate.' 09:00:00',
            'visit_action' => 'settle_only',
            'settled_delivery_id' => $delivery->id,
            'extension_granted' => false,
        ]);

        return [$owner, $operator, $trip];
    }

    public function test_bulan_ini_totals_match_laporan_bulanan_lama(): void
    {
        [$owner] = $this->ownerWithTripOnDate('2026-06-15');

        $monthly = MonthlyReportController::buildMonthlyData('2026-06', $owner->id);
        $periode = PeriodReportController::buildPeriodData('2026-06-01', '2026-06-30', null, $owner->id);

        // Angka uang WAJIB identik — keduanya dari TripAggregator, trip set sama.
        $this->assertSame($monthly['totals']['omset'], $periode['totals']['omset']);
        $this->assertSame($monthly['totals']['komisi'], $periode['totals']['komisi']);
        $this->assertSame($monthly['totals']['untung_bersih'], $periode['totals']['untung_bersih']);
        $this->assertSame($monthly['totals']['mika_drop'], $periode['totals']['mika_diantar']);
        $this->assertGreaterThan(0, $periode['totals']['omset'], 'Sanity check — jangan sampai keduanya kebetulan 0.');
    }

    public function test_piutang_baru_dihitung_dari_settlement_pending_periode_ini(): void
    {
        [$owner] = $this->ownerWithTripOnDate('2026-06-15');

        $periode = PeriodReportController::buildPeriodData('2026-06-01', '2026-06-30', null, $owner->id);

        // amount_due 150000 - amount_paid 80000 = 70000, status pending.
        $this->assertSame(70000, $periode['totals']['piutang_baru']);
    }

    public function test_sentinel_trip_dikecualikan_dari_periode(): void
    {
        [$owner] = $this->ownerWithTripOnDate('2026-06-15');

        $sentinelOperator = User::factory()->create([
            'role' => 'operator',
            'is_active' => true,
            'owner_id' => $owner->id,
            'email' => User::SYSTEM_OPERATOR_EMAIL_PREFIX.'sentinel@test.test',
        ]);
        $cluster = Cluster::create(['name' => 'Cluster Sentinel', 'owner_id' => $owner->id]);
        $sentinelTrip = Trip::factory()->create([
            'owner_id' => $owner->id,
            'operator_id' => $sentinelOperator->id,
            'starting_cluster_id' => $cluster->id,
            'trip_date' => '2026-06-20',
            'ended_at' => '2026-06-20 00:00:01',
            'qty_carried_total' => 999,
        ]);
        $kiosk = Kiosk::factory()->create(['cluster_id' => $cluster->id]);
        $variant = ProductVariant::factory()->create(['is_active' => true]);
        Delivery::factory()->create([
            'trip_id' => $sentinelTrip->id,
            'kiosk_id' => $kiosk->id,
            'product_variant_id' => $variant->id,
            'qty_delivered' => 999,
        ]);

        $periode = PeriodReportController::buildPeriodData('2026-06-01', '2026-06-30', null, $owner->id);

        $tripIds = $periode['trips']->pluck('trip_id')->all();
        $this->assertNotContains($sentinelTrip->id, $tripIds, 'Trip sentinel migrasi tidak boleh masuk Laporan Periode.');
    }

    public function test_trip_diarsip_dikecualikan_dari_periode(): void
    {
        [$owner, , $trip] = $this->ownerWithTripOnDate('2026-06-15');
        $trip->delete(); // SoftDeletes — "arsip".

        $periode = PeriodReportController::buildPeriodData('2026-06-01', '2026-06-30', null, $owner->id);

        $this->assertSame(0, $periode['totals']['omset'], 'Trip diarsip tidak boleh ikut angka Laporan Periode.');
    }

    public function test_isolasi_tenant_owner_lain_tidak_ikut_terhitung(): void
    {
        [$ownerA] = $this->ownerWithTripOnDate('2026-06-15');
        [$ownerB] = $this->ownerWithTripOnDate('2026-06-16');

        $periodeA = PeriodReportController::buildPeriodData('2026-06-01', '2026-06-30', null, $ownerA->id);

        $this->assertCount(1, $periodeA['trips']);
        $this->assertNotEquals($ownerB->id, $ownerA->id);
    }

    public function test_halaman_periode_bisa_diakses_owner(): void
    {
        [$owner] = $this->ownerWithTripOnDate('2026-06-15');

        $response = $this->actingAs($owner)->get(route('owner.reports.periode', ['range' => 'custom', 'from' => '2026-06-01', 'to' => '2026-06-30']));

        $response->assertOk();
        $response->assertSee('Laporan Periode');
        $response->assertViewHas('totals');
    }

    public function test_operator_tidak_bisa_akses_laporan_periode(): void
    {
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true]);

        $this->actingAs($operator)->get(route('owner.reports.periode'))->assertForbidden();
    }

    public function test_fee_aplikasi_tidak_pernah_muncul_di_laporan_periode(): void
    {
        [$owner] = $this->ownerWithTripOnDate('2026-06-15');

        $response = $this->actingAs($owner)->get(route('owner.reports.periode', ['range' => 'custom', 'from' => '2026-06-01', 'to' => '2026-06-30']));

        $response->assertOk();
        $this->assertStringNotContainsStringIgnoringCase('fee aplikasi', $response->getContent());
    }

    public function test_pdf_dan_excel_periode_terunduh_sebagai_attachment(): void
    {
        [$owner] = $this->ownerWithTripOnDate('2026-06-15');
        $params = ['range' => 'custom', 'from' => '2026-06-01', 'to' => '2026-06-30'];

        $pdf = $this->actingAs($owner)->get(route('owner.reports.periode.pdf', $params));
        $pdf->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('content-type'));
        $this->assertStringContainsString('attachment', $pdf->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $xls = $this->actingAs($owner)->get(route('owner.reports.periode.excel', $params));
        $xls->assertOk();
        $this->assertStringContainsString('spreadsheetml', $xls->headers->get('content-type'));
        $this->assertStringContainsString('attachment', $xls->headers->get('content-disposition'));
        $this->assertStringContainsString('Laporan-Periode-', $xls->headers->get('content-disposition'));
    }

    public function test_excel_punya_3_sheet_dan_kolom_numerik_data_kunjungan_berupa_angka(): void
    {
        [$owner] = $this->ownerWithTripOnDate('2026-06-15');
        $params = ['range' => 'custom', 'from' => '2026-06-01', 'to' => '2026-06-30'];

        $xls = $this->actingAs($owner)->get(route('owner.reports.periode.excel', $params));
        $xls->assertOk();

        // Excel::download() balik BinaryFileResponse — getContent() Symfony SELALU
        // false untuk tipe ini (bukan di-buffer), jadi ambil file-nya langsung.
        $tmpPath = tempnam(sys_get_temp_dir(), 'periode').'.xlsx';
        copy($xls->baseResponse->getFile()->getPathname(), $tmpPath);

        try {
            $spreadsheet = IOFactory::load($tmpPath);
            $sheetNames = $spreadsheet->getSheetNames();
            $this->assertSame(['Ringkasan', 'Per Trip', 'Data Kunjungan'], $sheetNames);

            $dataKunjungan = $spreadsheet->getSheetByName('Data Kunjungan');
            // Baris 1 = header, baris 2 = kunjungan pertama. Kolom G = "Mika Titip"
            // (A..G: Tanggal,Jam,Operator,Area,Kios,Aksi,Mika Titip).
            $cell = $dataKunjungan->getCell('G2');
            $this->assertTrue(
                is_numeric($cell->getValue()),
                'Kolom Mika Titip di sheet Data Kunjungan harus angka asli, bukan teks berformat.'
            );
        } finally {
            @unlink($tmpPath);
        }
    }
}
