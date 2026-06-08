<?php

namespace Tests\Feature\Operator;

use App\Livewire\Operator\ActiveTrip;
use App\Livewire\Operator\StartTrip;
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

class TripBebasFlagTest extends TestCase
{
    use RefreshDatabase;

    protected User $operator;
    protected Cluster $cluster;

    protected function setUp(): void
    {
        parent::setUp();
        $this->operator = User::factory()->create(['role' => 'operator', 'is_active' => true]);
        $this->cluster = Cluster::create(['name' => 'Cluster X']);
        $this->actingAs($this->operator);
    }

    public function test_trip_bebas_creates_trip_without_cluster(): void
    {
        Livewire::test(StartTrip::class)
            ->set('tripBebas', true)
            ->set('qtyCarried', 30)
            ->call('startTrip');

        $trip = Trip::where('operator_id', $this->operator->id)->first();
        $this->assertNotNull($trip);
        $this->assertNull($trip->starting_cluster_id);
        $this->assertEquals(30, $trip->qty_carried_total);
    }

    public function test_trip_biasa_requires_cluster(): void
    {
        Livewire::test(StartTrip::class)
            ->set('tripBebas', false)
            ->set('qtyCarried', 30)
            ->call('startTrip')
            ->assertHasErrors(['selectedClusterId']);

        $this->assertSame(0, Trip::where('operator_id', $this->operator->id)->count());
    }

    /**
     * Buat $count delivery+settlement untuk satu kios, dengan jarak hari tertentu.
     */
    private function makeSettledDeliveries(Kiosk $kiosk, ProductVariant $variant, int $count, int $daysToSettle): void
    {
        for ($i = 0; $i < $count; $i++) {
            $delivery = Delivery::factory()->create([
                'kiosk_id' => $kiosk->id,
                'product_variant_id' => $variant->id,
                'procurement_batch_id' => null,
                'qty_delivered' => 1,
            ]);
            $delivery->created_at = now()->subDays($daysToSettle);
            $delivery->save();

            Settlement::factory()->create([
                'delivery_id' => $delivery->id,
                'visit_date' => today(),
                'qty_sold' => 15,
                'qty_returned_fresh' => 0,
                'qty_returned_expired' => 0,
                'amount_due' => 12000,
                'amount_paid' => 12000,
            ]);
        }
    }

    public function test_smart_kios_flags_computed(): void
    {
        $variant = ProductVariant::factory()->create(['is_active' => true]);

        // Trip bebas (semua kios)
        $trip = Trip::factory()->create([
            'operator_id' => $this->operator->id,
            'starting_cluster_id' => null,
            'started_at' => now(),
        ]);

        // URGENT: target 5 hari, kunjungan terakhir 20 hari lalu
        $urgent = Kiosk::factory()->create([
            'cluster_id' => $this->cluster->id,
            'target_visit_interval_days' => 5,
        ]);
        KioskVisit::create([
            'trip_id' => $trip->id, 'kiosk_id' => $urgent->id,
            'visited_at' => now()->subDays(20), 'visit_action' => 'check_only', 'extension_granted' => false,
        ]);

        // KIOS BARU: first_titip_date hari ini + dikunjungi hari ini (biar tidak urgent)
        $new = Kiosk::factory()->create([
            'cluster_id' => $this->cluster->id,
            'target_visit_interval_days' => 14,
            'first_titip_date' => today(),
        ]);
        KioskVisit::create([
            'trip_id' => $trip->id, 'kiosk_id' => $new->id,
            'visited_at' => now(), 'visit_action' => 'check_only', 'extension_granted' => false,
        ]);

        // FAST MOVER: threshold 5, avg habis 0 hari (>=3 settlement)
        $fast = Kiosk::factory()->create([
            'cluster_id' => $this->cluster->id,
            'fast_mover_threshold_days' => 5,
        ]);
        $this->makeSettledDeliveries($fast, $variant, 3, 0);

        // SLOW MOVER: threshold 5, avg habis 15 hari (> 2x threshold)
        $slow = Kiosk::factory()->create([
            'cluster_id' => $this->cluster->id,
            'fast_mover_threshold_days' => 5,
        ]);
        $this->makeSettledDeliveries($slow, $variant, 3, 15);

        $flags = Livewire::test(ActiveTrip::class)->get('kioskFlags');

        $this->assertContains('urgent', $flags[$urgent->id]);
        $this->assertContains('new', $flags[$new->id]);
        $this->assertNotContains('urgent', $flags[$new->id]);
        $this->assertContains('fast_mover', $flags[$fast->id]);
        $this->assertContains('slow_mover', $flags[$slow->id]);
    }
}
