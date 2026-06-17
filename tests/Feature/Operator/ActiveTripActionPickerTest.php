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
 * Layar pilih aksi di modal kunjungan (UX 2 langkah). chosenAction hanya
 * mengatur tampilan — visit_action tersimpan tetap dari auto-detect, jadi
 * semua kalkulasi finansial tidak berubah.
 */
class ActiveTripActionPickerTest extends TestCase
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
        $this->cluster = Cluster::create(['name' => 'Cluster Picker']);
        $this->trip = Trip::factory()->create([
            'operator_id' => $this->operator->id,
            'starting_cluster_id' => $this->cluster->id,
            'qty_carried_total' => 50,
            'started_at' => now(),
            'trip_date' => today()->format('Y-m-d'),
        ]);
        $this->variant = ProductVariant::factory()->create(['is_active' => true, 'sale_price_per_pack' => 12000]);
    }

    public function test_modal_opens_on_action_picker_for_normal_kiosk(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id]);

        $this->actingAs($this->operator);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->assertSet('chosenAction', null)
            ->assertSee('Mau ngapain di kios ini?')
            ->assertSee('Titip Baru')
            ->assertSee('Cek Saja');
    }

    public function test_cash_only_kiosk_skips_action_picker(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id, 'is_cash_only' => true]);

        $this->actingAs($this->operator);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->assertSet('chosenAction', 'cash')
            ->assertDontSee('Mau ngapain di kios ini?');
    }

    public function test_pending_kiosk_shows_tagih_options_not_titip_only(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id]);
        Delivery::factory()->create([
            'kiosk_id' => $kiosk->id,
            'trip_id' => $this->trip->id,
            'qty_delivered' => 5,
            'product_variant_id' => $this->variant->id,
        ]);

        $this->actingAs($this->operator);

        $component = Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->assertSee('Tagih + Titip Baru')
            ->assertSee('Tunda Bayar');

        // 'titip' tidak valid untuk kios bertitipan → diabaikan.
        $component->call('chooseAction', 'titip')
            ->assertSet('chosenAction', null)
            // 'cek' (Cek Sisa) kini DIIZINKAN untuk kios bertitipan (catat sisa
            // tanpa menyentuh titipan).
            ->call('chooseAction', 'cek')
            ->assertSet('chosenAction', 'cek');
    }

    public function test_choose_tunda_sets_extension_and_clears_drop(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id]);
        $pending = Delivery::factory()->create([
            'kiosk_id' => $kiosk->id,
            'trip_id' => $this->trip->id,
            'qty_delivered' => 5,
            'product_variant_id' => $this->variant->id,
        ]);

        $this->actingAs($this->operator);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->call('chooseAction', 'tunda')
            ->assertSet('chosenAction', 'tunda')
            ->assertSet('extensionGranted', true)
            ->assertSet('dropBaru', 0)
            ->call('saveVisit')
            ->assertHasNoErrors();

        $visit = KioskVisit::where('trip_id', $this->trip->id)->where('kiosk_id', $kiosk->id)->first();
        $this->assertNotNull($visit);
        $this->assertTrue((bool) $visit->extension_granted);
        $this->assertEquals($pending->id, $visit->settled_delivery_id);
        // Tunda bayar = settlement DITUNDA, tidak ada uang tercatat.
        $this->assertEquals(0, Settlement::count());
    }

    public function test_back_to_picker_resets_extension_and_inputs(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id]);
        Delivery::factory()->create([
            'kiosk_id' => $kiosk->id,
            'trip_id' => $this->trip->id,
            'qty_delivered' => 5,
            'product_variant_id' => $this->variant->id,
        ]);

        $this->actingAs($this->operator);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->call('chooseAction', 'tunda')
            ->assertSet('extensionGranted', true)
            ->call('backToActionPicker')
            ->assertSet('chosenAction', null)
            ->assertSet('extensionGranted', false)
            ->assertSet('dropBaru', 0);
    }

    public function test_full_flow_tagih_titip_via_picker_creates_settlement_and_delivery(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id]);
        $pending = Delivery::factory()->create([
            'kiosk_id' => $kiosk->id,
            'trip_id' => $this->trip->id,
            'qty_delivered' => 4, // 60 biji
            'product_variant_id' => $this->variant->id,
        ]);

        $this->actingAs($this->operator);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->call('chooseAction', 'tagih_titip')
            ->set('returnFresh', 0)
            ->set('returnExpired', 0)
            ->set('uangDiterima', 48000)
            ->set('dropBaru', 6)
            ->call('hitungTagihan')
            ->call('saveVisit')
            ->assertHasNoErrors();

        $visit = KioskVisit::where('trip_id', $this->trip->id)->where('kiosk_id', $kiosk->id)->first();
        $this->assertEquals('drop_and_settle', $visit->visit_action);
        $this->assertEquals($pending->id, $visit->settled_delivery_id);
        $this->assertDatabaseHas('settlements', [
            'delivery_id' => $pending->id,
            'qty_sold' => 60,
            'amount_paid' => 48000,
        ]);
        $this->assertDatabaseHas('deliveries', [
            'id' => $visit->new_delivery_id,
            'qty_delivered' => 6,
        ]);
    }
}
