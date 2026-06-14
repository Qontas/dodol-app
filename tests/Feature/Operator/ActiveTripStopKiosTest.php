<?php

namespace Tests\Feature\Operator;

use App\Livewire\Operator\ActiveTrip;
use App\Models\Cluster;
use App\Models\Delivery;
use App\Models\Kiosk;
use App\Models\KioskVisit;
use App\Models\ProductVariant;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fitur cut off / stop titipan kios oleh operator dari modal kunjungan.
 */
class ActiveTripStopKiosTest extends TestCase
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
        $this->cluster = Cluster::create(['name' => 'Cluster Stop']);
        $this->trip = Trip::factory()->create([
            'operator_id' => $this->operator->id,
            'starting_cluster_id' => $this->cluster->id,
            'qty_carried_total' => 50,
            'started_at' => now(),
            'trip_date' => today()->format('Y-m-d'),
        ]);
        $this->variant = ProductVariant::factory()->create(['is_active' => true, 'sale_price_per_pack' => 12000]);
    }

    public function test_stop_kios_deactivates_and_records_visit(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id, 'is_active' => true]);

        $this->actingAs($this->operator);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->set('stopReason', 'tutup_permanen')
            ->call('stopKios')
            ->assertHasNoErrors()
            ->assertSet('isVisitModalOpen', false);

        $kiosk->refresh();
        $this->assertFalse($kiosk->is_active);
        $this->assertNotNull($kiosk->stopped_at);
        $this->assertEquals('tutup_permanen', $kiosk->stop_reason);
        $this->assertEquals('operator', $kiosk->stopped_by);

        $visit = KioskVisit::where('trip_id', $this->trip->id)->where('kiosk_id', $kiosk->id)->first();
        $this->assertNotNull($visit);
        $this->assertEquals('check_only', $visit->visit_action);
        $this->assertEquals('stop_titipan', $visit->alasan_check);
    }

    public function test_stop_kios_requires_reason(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id, 'is_active' => true]);

        $this->actingAs($this->operator);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->call('stopKios')
            ->assertHasErrors('stopReason');

        $this->assertTrue($kiosk->fresh()->is_active);
        $this->assertEquals(0, KioskVisit::where('kiosk_id', $kiosk->id)->count());
    }

    public function test_stop_kios_reachable_from_cek_saja_action(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id, 'is_active' => true]);

        $this->actingAs($this->operator);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->call('chooseAction', 'cek')
            ->assertSet('chosenAction', 'cek')
            ->set('showStopConfirm', true)
            ->set('stopReason', 'tutup_permanen')
            ->call('stopKios')
            ->assertHasNoErrors();

        $kiosk->refresh();
        $this->assertFalse($kiosk->is_active);
        $this->assertEquals('tutup_permanen', $kiosk->stop_reason);
        $this->assertEquals('operator', $kiosk->stopped_by);

        $visit = KioskVisit::where('trip_id', $this->trip->id)->where('kiosk_id', $kiosk->id)->first();
        $this->assertNotNull($visit);
        $this->assertEquals('stop_titipan', $visit->alasan_check);
    }

    public function test_stop_kios_blocked_when_pending_delivery_exists(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id, 'is_active' => true]);
        Delivery::factory()->create([
            'kiosk_id' => $kiosk->id,
            'trip_id' => $this->trip->id,
            'qty_delivered' => 5,
            'product_variant_id' => $this->variant->id,
        ]);

        $this->actingAs($this->operator);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->set('stopReason', 'kurang_laku')
            ->call('stopKios')
            ->assertHasErrors('stopReason');

        // Kios tetap aktif, tidak ada kunjungan stop tercatat.
        $this->assertTrue($kiosk->fresh()->is_active);
        $this->assertEquals(0, KioskVisit::where('kiosk_id', $kiosk->id)->count());
    }
}
