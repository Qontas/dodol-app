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

class CashDeliveryTest extends TestCase
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
        $this->cluster = Cluster::create(['name' => 'Cluster Cash']);
        $this->trip = Trip::factory()->create([
            'operator_id' => $this->operator->id,
            'starting_cluster_id' => $this->cluster->id,
            'qty_carried_total' => 50,
            'started_at' => now(),
            'trip_date' => today()->format('Y-m-d'),
        ]);
        $this->variant = ProductVariant::factory()->create(['is_active' => true, 'sale_price_per_pack' => 12000]);

        $this->actingAs($this->operator);
    }

    public function test_cash_only_kiosk_creates_paid_cash_sale(): void
    {
        $kiosk = Kiosk::factory()->create([
            'cluster_id' => $this->cluster->id,
            'is_cash_only' => true,
            'default_qty_mika' => 3,
        ]);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->assertSet('isCashOnly', true)
            ->set('dropBaru', 5)
            ->call('saveVisit')
            ->assertHasNoErrors();

        $visit = KioskVisit::where('kiosk_id', $kiosk->id)->first();
        $this->assertNotNull($visit);
        $this->assertSame('cash_sale', $visit->visit_action);
        $this->assertNotNull($visit->new_delivery_id);
        $this->assertSame($visit->new_delivery_id, $visit->settled_delivery_id);

        // Satu delivery cash_sale qty 5
        $this->assertDatabaseHas('deliveries', [
            'id' => $visit->new_delivery_id,
            'delivery_type' => 'cash_sale',
            'qty_delivered' => 5,
        ]);

        // Settlement langsung lunas: 5 mika * 15 * 800 = 60000
        $settlement = Settlement::where('delivery_id', $visit->new_delivery_id)->first();
        $this->assertNotNull($settlement);
        $this->assertEquals(60000, $settlement->amount_due);
        $this->assertEquals(60000, $settlement->amount_paid);
        $this->assertSame('paid', $settlement->status);

        // Tidak ada pendingDelivery untuk kunjungan berikutnya (sudah ter-settle)
        $this->assertSame(0, Delivery::where('kiosk_id', $kiosk->id)->doesntHave('settlement')->count());
    }

    public function test_drop_extra_cash_splits_into_consignment_and_cash(): void
    {
        $kiosk = Kiosk::factory()->create([
            'cluster_id' => $this->cluster->id,
            'is_cash_only' => false,
            'default_qty_mika' => 2,
        ]);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->set('dropBaru', 5) // 2 konsinyasi + 3 cash
            ->call('saveVisit')
            ->assertHasNoErrors();

        $deliveries = Delivery::where('kiosk_id', $kiosk->id)->get();
        $this->assertCount(2, $deliveries);

        $consignment = $deliveries->firstWhere('delivery_type', 'consignment');
        $cash = $deliveries->firstWhere('delivery_type', 'cash_sale');

        $this->assertNotNull($consignment);
        $this->assertNotNull($cash);
        $this->assertEquals(2, $consignment->qty_delivered);
        $this->assertEquals(3, $cash->qty_delivered);

        // Settlement hanya untuk delivery cash (lunas), konsinyasi masih pending
        $this->assertNull(Settlement::where('delivery_id', $consignment->id)->first());
        $cashSettlement = Settlement::where('delivery_id', $cash->id)->first();
        $this->assertNotNull($cashSettlement);
        $this->assertEquals(36000, $cashSettlement->amount_paid); // 3 * 15 * 800
        $this->assertSame('paid', $cashSettlement->status);

        // KioskVisit menunjuk ke delivery konsinyasi sebagai titipan baru
        $visit = KioskVisit::where('kiosk_id', $kiosk->id)->first();
        $this->assertSame('drop_only', $visit->visit_action);
        $this->assertSame($consignment->id, $visit->new_delivery_id);
    }

    public function test_drop_within_default_no_split(): void
    {
        $kiosk = Kiosk::factory()->create([
            'cluster_id' => $this->cluster->id,
            'is_cash_only' => false,
            'default_qty_mika' => 5,
        ]);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->set('dropBaru', 5) // sama dengan default → tidak ada cash extra
            ->call('saveVisit')
            ->assertHasNoErrors();

        $deliveries = Delivery::where('kiosk_id', $kiosk->id)->get();
        $this->assertCount(1, $deliveries);
        $this->assertSame('consignment', $deliveries->first()->delivery_type);
        $this->assertEquals(5, $deliveries->first()->qty_delivered);
        $this->assertSame(0, Settlement::count());
    }
}
