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

/**
 * 🔒 GATE MULTI-TENANT OPERATOR (LANGKAH 1 darurat) — ActiveTrip.
 *
 * Menutup 4 lubang WRITE/READ lintas-tenant: operator owner A TIDAK boleh
 * membuka/menulis/menghentikan kios milik owner B, walau $kioskId / $selectedKiosk
 * dipaksa dari klien (snapshot Livewire di-tamper). Plus happy-path: operator
 * tetap normal atas kios SENDIRI saat owner_id di-set (skenario produksi asli).
 */
class ActiveTripCrossTenantTest extends TestCase
{
    use RefreshDatabase;

    protected User $ownerA;
    protected User $operatorA;
    protected Cluster $clusterA;
    protected Trip $tripA;
    protected ProductVariant $variant;

    // Dunia owner B (korban serangan).
    protected User $ownerB;
    protected Cluster $clusterB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ownerA = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $this->operatorA = User::factory()->create([
            'role' => 'operator', 'is_active' => true, 'owner_id' => $this->ownerA->id,
        ]);
        $this->clusterA = Cluster::create(['name' => 'Area A', 'owner_id' => $this->ownerA->id]);
        $this->tripA = Trip::factory()->create([
            'operator_id' => $this->operatorA->id,
            'starting_cluster_id' => $this->clusterA->id,
            'qty_carried_total' => 50,
            'started_at' => now(),
            'trip_date' => today()->format('Y-m-d'),
        ]);
        $this->variant = ProductVariant::factory()->create(['is_active' => true, 'sale_price_per_pack' => 12000]);

