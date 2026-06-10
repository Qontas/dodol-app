<?php

namespace Tests\Feature\Operator;

use App\Livewire\Operator\ActiveTrip;
use App\Models\Cluster;
use App\Models\Delivery;
use App\Models\Kiosk;
use App\Models\KioskVisit;
use App\Models\ProductVariant;
use App\Models\Settlement;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ActiveTripAdvancedScenarioTest extends TestCase
{
    use RefreshDatabase;

    protected User $operator;
    protected Cluster $cluster;
    protected Trip $trip;
    protected ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = User::factory()->create(['role' => 'operator', 'is_active' => true]);
        $this->cluster = Cluster::create(['name' => 'Cluster Test']);
        $this->trip = Trip::factory()->create([
            'operator_id' => $this->operator->id,
            'starting_cluster_id' => $this->cluster->id,
            'qty_carried_total' => 50,
            'started_at' => now(),
            'trip_date' => today()->format('Y-m-d'),
        ]);
        $this->variant = ProductVariant::factory()->create(['is_active' => true, 'sale_price_per_pack' => 12000]);
    }

    /** Skenario 5: check_only menyimpan alasan_check + sisa_biji. */
    public function test_check_only_stores_alasan_and_sisa_biji()
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id]);

        $this->actingAs($this->operator);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->set('dropBaru', 0)
            ->set('alasanCheck', 'kios_tutup')
            ->set('sisaBiji', 30)
            ->call('saveVisit')
            ->assertHasNoErrors();

        $visit = KioskVisit::where('kiosk_id', $kiosk->id)->first();

        $this->assertEquals('check_only', $visit->visit_action);
        $this->assertEquals('kios_tutup', $visit->alasan_check);
        $this->assertEquals(30, $visit->sisa_biji);
    }

    /** Skenario 4: settle + turunkanDefault menurunkan default_qty_mika kios. */
    public function test_settle_with_turunkan_default_lowers_default_qty()
    {
        $kiosk = Kiosk::factory()->create([
            'cluster_id' => $this->cluster->id,
            'default_qty_mika' => 8,
        ]);

        // Pending delivery 4 mika = 60 biji.
        Delivery::factory()->create([
            'kiosk_id' => $kiosk->id,
            'trip_id' => $this->trip->id,
            'qty_delivered' => 4,
            'product_variant_id' => $this->variant->id,
        ]);

        $this->actingAs($this->operator);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->set('returnFresh', 0)
            ->set('returnExpired', 0)
            ->set('uangDiterima', 48000) // 60 biji x 800
            ->set('turunkanDefault', true)
            ->set('qtyDefaultBaru', 3)
            ->call('hitungTagihan')
            ->call('saveVisit')
            ->assertHasNoErrors();

        $this->assertEquals(3, $kiosk->fresh()->default_qty_mika);
    }

    /** Skenario 4 guard: default baru >= default sekarang tidak menurunkan apa pun. */
    public function test_turunkan_default_ignored_when_not_smaller()
    {
        $kiosk = Kiosk::factory()->create([
            'cluster_id' => $this->cluster->id,
            'default_qty_mika' => 5,
        ]);

        Delivery::factory()->create([
            'kiosk_id' => $kiosk->id,
            'trip_id' => $this->trip->id,
            'qty_delivered' => 4,
            'product_variant_id' => $this->variant->id,
        ]);

        $this->actingAs($this->operator);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->set('uangDiterima', 48000)
            ->set('turunkanDefault', true)
            ->set('qtyDefaultBaru', 9) // >= 5, harus diabaikan
            ->call('hitungTagihan')
            ->call('saveVisit')
            ->assertHasNoErrors();

        $this->assertEquals(5, $kiosk->fresh()->default_qty_mika);
    }

    /** Prediksi habis: tanpa historis cukup (<3 settlement) -> "Data belum cukup". */
    public function test_prediksi_habis_data_belum_cukup_without_history()
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id]);

        KioskVisit::create([
            'trip_id' => $this->trip->id,
            'kiosk_id' => $kiosk->id,
            'visited_at' => now(),
            'visit_action' => 'check_only',
            'sisa_biji' => 30,
        ]);

        $this->assertEquals('Data belum cukup', $kiosk->fresh()->prediksi_habis);
    }

    /** Prediksi habis: tanpa kunjungan check_only -> null. */
    public function test_prediksi_habis_null_without_check_visit()
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id]);

        $this->assertNull($kiosk->fresh()->prediksi_habis);
    }

    /** Skenario 7: BS redistribusi = delivery terpisah (titipan, tanpa settlement). */
    public function test_drop_with_bs_redistribution_creates_separate_delivery()
    {
        $kiosk = Kiosk::factory()->create([
            'cluster_id' => $this->cluster->id,
            'default_qty_mika' => 10, // tinggi agar drop 5 tidak ke-split cash/konsinyasi
        ]);

        $this->actingAs($this->operator);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->set('dropBaru', 5)
            ->set('adaBsRedistribusi', true)
            ->set('qtyBsMika', 3)
            ->call('saveVisit')
            ->assertHasNoErrors();

        // Delivery konsinyasi normal (dari stok baru).
        $this->assertDatabaseHas('deliveries', [
            'kiosk_id' => $kiosk->id,
            'trip_id' => $this->trip->id,
            'delivery_type' => 'consignment',
            'qty_delivered' => 5,
        ]);

        // Delivery BS redistribusi terpisah, HPP 0.
        $this->assertDatabaseHas('deliveries', [
            'kiosk_id' => $kiosk->id,
            'trip_id' => $this->trip->id,
            'delivery_type' => 'bs_redistribution',
            'qty_delivered' => 3,
            'cost_snapshot' => 0,
        ]);

        // Titipan konsinyasi biasa → tidak ada settlement saat drop.
        $this->assertEquals(0, Settlement::count());

        // Counter trip + total drop real (exclude BS).
        $this->assertEquals(3, $this->trip->fresh()->qty_bs_redistributed);
        $this->assertEquals(5, $this->trip->fresh()->getTotalDropReal());
    }
}
