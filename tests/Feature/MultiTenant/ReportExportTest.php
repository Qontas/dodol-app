<?php

namespace Tests\Feature\MultiTenant;

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

class ReportExportTest extends TestCase
{
    use RefreshDatabase;

    private function ownerWithTrip(): array
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true, 'hpp_per_mika' => 9500]);
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $owner->id]);
        $cluster = Cluster::create(['name' => 'Cluster Lap', 'owner_id' => $owner->id]);
        $kiosk = Kiosk::factory()->create(['cluster_id' => $cluster->id]);
        $variant = ProductVariant::factory()->create(['is_active' => true, 'sale_price_per_pack' => 12000]);

        $trip = Trip::factory()->create([
            'owner_id' => $owner->id,
            'operator_id' => $operator->id,
            'starting_cluster_id' => $cluster->id,
            'qty_carried_total' => 60,
            'ended_at' => now(),
        ]);

        $delivery = Delivery::factory()->create([
            'kiosk_id' => $kiosk->id,
            'trip_id' => $trip->id,
            'product_variant_id' => $variant->id,
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
            'visit_action' => 'settle_only',
            'settled_delivery_id' => $delivery->id,
            'extension_granted' => false,
        ]);

        return [$owner, $trip];
    }

    public function test_trip_pdf_export_downloads(): void
    {
        [$owner, $trip] = $this->ownerWithTrip();

        $response = $this->actingAs($owner)->get(route('owner.trips.export.pdf', $trip));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_trip_excel_export_downloads(): void
    {
        [$owner, $trip] = $this->ownerWithTrip();

        $response = $this->actingAs($owner)->get(route('owner.trips.export.excel', $trip));

        $response->assertOk();
        $this->assertStringContainsString(
            'spreadsheetml',
            $response->headers->get('content-type')
        );
    }

    public function test_owner_cannot_export_other_owners_trip(): void
    {
        [$owner, $trip] = $this->ownerWithTrip();
        $intruder = User::factory()->create(['role' => 'owner', 'is_active' => true]);

        // Secure-by-default (global OwnerScope): route-model binding tak menemukan
        // trip owner lain → 404 (bukan 403). Guard controller abort(403) tetap ada.
        $this->actingAs($intruder)->get(route('owner.trips.export.pdf', $trip))->assertNotFound();
    }

    public function test_monthly_report_page_loads(): void
    {
        [$owner, $trip] = $this->ownerWithTrip();
        $month = $trip->trip_date->format('Y-m');

        $response = $this->actingAs($owner)->get(route('owner.reports.monthly', ['month' => $month]));

        $response->assertOk();
        $response->assertViewHas('totals');
        $response->assertSee('Rekap Per Trip');
    }

    public function test_monthly_pdf_and_excel_export(): void
    {
        [$owner, $trip] = $this->ownerWithTrip();
        $month = $trip->trip_date->format('Y-m');

        $pdf = $this->actingAs($owner)->get(route('owner.reports.monthly.pdf', ['month' => $month]));
        $pdf->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('content-type'));

        $xls = $this->actingAs($owner)->get(route('owner.reports.monthly.excel', ['month' => $month]));
        $xls->assertOk();
        $this->assertStringContainsString('spreadsheetml', $xls->headers->get('content-type'));
    }

    public function test_operator_cannot_access_reports(): void
    {
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true]);
        $this->actingAs($operator)->get(route('owner.reports.monthly'))->assertForbidden();
    }

    /**
     * BRIEF 26 Okt 2026 item 1 — "UNDUH, BUKAN TAMPIL": semua export WAJIB
     * Content-Disposition: attachment + nama file manusiawi, bukan nama teknis
     * `trip-report-{date}.pdf` yang lama.
     */
    public function test_trip_export_filenames_are_human_readable_attachments(): void
    {
        [$owner, $trip] = $this->ownerWithTrip();

        $pdf = $this->actingAs($owner)->get(route('owner.trips.export.pdf', $trip));
        $pdf->assertOk();
        $disposition = $pdf->headers->get('content-disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('Laporan-Trip-'.$trip->trip_date->format('Y-m-d'), $disposition);
        $this->assertStringContainsString('.pdf', $disposition);

        $xls = $this->actingAs($owner)->get(route('owner.trips.export.excel', $trip));
        $xls->assertOk();
        $xlsDisposition = $xls->headers->get('content-disposition');
        $this->assertStringContainsString('attachment', $xlsDisposition);
        $this->assertStringContainsString('Laporan-Trip-'.$trip->trip_date->format('Y-m-d'), $xlsDisposition);
        $this->assertStringContainsString('.xlsx', $xlsDisposition);
    }

    public function test_monthly_export_filenames_are_human_readable_attachments(): void
    {
        [$owner, $trip] = $this->ownerWithTrip();
        $month = $trip->trip_date->format('Y-m');

        $pdf = $this->actingAs($owner)->get(route('owner.reports.monthly.pdf', ['month' => $month]));
        $pdf->assertOk();
        $disposition = $pdf->headers->get('content-disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('Laporan-', $disposition);
        $this->assertStringContainsString('Cemilan-Qontas', $disposition);
    }

    /**
     * BRIEF 26 Okt 2026 item 2 — "DETAIL KUNJUNGAN LENGKAP": total kolom "Uang
     * Diterima" di daftar kunjungan (web, PDF, Excel) WAJIB sama dengan Omset trip
     * (keduanya basis amount_paid settlement — satu sumber, bukan dua rumus).
     */
    public function test_total_uang_diterima_equals_trip_omset(): void
    {
        [$owner, $trip] = $this->ownerWithTrip();

        $response = $this->actingAs($owner)->get(route('owner.trips.show', $trip));
        $response->assertOk();

        $visitRows = $response->viewData('visitRows');
        $totalUangDiterima = $visitRows->sum('uang_diterima');

        $this->assertSame((int) round($trip->omset_val), $totalUangDiterima);
        $this->assertGreaterThan(0, $totalUangDiterima);
    }

    public function test_trip_detail_page_shows_all_required_visit_columns(): void
    {
        [$owner, $trip] = $this->ownerWithTrip();

        $response = $this->actingAs($owner)->get(route('owner.trips.show', $trip));

        $response->assertOk();
        foreach (['Mika Titip', 'Biji Terjual', 'BS (biji)', 'Tagihan', 'Uang Diterima', 'Piutang', 'Catatan/Janji Bayar'] as $col) {
            $response->assertSee($col);
        }
    }
}
