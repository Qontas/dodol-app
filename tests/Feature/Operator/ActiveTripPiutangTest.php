<?php

namespace Tests\Feature\Operator;

use App\Livewire\Operator\ActiveTrip;
use App\Models\Cluster;
use App\Models\Delivery;
use App\Models\Kiosk;
use App\Models\ProductVariant;
use App\Models\Settlement;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TAHAP 3: "titip baru + bayar lama nanti" (Tagih+Titip uang kurang = piutang) +
 * alur pelunasan piutang (terimaPembayaranPiutang). Opsi A: omzet tetap tanggal asli.
 */
class ActiveTripPiutangTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected User $operator;
    protected Cluster $cluster;
    protected Trip $trip;
    protected ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $this->operator = User::factory()->create([
            'role' => 'operator', 'is_active' => true, 'owner_id' => $this->owner->id,
        ]);
        $this->cluster = Cluster::create(['name' => 'Cluster Piutang', 'owner_id' => $this->owner->id]);
        $productPiutang = \App\Models\Product::factory()->create(['owner_id' => $this->owner->id]);
        $this->variant = ProductVariant::factory()->create(['product_id' => $productPiutang->id, 'is_active' => true, 'sale_price_per_pack' => 12000]);
        $this->trip = Trip::factory()->create([
            'operator_id' => $this->operator->id, 'owner_id' => $this->owner->id,
            'starting_cluster_id' => $this->cluster->id, 'qty_carried_total' => 60,
            'started_at' => now(), 'trip_date' => today()->format('Y-m-d'),
        ]);
    }

    private function kioskWithPending(int $qty = 5): array
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id]);
        $past = Trip::factory()->create([
            'operator_id' => $this->operator->id, 'owner_id' => $this->owner->id,
            'trip_date' => today()->subDays(3)->format('Y-m-d'),
            'started_at' => today()->subDays(3), 'ended_at' => today()->subDays(3),
        ]);
        $delivery = Delivery::factory()->create([
            'kiosk_id' => $kiosk->id, 'trip_id' => $past->id,
            'qty_delivered' => $qty, 'product_variant_id' => $this->variant->id,
        ]);

        return [$kiosk, $delivery];
    }

    /** (a) Tagih+Titip uang<tagihan → Settlement pending (piutang) + new delivery + notes janji. */
    public function test_tagih_titip_uang_kurang_creates_piutang_with_janji_notes(): void
    {
        [$kiosk, $pending] = $this->kioskWithPending(5); // 75 biji → tagihan 60000

        $this->actingAs($this->operator);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->call('chooseAction', 'tagih_titip')
            ->set('returnFresh', 0)
            ->set('returnExpired', 0)
            ->set('uangDiterima', 20000)   // kurang dari 60000 → sisa 40000 piutang
            ->set('janjiBayar', 'besok sore')
            ->set('dropBaru', 3)           // titip baru wajib > 0 (Tahap 1)
            ->call('saveVisit')
            ->assertHasNoErrors();

        // Settlement piutang atas titipan lama.
        $s = Settlement::where('delivery_id', $pending->id)->first();
        $this->assertNotNull($s);
        $this->assertEquals(60000, (int) $s->amount_due);
        $this->assertEquals(20000, (int) $s->amount_paid);
        $this->assertEquals('pending', $s->status);
        $this->assertEquals('Janji bayar: besok sore', $s->notes);

        // Titip baru = delivery baru yang pending (doesntHave settlement).
        $newPending = Delivery::where('kiosk_id', $kiosk->id)->where('id', '!=', $pending->id)
            ->doesntHave('settlement')->first();
        $this->assertNotNull($newPending);
        $this->assertEquals(3, (int) $newPending->qty_delivered);
    }

    /** (b) Banner piutang muncul saat kunjungan berikutnya. */
    public function test_piutang_banner_shown_next_visit(): void
    {
        [$kiosk, $pending] = $this->kioskWithPending(5);
        Settlement::factory()->create([
            'delivery_id' => $pending->id, 'visit_date' => today()->subDays(3),
            'qty_sold' => 75, 'qty_returned_fresh' => 0, 'qty_returned_expired' => 0,
            'amount_due' => 60000, 'amount_paid' => 20000, 'status' => 'pending',
            'notes' => 'Janji bayar: besok',
        ]);

        $this->actingAs($this->operator);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->assertSet('piutangLama', 40000)
            ->assertSet('piutangJanji', 'Janji bayar: besok');
    }

    /** (c) Pelunasan parsial → status tetap pending; penuh → status paid, piutang 0. */
    public function test_terima_pembayaran_partial_then_full(): void
    {
        [$kiosk, $pending] = $this->kioskWithPending(5);
        $s = Settlement::factory()->create([
            'delivery_id' => $pending->id, 'visit_date' => today()->subDays(3),
            'qty_sold' => 75, 'qty_returned_fresh' => 0, 'qty_returned_expired' => 0,
            'amount_due' => 60000, 'amount_paid' => 20000, 'status' => 'pending',
        ]);

        $this->actingAs($this->operator);

        $c = Livewire::test(ActiveTrip::class)->call('openVisitModal', $kiosk->id);

        // Parsial: bayar 10000 → sisa 30000, status tetap pending.
        $c->set('piutangBayar', 10000)->call('terimaPembayaranPiutang')->assertHasNoErrors()
          ->assertSet('piutangLama', 30000);
        $s->refresh();
        $this->assertEquals(30000, (int) $s->amount_paid);
        $this->assertEquals('pending', $s->status);

        // Pelunasan penuh sisa 30000 → status paid, piutang 0.
        $c->set('piutangBayar', 30000)->call('terimaPembayaranPiutang')->assertHasNoErrors()
          ->assertSet('piutangLama', 0);
        $s->refresh();
        $this->assertEquals(60000, (int) $s->amount_paid);
        $this->assertEquals('paid', $s->status);
        $this->assertNotNull($s->paid_at);
    }

    /** (c) Overpay ditolak. */
    public function test_terima_pembayaran_overpay_rejected(): void
    {
        [$kiosk, $pending] = $this->kioskWithPending(5);
        $s = Settlement::factory()->create([
            'delivery_id' => $pending->id, 'visit_date' => today()->subDays(3),
            'qty_sold' => 75, 'qty_returned_fresh' => 0, 'qty_returned_expired' => 0,
            'amount_due' => 60000, 'amount_paid' => 20000, 'status' => 'pending',
        ]);

        $this->actingAs($this->operator);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->set('piutangBayar', 999999)
            ->call('terimaPembayaranPiutang')
            ->assertHasErrors('piutang');

        $s->refresh();
        $this->assertEquals(20000, (int) $s->amount_paid); // tak berubah
        $this->assertEquals('pending', $s->status);
    }

    /** 🔒 GATE KEAMANAN: operator TIDAK boleh melunasi piutang kios OWNER LAIN. */
    public function test_terima_pembayaran_rejects_other_owner_settlement(): void
    {
        // Owner B + kios B + piutang B.
        $ownerB = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $clusterB = Cluster::create(['name' => 'Cluster B', 'owner_id' => $ownerB->id]);
        $kioskB = Kiosk::factory()->create(['cluster_id' => $clusterB->id]);
        $tripB = Trip::factory()->create([
            'operator_id' => $ownerB->id, 'owner_id' => $ownerB->id,
            'trip_date' => today()->subDays(5)->format('Y-m-d'),
            'started_at' => today()->subDays(5), 'ended_at' => today()->subDays(5),
        ]);
        $deliveryB = Delivery::factory()->create([
            'kiosk_id' => $kioskB->id, 'trip_id' => $tripB->id,
            'qty_delivered' => 5, 'product_variant_id' => $this->variant->id,
        ]);
        $settlementB = Settlement::factory()->create([
            'delivery_id' => $deliveryB->id, 'visit_date' => today()->subDays(5),
            'qty_sold' => 75, 'qty_returned_fresh' => 0, 'qty_returned_expired' => 0,
            'amount_due' => 60000, 'amount_paid' => 0, 'status' => 'pending',
        ]);

        // Operator milik owner A mencoba buka kios B + melunasi piutang B.
        $this->actingAs($this->operator);

        $c = Livewire::test(ActiveTrip::class)->call('openVisitModal', $kioskB->id);
        // A tak boleh LIHAT piutang B (scope owner).
        $c->assertSet('piutangLama', 0);
        // Coba lunasi → ditolak (tak ada piutang dalam scope A).
        $c->set('piutangBayar', 60000)->call('terimaPembayaranPiutang')->assertHasErrors('piutang');

        // Settlement B TIDAK tersentuh.
        $settlementB->refresh();
        $this->assertEquals(0, (int) $settlementB->amount_paid);
        $this->assertEquals('pending', $settlementB->status);
    }

    /** (e) Opsi A: pelunasan TIDAK mengubah visit_date (omzet tetap tanggal asli). */
    public function test_pelunasan_keeps_original_visit_date(): void
    {
        [$kiosk, $pending] = $this->kioskWithPending(5);
        $origDate = today()->subDays(3)->toDateString();
        $s = Settlement::factory()->create([
            'delivery_id' => $pending->id, 'visit_date' => $origDate,
            'qty_sold' => 75, 'qty_returned_fresh' => 0, 'qty_returned_expired' => 0,
            'amount_due' => 60000, 'amount_paid' => 0, 'status' => 'pending',
        ]);

        $this->actingAs($this->operator);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->set('piutangBayar', 60000)
            ->call('terimaPembayaranPiutang')
            ->assertHasNoErrors();

        $s->refresh();
        $this->assertEquals($origDate, $s->visit_date->toDateString()); // visit_date TIDAK pindah ke hari ini
        $this->assertEquals('paid', $s->status);
    }
}
