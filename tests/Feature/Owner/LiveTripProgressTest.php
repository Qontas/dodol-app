<?php

namespace Tests\Feature\Owner;

use App\Livewire\Owner\LiveTripProgress;
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
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Laporan trip real-time owner (wire:poll 30s): angka harus identik dengan
 * accessor finansial Trip, tapi dihitung tanpa N+1 (di-poll terus-menerus).
 */
class LiveTripProgressTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected User $operator;
    protected Cluster $cluster;
    protected ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $this->operator = User::factory()->create([
            'role' => 'operator',
            'is_active' => true,
            'owner_id' => $this->owner->id,
        ]);
        $this->cluster = Cluster::create(['name' => 'Cluster Live', 'owner_id' => $this->owner->id]);
        $this->variant = ProductVariant::factory()->create(['is_active' => true, 'sale_price_per_pack' => 12000]);
    }

    protected function makeActiveTripWithActivity(): Trip
    {
        $trip = Trip::factory()->create([
            'owner_id' => $this->owner->id,
            'operator_id' => $this->operator->id,
            'starting_cluster_id' => $this->cluster->id,
            'qty_carried_total' => 50,
            'started_at' => now(),
            'ended_at' => null,
            'trip_date' => today()->format('Y-m-d'),
        ]);

        // Kios lama: settle titipan trip lampau → omset masuk trip aktif ini.
        $kioskLama = Kiosk::factory()->create(['cluster_id' => $this->cluster->id]);
        $pastTrip = Trip::factory()->create([
            'owner_id' => $this->owner->id,
            'operator_id' => $this->operator->id,
            'trip_date' => today()->subDays(3)->format('Y-m-d'),
            'started_at' => now()->subDays(3),
            'ended_at' => now()->subDays(3),
        ]);
        $settledDelivery = Delivery::factory()->create([
            'kiosk_id' => $kioskLama->id,
            'trip_id' => $pastTrip->id,
            'qty_delivered' => 8,
            'product_variant_id' => $this->variant->id,
        ]);
        Settlement::create([
            'delivery_id' => $settledDelivery->id,
            'visit_date' => today(),
            'qty_sold' => 105,
            'qty_returned_fresh' => 10,
            'qty_returned_expired' => 5,
            'amount_due' => 84000,
            'amount_paid' => 84000,
        ]);
        KioskVisit::create([
            'trip_id' => $trip->id,
            'kiosk_id' => $kioskLama->id,
            'visited_at' => now()->subMinutes(20),
            'visit_action' => 'settle_only',
            'settled_delivery_id' => $settledDelivery->id,
            'extension_granted' => false,
        ]);

        // Kios baru hari ini: drop perdana → komisi kios baru Rp 1.000/mika.
        $kioskBaru = Kiosk::factory()->create([
            'cluster_id' => $this->cluster->id,
            'first_titip_date' => today(),
        ]);
        $newDelivery = Delivery::factory()->create([
            'kiosk_id' => $kioskBaru->id,
            'trip_id' => $trip->id,
            'qty_delivered' => 5,
            'product_variant_id' => $this->variant->id,
        ]);
        KioskVisit::create([
            'trip_id' => $trip->id,
            'kiosk_id' => $kioskBaru->id,
            'visited_at' => now()->subMinutes(10),
            'visit_action' => 'drop_only',
            'new_delivery_id' => $newDelivery->id,
            'extension_granted' => false,
        ]);

        return $trip;
    }

    /**
     * Omset & komisi yang ditampilkan identik dengan accessor finansial Trip
     * (sumber kebenaran perhitungan, dipakai juga di laporan akhir trip).
     */
    public function test_displayed_totals_match_trip_accessors(): void
    {
        $trip = $this->makeActiveTripWithActivity();

        // Sanity: accessor menghitung 105 biji = 7 mika; komisi 7x500 + 5x1000.
        $this->assertEquals(84000.0, $trip->omset_val);
        $this->assertEquals(8500.0, $trip->komisi_rian);

        $this->actingAs($this->owner);

        Livewire::test(LiveTripProgress::class)
            ->assertSee('Rp ' . number_format($trip->omset_val, 0, ',', '.'))
            ->assertSee('Rp ' . number_format($trip->komisi_rian, 0, ',', '.'));
    }

    /**
     * Kios yang belum dikunjungi tampil; milik owner lain tidak ikut dihitung.
     */
    public function test_unvisited_kiosks_scoped_to_owner(): void
    {
        $this->makeActiveTripWithActivity();
        $belumDikunjungi = Kiosk::factory()->create([
            'cluster_id' => $this->cluster->id,
            'name' => 'Kios Belum Disambangi',
        ]);

        // Kios owner lain — tidak boleh muncul/terhitung di progress owner ini.
        $otherOwner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $otherCluster = Cluster::create(['name' => 'Cluster Lain', 'owner_id' => $otherOwner->id]);
        Kiosk::factory()->create(['cluster_id' => $otherCluster->id, 'name' => 'Kios Owner Lain']);

        $this->actingAs($this->owner);

        Livewire::test(LiveTripProgress::class)
            ->assertSee('Kios Belum Disambangi')
            ->assertDontSee('Kios Owner Lain');
    }

    /**
     * Anti N+1 untuk halaman yang di-poll tiap 30 detik: jumlah query konstan
     * (eager load), tidak bertambah mengikuti jumlah kios/kunjungan.
     */
    public function test_render_query_count_is_bounded(): void
    {
        $this->makeActiveTripWithActivity();
        Kiosk::factory()->count(10)->create(['cluster_id' => $this->cluster->id]);

        $this->actingAs($this->owner);

        DB::enableQueryLog();
        Livewire::test(LiveTripProgress::class);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(15, $queryCount, "Render live progress memakai {$queryCount} query (harusnya konstan <= 15).");
    }
}
