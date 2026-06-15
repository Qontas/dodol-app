<?php

namespace Tests\Feature\Operator;

use App\Http\Controllers\Owner\MonthlyReportController;
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

class WalkInCashSaleTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected User $operator;
    protected Cluster $cluster;
    protected Kiosk $realKiosk;
    protected Trip $trip;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $this->operator = User::factory()->create([
            'role' => 'operator', 'is_active' => true, 'owner_id' => $this->owner->id,
        ]);
        $this->cluster = Cluster::create(['name' => 'Cluster Nyata', 'owner_id' => $this->owner->id]);
        $this->realKiosk = Kiosk::factory()->create([
            'cluster_id' => $this->cluster->id, 'name' => 'Kios Nyata', 'is_active' => true,
        ]);
        ProductVariant::factory()->create(['is_active' => true, 'sale_price_per_pack' => 12000]);

        $this->trip = Trip::factory()->create([
            'owner_id' => $this->owner->id,
            'operator_id' => $this->operator->id,
            'starting_cluster_id' => $this->cluster->id,
            'qty_carried_total' => 50,
            'trip_date' => today()->format('Y-m-d'),
            'started_at' => now(),
            'ended_at' => null,
        ]);

        $this->actingAs($this->operator);
    }

    public function test_walk_in_cash_creates_paid_sale_at_hidden_sentinel(): void
    {
        Livewire::test(ActiveTrip::class)
            ->call('openWalkInModal')
            ->assertSet('isWalkInModalOpen', true)
            ->set('walkInMika', 4)
            ->call('saveWalkInCash')
            ->assertHasNoErrors()
            ->assertSet('isWalkInModalOpen', false);

        // Sentinel cluster + kios dibuat, ter-scope ke owner, tersembunyi.
        $sentinelCluster = Cluster::where('name', Kiosk::WALKIN_CLUSTER_PREFIX . $this->owner->id)->first();
        $this->assertNotNull($sentinelCluster);
        $this->assertSame($this->owner->id, $sentinelCluster->owner_id);
        $this->assertFalse((bool) $sentinelCluster->is_active);

        $sentinel = Kiosk::where('name', Kiosk::WALKIN_SENTINEL_NAME)->first();
        $this->assertNotNull($sentinel);
        $this->assertSame($sentinelCluster->id, $sentinel->cluster_id);
        $this->assertFalse((bool) $sentinel->is_active);
        $this->assertTrue((bool) $sentinel->is_cash_only);

        // Delivery cash_sale + settlement lunas: 4 * 15 * 800 = 48000.
        $delivery = Delivery::where('kiosk_id', $sentinel->id)->first();
        $this->assertNotNull($delivery);
        $this->assertSame('cash_sale', $delivery->delivery_type);
        $this->assertEquals(4, $delivery->qty_delivered);

        $settlement = Settlement::where('delivery_id', $delivery->id)->first();
        $this->assertEquals(48000, $settlement->amount_due);
        $this->assertEquals(48000, $settlement->amount_paid);
        $this->assertSame('paid', $settlement->status);

        // KioskVisit cash_sale menunjuk delivery sebagai new + settled.
        $visit = KioskVisit::where('kiosk_id', $sentinel->id)->first();
        $this->assertSame('cash_sale', $visit->visit_action);
        $this->assertSame($delivery->id, $visit->settled_delivery_id);
    }

    public function test_walk_in_omset_counts_toward_commission(): void
    {
        Livewire::test(ActiveTrip::class)
            ->set('walkInMika', 4)
            ->call('saveWalkInCash')
            ->assertHasNoErrors();

        $trip = $this->trip->fresh();

        // Omset & mika terjual memuat walk-in (dihitung per-trip lewat settled_delivery_id).
        $this->assertSame(48000.0, $trip->omset_val);
        $this->assertSame(4.0, $trip->mika_terjual);
        // Komisi reguler ikut naik (bukan 0) → omset walk-in masuk komisi.
        $this->assertGreaterThan(0, $trip->komisi_reguler);
        // Bukan kios baru (visit_action cash_sale, bukan drop_only).
        $this->assertSame(0.0, $trip->mika_kios_baru);
    }

    public function test_sentinel_hidden_from_trip_kiosk_list(): void
    {
        Livewire::test(ActiveTrip::class)
            ->set('walkInMika', 3)
            ->call('saveWalkInCash')
            ->assertHasNoErrors()
            ->assertSee('Kios Nyata')
            ->assertDontSee(Kiosk::WALKIN_SENTINEL_NAME);
    }

    public function test_sentinel_excluded_from_per_kiosk_report_but_omset_kept(): void
    {
        Livewire::test(ActiveTrip::class)
            ->set('walkInMika', 5)
            ->call('saveWalkInCash')
            ->assertHasNoErrors();

        $data = MonthlyReportController::buildMonthlyData(now()->format('Y-m'), $this->owner->id);

        // Sentinel tidak muncul di frekuensi per-kios maupun hitungan kios ter-settle.
        $namaKios = collect($data['analisisKios']['frekuensi'])->pluck('kiosk')->all();
        $this->assertNotContains(Kiosk::WALKIN_SENTINEL_NAME, $namaKios);
        $this->assertSame(0, $data['analisisKios']['total_kios_settled']);

        // Tapi omset walk-in (5 * 15 * 800 = 60000) tetap masuk omset harian.
        $totalOmsetHarian = collect($data['dailyOmset'])->sum('total');
        $this->assertSame(60000, $totalOmsetHarian);
    }

    public function test_walk_in_requires_at_least_one_mika(): void
    {
        Livewire::test(ActiveTrip::class)
            ->set('walkInMika', 0)
            ->call('saveWalkInCash')
            ->assertHasErrors(['walkInMika']);

        $this->assertSame(0, Delivery::where('delivery_type', 'cash_sale')->count());
    }
}
