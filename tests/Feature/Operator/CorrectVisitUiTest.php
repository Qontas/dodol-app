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

class CorrectVisitUiTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected User $operator;
    protected Cluster $cluster;
    protected Kiosk $kiosk;
    protected Trip $trip;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $this->operator = User::factory()->create([
            'role' => 'operator', 'is_active' => true, 'owner_id' => $this->owner->id,
        ]);
        $this->cluster = Cluster::create(['name' => 'Cluster UI', 'owner_id' => $this->owner->id]);
        $this->kiosk = Kiosk::factory()->create([
            'cluster_id' => $this->cluster->id, 'default_qty_mika' => 10, 'is_active' => true,
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

    private function pendingTitipan(int $qtyMika): Delivery
    {
        return Delivery::create([
            'kiosk_id' => $this->kiosk->id,
            'trip_id' => $this->trip->id,
            'product_variant_id' => ProductVariant::first()->id,
            'source_type' => 'new_procurement',
            'delivery_type' => 'consignment',
            'qty_delivered' => $qtyMika,
            'unit_price' => 12000,
        ]);
    }

    public function test_open_correction_modal_prefills_old_drop_number(): void
    {
        $component = Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $this->kiosk->id)
            ->set('dropBaru', 5)
            ->call('saveVisit')
            ->assertHasNoErrors();

        $component->call('openCorrectionModal', $this->kiosk->id)
            ->assertHasNoErrors()
            ->assertSet('isCorrectionModalOpen', true)
            ->assertSet('correctionHasDrop', true)
            ->assertSet('correctionHasSettle', false)
            ->assertSet('dropBaru', 5); // angka lama ke-isi
    }

    public function test_open_correction_modal_prefills_settle_numbers(): void
    {
        $this->pendingTitipan(4); // 60 biji, tagihan 48000

        $component = Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $this->kiosk->id)
            ->set('dropBaru', 0)
            ->set('returnFresh', 15)
            ->set('uangDiterima', 20000)
            ->call('saveVisit')
            ->assertHasNoErrors();

        $component->call('openCorrectionModal', $this->kiosk->id)
            ->assertHasNoErrors()
            ->assertSet('isCorrectionModalOpen', true)
            ->assertSet('correctionHasSettle', true)
            ->assertSet('uangDiterima', 20000)
            ->assertSet('returnFresh', 15);
    }

    public function test_submit_correction_updates_numbers_and_closes_modal(): void
    {
        $component = Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $this->kiosk->id)
            ->set('dropBaru', 5)
            ->call('saveVisit')
            ->assertHasNoErrors();

        $component->call('openCorrectionModal', $this->kiosk->id)
            ->set('dropBaru', 3)
            ->call('submitCorrection')
            ->assertHasNoErrors()
            ->assertSet('isCorrectionModalOpen', false);

        $this->assertSame(1, Delivery::where('kiosk_id', $this->kiosk->id)->count());
        $this->assertEquals(3, Delivery::where('kiosk_id', $this->kiosk->id)->value('qty_delivered'));
        $this->assertSame(1, KioskVisit::active()->where('trip_id', $this->trip->id)->count());
    }

    public function test_correction_rejected_for_walk_in_sentinel(): void
    {
        // Catat walk-in (membuat kios sentinel + visit cash_sale).
        $component = Livewire::test(ActiveTrip::class)
            ->set('walkInMika', 4)
            ->call('saveWalkInCash')
            ->assertHasNoErrors();

        $sentinel = Kiosk::where('name', Kiosk::WALKIN_SENTINEL_NAME)->firstOrFail();

        $component->call('openCorrectionModal', $sentinel->id)
            ->assertHasErrors('correction')
            ->assertSet('isCorrectionModalOpen', false);
    }

    public function test_correction_rejected_when_visit_changed_default(): void
    {
        $kiosk = Kiosk::factory()->create([
            'cluster_id' => $this->cluster->id, 'default_qty_mika' => 2, 'is_active' => true,
        ]);

        $component = Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->set('dropBaru', 5)
            ->set('extraDropMode', 'konsinyasi')
            ->call('saveVisit')
            ->assertHasNoErrors();

        $component->call('openCorrectionModal', $kiosk->id)
            ->assertHasErrors('correction')
            ->assertSet('isCorrectionModalOpen', false);
    }

    public function test_correction_button_visible_only_for_visited_kiosk(): void
    {
        $component = Livewire::test(ActiveTrip::class);

        // Belum dikunjungi → tidak ada tombol Koreksi untuk kios ini.
        $component->assertDontSee('openCorrectionModal');

        $component->call('openVisitModal', $this->kiosk->id)
            ->set('dropBaru', 5)
            ->call('saveVisit')
            ->assertHasNoErrors();

        // Sudah dikunjungi → tombol Koreksi muncul.
        $component->assertSee('Koreksi')
            ->assertSeeHtml('openCorrectionModal(' . $this->kiosk->id . ')');
    }
}
