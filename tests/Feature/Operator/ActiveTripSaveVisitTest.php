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

class ActiveTripSaveVisitTest extends TestCase
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

    /**
     * 1. drop_only: Verifikasi record tabel deliveries bertambah, qty_delivered sesuai, tidak ada record settlement.
     */
    public function test_save_visit_drop_only()
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id]);

        $this->actingAs($this->operator);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->set('dropBaru', 5)
            ->call('saveVisit')
            ->assertHasNoErrors();

        // Verifikasi KioskVisit
        $visit = KioskVisit::where('trip_id', $this->trip->id)
            ->where('kiosk_id', $kiosk->id)
            ->first();

        $this->assertNotNull($visit);
        $this->assertEquals('drop_only', $visit->visit_action);
        $this->assertNotNull($visit->new_delivery_id);
        $this->assertNull($visit->settled_delivery_id);

        // Verifikasi delivery tercipta
        $this->assertDatabaseHas('deliveries', [
            'id' => $visit->new_delivery_id,
            'kiosk_id' => $kiosk->id,
            'trip_id' => $this->trip->id,
            'qty_delivered' => 5,
        ]);

        // Verifikasi tidak ada settlement baru
        $this->assertEquals(0, Settlement::count());
    }

    /**
     * 2. settle_only: Verifikasi record tabel settlements bertambah, qty_sold, return_fresh, return_expired, amount_paid tercatat benar, tidak ada record deliveries baru.
     */
    public function test_save_visit_settle_only()
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id]);

        // Buat pending delivery sebelumnya yang belum di-settle
        $pastTrip = Trip::factory()->create([
            'operator_id' => $this->operator->id,
            'trip_date' => today()->subDays(2)->format('Y-m-d'),
        ]);
        $pendingDelivery = Delivery::factory()->create([
            'kiosk_id' => $kiosk->id,
            'trip_id' => $pastTrip->id,
            'qty_delivered' => 8, // 8 mika * 15 biji = 120 biji
            'product_variant_id' => $this->variant->id,
        ]);

        $this->actingAs($this->operator);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->set('returnFresh', 10)
            ->set('returnExpired', 5)
            ->set('uangDiterima', 84000) // 120 biji - 15 retur = 105 terjual * 800 per biji = 84000
            ->call('hitungTagihan')
            ->call('saveVisit')
            ->assertHasNoErrors();

        // Verifikasi KioskVisit
        $visit = KioskVisit::where('trip_id', $this->trip->id)
            ->where('kiosk_id', $kiosk->id)
            ->first();

        $this->assertNotNull($visit);
        $this->assertEquals('settle_only', $visit->visit_action);
        $this->assertNull($visit->new_delivery_id);
        $this->assertEquals($pendingDelivery->id, $visit->settled_delivery_id);

        // Verifikasi settlement tercipta
        $this->assertDatabaseHas('settlements', [
            'delivery_id' => $pendingDelivery->id,
            'qty_sold' => 105,
            'qty_returned_fresh' => 10,
            'qty_returned_expired' => 5,
            'amount_due' => 84000,
            'amount_paid' => 84000,
        ]);

        // Verifikasi tidak ada delivery baru di trip ini
        $this->assertEquals(1, Delivery::count()); // Hanya pending delivery sebelumnya
    }

    /**
     * 3. drop_and_settle: Verifikasi KEDUA record (deliveries dan settlements) tercipta dan saling berelasi ke kiosk_visit_id yang sama.
     */
    public function test_save_visit_drop_and_settle()
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id]);

        // Buat pending delivery
        $pendingDelivery = Delivery::factory()->create([
            'kiosk_id' => $kiosk->id,
            'trip_id' => $this->trip->id,
            'qty_delivered' => 4, // 4 mika * 15 biji = 60 biji
            'product_variant_id' => $this->variant->id,
        ]);

        $this->actingAs($this->operator);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->set('returnFresh', 0)
            ->set('returnExpired', 0)
            ->set('uangDiterima', 48000) // 60 terjual * 800 = 48000
            ->set('dropBaru', 6)
            ->call('hitungTagihan')
            ->call('saveVisit')
            ->assertHasNoErrors();

        // Verifikasi KioskVisit
        $visit = KioskVisit::where('trip_id', $this->trip->id)
            ->where('kiosk_id', $kiosk->id)
            ->first();

        $this->assertNotNull($visit);
        $this->assertEquals('drop_and_settle', $visit->visit_action);
        $this->assertNotNull($visit->new_delivery_id);
        $this->assertEquals($pendingDelivery->id, $visit->settled_delivery_id);

        // Verifikasi new delivery tercipta
        $this->assertDatabaseHas('deliveries', [
            'id' => $visit->new_delivery_id,
            'kiosk_id' => $kiosk->id,
            'trip_id' => $this->trip->id,
            'qty_delivered' => 6,
        ]);

        // Verifikasi settlement tercipta
        $this->assertDatabaseHas('settlements', [
            'delivery_id' => $pendingDelivery->id,
            'qty_sold' => 60,
            'qty_returned_fresh' => 0,
            'qty_returned_expired' => 0,
            'amount_due' => 48000,
            'amount_paid' => 48000,
        ]);
    }

    /**
     * 4. check_only: Verifikasi HANYA record KioskVisit yang tercipta, deliveries dan settlements kosong.
     */
    public function test_save_visit_check_only()
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id]);

        $this->actingAs($this->operator);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->set('dropBaru', 0)
            ->call('saveVisit')
            ->assertHasNoErrors();

        // Verifikasi KioskVisit
        $visit = KioskVisit::where('trip_id', $this->trip->id)
            ->where('kiosk_id', $kiosk->id)
            ->first();

        $this->assertNotNull($visit);
        $this->assertEquals('check_only', $visit->visit_action);
        $this->assertNull($visit->new_delivery_id);
        $this->assertNull($visit->settled_delivery_id);

        // Verifikasi tidak ada delivery dan settlement baru
        $this->assertEquals(0, Delivery::count());
        $this->assertEquals(0, Settlement::count());
    }
}