        $this->ownerB = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $this->clusterB = Cluster::create(['name' => 'Area B', 'owner_id' => $this->ownerB->id]);
    }

    private function kioskB(bool $active = true): Kiosk
    {
        return Kiosk::factory()->create(['cluster_id' => $this->clusterB->id, 'is_active' => $active]);
    }

    private function kioskA(bool $active = true): Kiosk
    {
        return Kiosk::factory()->create(['cluster_id' => $this->clusterA->id, 'is_active' => $active]);
    }

    private function pendingDeliveryFor(Kiosk $kiosk, Trip $trip, int $mika = 5): Delivery
    {
        return Delivery::factory()->create([
            'kiosk_id' => $kiosk->id,
            'trip_id' => $trip->id,
            'qty_delivered' => $mika,
            'product_variant_id' => $this->variant->id,
            'delivery_type' => 'consignment',
        ]);
    }

    // ===================== LUBANG 1: openVisitModal (read + setup) =====================
    public function test_operator_cannot_open_visit_modal_of_other_owners_kiosk(): void
    {
        $kioskB = $this->kioskB();

        $this->actingAs($this->operatorA);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kioskB->id)
            ->assertHasErrors('general')
            ->assertSet('isVisitModalOpen', false)
            ->assertSet('selectedKiosk', null);

        // Tidak ada kunjungan tercipta di kios owner B.
        $this->assertEquals(0, KioskVisit::where('kiosk_id', $kioskB->id)->count());
    }

    // ===================== LUBANG 2: saveVisit (write) =====================
    public function test_operator_cannot_save_visit_to_other_owners_kiosk(): void
    {
        $kioskB = $this->kioskB();

        $this->actingAs($this->operatorA);

        // Paksa selectedKiosk ke kios owner B (simulasi snapshot Livewire di-tamper).
        Livewire::test(ActiveTrip::class)
            ->set('selectedKiosk', $kioskB)
            ->set('dropBaru', 5)
            ->call('saveVisit')
            ->assertHasErrors('general');

        // NOL tulisan untuk kios owner B.
        $this->assertEquals(0, KioskVisit::where('kiosk_id', $kioskB->id)->count());
        $this->assertEquals(0, Delivery::where('kiosk_id', $kioskB->id)->count());
        $this->assertEquals(0, Settlement::count());
    }

    // ===================== LUBANG 3: stopWithSettle (write, matikan + settle) =====================
    public function test_operator_cannot_stop_settle_other_owners_kiosk(): void
    {
        $kioskB = $this->kioskB(active: true);
        $tripB = Trip::factory()->create([
            'operator_id' => $this->ownerB->id, // trip di dunia owner B
            'trip_date' => today()->format('Y-m-d'),
        ]);
        $deliveryB = $this->pendingDeliveryFor($kioskB, $tripB, 5);

        $this->actingAs($this->operatorA);

        // Serangan lengkap: selectedKiosk + pendingDelivery owner B dipaksa masuk.
        Livewire::test(ActiveTrip::class)
            ->set('selectedKiosk', $kioskB)
            ->set('pendingDelivery', $deliveryB)
            ->call('startStop')
            ->call('chooseStopMode', 'tagih')
            ->set('returnFresh', 0)
            ->set('returnExpired', 0)
            ->set('uangDiterima', 0)
            ->set('stopReason', 'pemilik_minta_stop')
            ->call('requestStopConfirm')
            ->call('executeStop')
            ->assertHasErrors('general');

        // Kios owner B TIDAK dimatikan; titipan owner B TIDAK di-settle.
        $kioskB->refresh();
        $this->assertTrue($kioskB->is_active, 'Kios owner B tidak boleh dimatikan operator owner lain.');
        $this->assertNull($kioskB->stopped_at);
        $this->assertEquals(0, Settlement::where('delivery_id', $deliveryB->id)->count());
    }

    // ===================== LUBANG 4: stopWithoutSettle (write, matikan + writeoff) =====================
    public function test_operator_cannot_stop_without_settle_other_owners_kiosk(): void
    {
        $kioskB = $this->kioskB(active: true);

        $this->actingAs($this->operatorA);

        Livewire::test(ActiveTrip::class)
            ->set('selectedKiosk', $kioskB)
            ->call('startStop')
            ->call('chooseStopMode', 'tanpa_tagih')
            ->set('stopReason', 'tutup_permanen')
            ->call('requestStopConfirm')
            ->call('executeStop')
            ->assertHasErrors('general');

        // Kios owner B tetap aktif; tidak ada write-off/kerugian palsu.
        $kioskB->refresh();
        $this->assertTrue($kioskB->is_active, 'Kios owner B tidak boleh dimatikan operator owner lain.');
        $this->assertNull($kioskB->stopped_at);
        $this->assertEquals(0, KioskVisit::where('kiosk_id', $kioskB->id)->count());
        $this->assertEquals(0, Settlement::count());
    }

    // ===================== HAPPY PATH (owner_id di-set = skenario produksi) =====================
    public function test_happy_path_operator_can_open_and_save_visit_on_own_kiosk(): void
    {
        $kioskA = $this->kioskA();

        $this->actingAs($this->operatorA);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kioskA->id)
            ->assertHasNoErrors()
            ->assertSet('isVisitModalOpen', true)
            ->set('dropBaru', 5)
            ->call('saveVisit')
            ->assertHasNoErrors();

        $visit = KioskVisit::where('trip_id', $this->tripA->id)
            ->where('kiosk_id', $kioskA->id)
            ->first();
        $this->assertNotNull($visit, 'Kunjungan kios sendiri harus tetap tersimpan.');
        $this->assertEquals('drop_only', $visit->visit_action);
        $this->assertDatabaseHas('deliveries', [
            'kiosk_id' => $kioskA->id,
            'trip_id' => $this->tripA->id,
            'qty_delivered' => 5,
        ]);
    }

    public function test_happy_path_operator_can_stop_own_kiosk(): void
    {
        $kioskA = $this->kioskA();

        $this->actingAs($this->operatorA);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kioskA->id)
            ->call('startStop')
            ->call('chooseStopMode', 'tanpa_tagih')
            ->set('stopReason', 'tutup_permanen')
            ->call('requestStopConfirm')
            ->call('executeStop')
            ->assertHasNoErrors()
            ->assertSet('isVisitModalOpen', false);

        $kioskA->refresh();
        $this->assertFalse($kioskA->is_active, 'Operator harus tetap bisa menghentikan kios sendiri.');
        $this->assertEquals('tutup_permanen', $kioskA->stop_reason);
    }
}
