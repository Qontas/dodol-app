<?php

namespace Tests\Feature\Owner;

use App\Models\User;
use App\Models\Settlement;
use App\Models\Delivery;
use App\Models\Kiosk;
use App\Models\ProductVariant;
use App\Models\Cluster;
use App\Models\Trip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_dashboard_shows_correct_data_and_chart_variables()
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);

        $cluster = Cluster::create(['name' => 'Cluster Owner', 'owner_id' => $owner->id]);
        $kiosk = Kiosk::factory()->create(['cluster_id' => $cluster->id]);
        $variant = ProductVariant::factory()->create([
            'sale_price_per_pack' => 12000,
            'is_active' => true,
        ]);

        $delivery = Delivery::factory()->create([
            'kiosk_id' => $kiosk->id,
            'product_variant_id' => $variant->id,
            'qty_delivered' => 5,
        ]);

        Settlement::factory()->create([
            'delivery_id' => $delivery->id,
            'visit_date' => today(),
            'qty_sold' => 75,
            'qty_returned_fresh' => 0,
            'qty_returned_expired' => 0,
            'amount_due' => 60000,
            'amount_paid' => 60000,
            'status' => 'paid',
        ]);

        $deliveryPast = Delivery::factory()->create([
            'kiosk_id' => $kiosk->id,
            'product_variant_id' => $variant->id,
            'qty_delivered' => 5,
        ]);

        Settlement::factory()->create([
            'delivery_id' => $deliveryPast->id,
            'visit_date' => today()->subDays(2),
            'qty_sold' => 45,
            'qty_returned_fresh' => 30,
            'qty_returned_expired' => 0,
            'amount_due' => 36000,
            'amount_paid' => 36000,
            'status' => 'paid',
        ]);

        // Data milik owner LAIN — tidak boleh ikut terhitung di dashboard owner ini.
        $otherOwner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $otherCluster = Cluster::create(['name' => 'Cluster Lain', 'owner_id' => $otherOwner->id]);
        $otherKiosk = Kiosk::factory()->create(['cluster_id' => $otherCluster->id]);
        $otherDelivery = Delivery::factory()->create([
            'kiosk_id' => $otherKiosk->id,
            'product_variant_id' => $variant->id,
            'qty_delivered' => 5,
        ]);
        Settlement::factory()->create([
            'delivery_id' => $otherDelivery->id,
            'visit_date' => today(),
            'qty_sold' => 75,
            'qty_returned_fresh' => 0,
            'qty_returned_expired' => 0,
            'amount_due' => 99999,
            'amount_paid' => 99999,
            'status' => 'paid',
        ]);

        $response = $this->actingAs($owner)->get('/owner/dashboard');

        $response->assertOk();
        $response->assertViewHas('chartLabels');
        $response->assertViewHas('chartData');

        $chartData = $response->viewData('chartData');
        $chartLabels = $response->viewData('chartLabels');

        $this->assertCount(30, $chartData);
        $this->assertCount(30, $chartLabels);

        // Today's index is the last one (index 29) — hanya omset owner ini (bukan + 99999)
        $this->assertEquals(60000, $chartData[29]);

        // Two days ago is index 27
        $this->assertEquals(36000, $chartData[27]);

        // The day before that (index 28) should be 0
        $this->assertEquals(0, $chartData[28]);

        // Stats di-scope ke owner ini saja
        $this->assertEquals(1, $response->viewData('stats')['total_kios']);
        $this->assertEquals(1, $response->viewData('stats')['total_cluster']);
    }

    /**
     * Widget kerugian titipan: hanya settlement write-off (Stop Tanpa Tagih)
     * bulan berjalan milik owner ini yang dijumlahkan; mika x HPP per mika.
     */
    public function test_kerugian_titipan_aggregates_writeoff_settlements_scoped_to_owner()
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true, 'hpp_per_mika' => 9500]);
        $cluster = Cluster::create(['name' => 'Cluster Loss', 'owner_id' => $owner->id]);
        $kiosk = Kiosk::factory()->create(['cluster_id' => $cluster->id, 'is_active' => false]);
        $variant = ProductVariant::factory()->create(['is_active' => true, 'sale_price_per_pack' => 12000]);

        // Kerugian: 5 mika (75 biji) hilang, uang 0, write-off, bulan ini.
        $delivery = Delivery::factory()->create([
            'kiosk_id' => $kiosk->id,
            'product_variant_id' => $variant->id,
            'qty_delivered' => 5,
        ]);
        Settlement::factory()->create([
            'delivery_id' => $delivery->id,
            'visit_date' => today(),
            'qty_sold' => 0,
            'qty_returned_fresh' => 0,
            'qty_returned_expired' => 75,
            'amount_due' => 0,
            'amount_paid' => 0,
            'status' => 'paid',
            'is_writeoff' => true,
        ]);

        // Settlement tagih biasa yang semua diretur basi — BUKAN write-off, tak dihitung.
        $deliveryNormal = Delivery::factory()->create([
            'kiosk_id' => $kiosk->id,
            'product_variant_id' => $variant->id,
            'qty_delivered' => 2,
        ]);
        Settlement::factory()->create([
            'delivery_id' => $deliveryNormal->id,
            'visit_date' => today(),
            'qty_sold' => 0,
            'qty_returned_fresh' => 0,
            'qty_returned_expired' => 30,
            'amount_due' => 0,
            'amount_paid' => 0,
            'status' => 'paid',
            'is_writeoff' => false,
        ]);

        // Write-off owner LAIN — tidak boleh ikut terhitung.
        $otherOwner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $otherCluster = Cluster::create(['name' => 'Cluster Lain Loss', 'owner_id' => $otherOwner->id]);
        $otherKiosk = Kiosk::factory()->create(['cluster_id' => $otherCluster->id, 'is_active' => false]);
        $otherDelivery = Delivery::factory()->create([
            'kiosk_id' => $otherKiosk->id,
            'product_variant_id' => $variant->id,
            'qty_delivered' => 10,
        ]);
        Settlement::factory()->create([
            'delivery_id' => $otherDelivery->id,
            'visit_date' => today(),
            'qty_sold' => 0,
            'qty_returned_fresh' => 0,
            'qty_returned_expired' => 150,
            'amount_due' => 0,
            'amount_paid' => 0,
            'status' => 'paid',
            'is_writeoff' => true,
        ]);

        $response = $this->actingAs($owner)->get('/owner/dashboard');

        $response->assertOk();
        $kerugian = $response->viewData('kerugianTitipan');

        // Hanya write-off owner ini: 75 biji = 5 mika x Rp 9.500 = Rp 47.500.
        $this->assertEquals(75, $kerugian['biji']);
        $this->assertEquals(5.0, $kerugian['mika']);
        $this->assertEquals(47500, $kerugian['nilai']);
    }

    public function test_owner_dashboard_displays_real_time_trip_progress()
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $operator = User::factory()->create([
            'role' => 'operator',
            'is_active' => true,
            'name' => 'Rian',
            'owner_id' => $owner->id,
        ]);

        $cluster = Cluster::create(['name' => 'Cluster A', 'owner_id' => $owner->id]);

        $trip = Trip::factory()->create([
            'owner_id' => $owner->id,
            'operator_id' => $operator->id,
            'starting_cluster_id' => $cluster->id,
            'qty_carried_total' => 60,
            'started_at' => now(),
            'trip_date' => today()->format('Y-m-d'),
        ]);

        $kiosk = Kiosk::factory()->create([
            'cluster_id' => $cluster->id,
            'name' => 'Kios Rian Live Test',
        ]);

        // Trip milik owner lain — tidak boleh muncul di dashboard owner ini.
        $otherOwner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $otherOperator = User::factory()->create([
            'role' => 'operator',
            'is_active' => true,
            'name' => 'OperatorLain',
            'owner_id' => $otherOwner->id,
        ]);
        $otherCluster = Cluster::create(['name' => 'Cluster Rahasia', 'owner_id' => $otherOwner->id]);
        Trip::factory()->create([
            'owner_id' => $otherOwner->id,
            'operator_id' => $otherOperator->id,
            'starting_cluster_id' => $otherCluster->id,
            'qty_carried_total' => 99,
            'started_at' => now(),
            'trip_date' => today()->format('Y-m-d'),
            // index unik (trip_date, trip_number_of_day) global → pakai nomor beda
            'trip_number_of_day' => 2,
        ]);

        $response = $this->actingAs($owner)->get('/owner/dashboard');

        $response->assertOk();
        $response->assertViewHas('activeTrips');
        $response->assertSee('Rian');
        $response->assertSee('Cluster A');
        $response->assertSee('60 mika');

        // Isolasi: trip & cluster owner lain tidak bocor
        $response->assertDontSee('OperatorLain');
        $response->assertDontSee('Cluster Rahasia');
    }

    /**
     * Widget Kios Perlu Dikunjungi harus menghitung kios yang SUDAH pernah
     * dikunjungi tapi lewat interval (regresi: diffInDays Carbon 3 bertanda
     * negatif membuat kios terkunjungi tak pernah dianggap overdue).
     */
    public function test_overdue_widget_counts_visited_kiosk_past_interval()
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $owner->id]);
        $cluster = Cluster::create(['name' => 'Cluster Overdue', 'owner_id' => $owner->id]);

        $overdueKiosk = Kiosk::factory()->create([
            'cluster_id' => $cluster->id,
            'target_visit_interval_days' => 10,
        ]);
        $freshKiosk = Kiosk::factory()->create([
            'cluster_id' => $cluster->id,
            'target_visit_interval_days' => 10,
        ]);

        $pastTrip = Trip::factory()->create([
            'owner_id' => $owner->id,
            'operator_id' => $operator->id,
            'trip_date' => today()->subDays(20)->format('Y-m-d'),
            'started_at' => now()->subDays(20),
            'ended_at' => now()->subDays(20),
        ]);

        \App\Models\KioskVisit::create([
            'trip_id' => $pastTrip->id,
            'kiosk_id' => $overdueKiosk->id,
            'visited_at' => now()->subDays(20),
            'visit_action' => 'check_only',
            'extension_granted' => false,
        ]);
        \App\Models\KioskVisit::create([
            'trip_id' => $pastTrip->id,
            'kiosk_id' => $freshKiosk->id,
            'visited_at' => now()->subDays(2),
            'visit_action' => 'check_only',
            'extension_granted' => false,
        ]);

        $response = $this->actingAs($owner)->get('/owner/dashboard');

        $response->assertOk();
        // Hanya kios yang lewat interval (20 hari > 10) yang dihitung.
        $this->assertEquals(1, $response->viewData('overdueCount'));
    }

    /**
     * Prediksi dodol habis tampil di dashboard: nama kios + sisa biji + prediksi.
     */
    public function test_prediksi_habis_section_shows_checked_kiosk()
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $owner->id]);
        $cluster = Cluster::create(['name' => 'Cluster Prediksi', 'owner_id' => $owner->id]);
        $variant = ProductVariant::factory()->create(['is_active' => true, 'sale_price_per_pack' => 12000]);

        $kiosk = Kiosk::factory()->create([
            'cluster_id' => $cluster->id,
            'name' => 'Kios Prediksi Habis',
        ]);

        $trip = Trip::factory()->create([
            'owner_id' => $owner->id,
            'operator_id' => $operator->id,
            'trip_date' => today()->format('Y-m-d'),
            'started_at' => now(),
            'ended_at' => now(),
        ]);

        // Minimal 3 settlement historis agar prediksi terhitung.
        foreach (range(1, 3) as $i) {
            $delivery = Delivery::factory()->create([
                'kiosk_id' => $kiosk->id,
                'trip_id' => $trip->id,
                'product_variant_id' => $variant->id,
                'qty_delivered' => 5,
            ]);
            Settlement::factory()->create([
                'delivery_id' => $delivery->id,
                'visit_date' => today(),
                'qty_sold' => 75,
                'qty_returned_fresh' => 0,
                'qty_returned_expired' => 0,
                'amount_due' => 60000,
                'amount_paid' => 60000,
                'status' => 'paid',
            ]);
        }

        // Kunjungan cek dengan sisa biji (skenario 5).
        \App\Models\KioskVisit::create([
            'trip_id' => $trip->id,
            'kiosk_id' => $kiosk->id,
            'visited_at' => now(),
            'visit_action' => 'check_only',
            'alasan_check' => 'masih_banyak',
            'sisa_biji' => 30,
            'extension_granted' => false,
        ]);

        $response = $this->actingAs($owner)->get('/owner/dashboard');

        $response->assertOk();
        $response->assertSee('Prediksi Dodol Habis');
        $response->assertSee('Kios Prediksi Habis');
        $response->assertSee('Sisa 30 biji');

        $prediksi = $response->viewData('prediksiKios');
        $this->assertCount(1, $prediksi);
        $this->assertStringContainsString('hari lagi', $prediksi[0]['prediksi']);
    }
}
