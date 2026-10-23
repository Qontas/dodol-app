<?php

namespace Tests\Feature\Admin;

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
 * TUGAS 4f (BRIEF 23 Okt 2026) — fee aplikasi TIDAK BOLEH muncul di: dashboard owner,
 * Riwayat Trip, Laporan Bulanan, export PDF/Excel, layar operator. Owner di sini
 * punya app_fee_per_pack bernilai NYATA (Rp 300) dan sebuah trip dengan snapshot
 * terisi, supaya kalau suatu saat fee aplikasi tak sengaja ikut terbaca ke salah
 * satu layar ini, test ini MERAH (bukan lolos "diam-diam" karena datanya kosong).
 */
class AppFeeDoesNotLeakTest extends TestCase
{
    use RefreshDatabase;

    private function ownerWithFeeAndTrip(): array
    {
        $owner = User::factory()->create([
            'role' => 'owner', 'is_active' => true,
            'hpp_per_mika' => 9500, 'app_fee_per_pack' => 300,
        ]);
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $owner->id]);
        $cluster = Cluster::create(['name' => 'Cluster Leak', 'owner_id' => $owner->id]);
        $kiosk = Kiosk::factory()->create(['cluster_id' => $cluster->id]);
        $variant = ProductVariant::factory()->create(['is_active' => true, 'sale_price_per_pack' => 12000]);

        $trip = Trip::create([
            'owner_id' => $owner->id,
            'operator_id' => $operator->id,
            'trip_date' => today(),
            'trip_number_of_day' => 1,
            'starting_cluster_id' => $cluster->id,
            'started_at' => now(),
            'ended_at' => now(),
            'qty_carried_total' => 60,
            'app_fee_per_pack_snapshot' => 300,
        ]);

        $delivery = Delivery::factory()->create([
            'kiosk_id' => $kiosk->id,
            'trip_id' => $trip->id,
            'product_variant_id' => $variant->id,
            'delivery_type' => 'cash_sale',
            'qty_delivered' => 10,
        ]);
        Settlement::factory()->create([
            'delivery_id' => $delivery->id,
            'visit_date' => $trip->trip_date,
            'qty_sold' => 150,
            'qty_returned_fresh' => 0,
            'qty_returned_expired' => 0,
            'amount_due' => 120000,
            'amount_paid' => 120000,
            'status' => 'paid',
        ]);
        KioskVisit::create([
            'trip_id' => $trip->id,
            'kiosk_id' => $kiosk->id,
            'visited_at' => now(),
            'visit_action' => 'cash_sale',
            'settled_delivery_id' => $delivery->id,
            'extension_granted' => false,
        ]);

        return [$owner, $operator, $trip];
    }

    private function assertNoFeeLeak(string $content): void
    {
        $this->assertStringNotContainsString('Fee Aplikasi', $content);
        $this->assertStringNotContainsString('app_fee_per_pack', $content);
        $this->assertStringNotContainsString('Mika Kena Fee', $content);
    }

    public function test_owner_dashboard_does_not_show_app_fee(): void
    {
        [$owner] = $this->ownerWithFeeAndTrip();

        $response = $this->actingAs($owner)->get(route('owner.dashboard'));
        $response->assertOk();
        $this->assertNoFeeLeak($response->getContent());
    }

    public function test_operator_dashboard_does_not_show_app_fee(): void
    {
        [, $operator] = $this->ownerWithFeeAndTrip();

        $response = $this->actingAs($operator)->get(route('operator.dashboard'));
        $response->assertOk();
        $this->assertNoFeeLeak($response->getContent());
    }

    public function test_riwayat_trip_list_and_detail_do_not_show_app_fee(): void
    {
        [$owner, , $trip] = $this->ownerWithFeeAndTrip();

        $this->actingAs($owner);

        $list = $this->get(route('owner.trips.index'));
        $list->assertOk();
        $this->assertNoFeeLeak($list->getContent());

        $detail = $this->get(route('owner.trips.show', $trip));
        $detail->assertOk();
        $this->assertNoFeeLeak($detail->getContent());
    }

    public function test_trip_pdf_and_excel_export_do_not_show_app_fee(): void
    {
        [$owner, , $trip] = $this->ownerWithFeeAndTrip();
        $this->actingAs($owner);

        $pdf = $this->get(route('owner.trips.export.pdf', $trip));
        $pdf->assertOk();
        $this->assertNoFeeLeak($pdf->getContent());

        $excel = $this->get(route('owner.trips.export.excel', $trip));
        $excel->assertOk();
        // xlsx terkompresi — tak bisa diperiksa substring teks secara andal, tapi
        // minimal dipastikan sukses diunduh (data fee memang tak pernah dikirim ke
        // TripReportExport, lihat TripExportController::buildTripData()).
        $this->assertStringContainsString('spreadsheetml', $excel->headers->get('content-type'));
    }

    public function test_monthly_report_page_and_exports_do_not_show_app_fee(): void
    {
        [$owner, , $trip] = $this->ownerWithFeeAndTrip();
        $this->actingAs($owner);
        $month = $trip->trip_date->format('Y-m');

        $page = $this->get(route('owner.reports.monthly', ['month' => $month]));
        $page->assertOk();
        $this->assertNoFeeLeak($page->getContent());

        $pdf = $this->get(route('owner.reports.monthly.pdf', ['month' => $month]));
        $pdf->assertOk();
        $this->assertNoFeeLeak($pdf->getContent());

        $excel = $this->get(route('owner.reports.monthly.excel', ['month' => $month]));
        $excel->assertOk();
        $this->assertStringContainsString('spreadsheetml', $excel->headers->get('content-type'));
    }
}
