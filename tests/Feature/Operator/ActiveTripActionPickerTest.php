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
            ->assertSee('Cek Sisa')        // "Tunda Bayar" dilebur ke Cek Sisa (Tahap 2)
            ->assertDontSee('Tunda Bayar');

        // 'titip' tidak valid untuk kios bertitipan → diabaikan.
        $component->call('chooseAction', 'titip')
            ->assertSet('chosenAction', null)
            // 'cek' (Cek Sisa) kini DIIZINKAN untuk kios bertitipan (catat sisa
            // tanpa menyentuh titipan).
            ->call('chooseAction', 'cek')
            ->assertSet('chosenAction', 'cek');
    }

    /** Tahap 2: "Tunda Bayar" dicabut dari menu + chooseAction('tunda') ditolak. */
    public function test_tunda_action_removed_and_rejected(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id]);
        Delivery::factory()->create([
            'kiosk_id' => $kiosk->id, 'trip_id' => $this->trip->id,
            'qty_delivered' => 5, 'product_variant_id' => $this->variant->id,
        ]);

        $this->actingAs($this->operator);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->assertDontSee('Tunda Bayar')
            ->call('chooseAction', 'tunda')
            ->assertSet('chosenAction', null);
    }

    /** Tahap 2: Cek Sisa alasan "belum bisa bayar" = defer (check_only, titipan tetap pending, notes). */
    public function test_belum_bisa_bayar_defers_titipan_as_check_only(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id]);
        $pending = Delivery::factory()->create([
            'kiosk_id' => $kiosk->id, 'trip_id' => $this->trip->id,
            'qty_delivered' => 5, 'product_variant_id' => $this->variant->id,
        ]);

        $this->actingAs($this->operator);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->call('chooseAction', 'cek')
            ->set('alasanCheck', 'belum_bisa_bayar')
            ->set('janjiBayar', 'besok sore')
            ->set('sisaBiji', 30)
            ->call('saveVisit')
            ->assertHasNoErrors();

        $visit = KioskVisit::where('trip_id', $this->trip->id)->where('kiosk_id', $kiosk->id)->first();
        $this->assertNotNull($visit);
        $this->assertEquals('check_only', $visit->visit_action);
        $this->assertEquals('belum_bisa_bayar', $visit->alasan_check);
        $this->assertEquals(30, $visit->sisa_biji);
        $this->assertEquals('Janji bayar: besok sore', $visit->notes);
        $this->assertFalse((bool) $visit->extension_granted);
        // Realisasi B: titipan TETAP pending — TIDAK ada settlement.
        $this->assertEquals(0, Settlement::count());
        $this->assertTrue(Delivery::whereKey($pending->id)->doesntHave('settlement')->exists());
    }

    public function test_back_to_picker_resets_inputs(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id]);
        Delivery::factory()->create([
            'kiosk_id' => $kiosk->id, 'trip_id' => $this->trip->id,
            'qty_delivered' => 5, 'product_variant_id' => $this->variant->id,
        ]);

        $this->actingAs($this->operator);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->call('chooseAction', 'cek')
            ->set('alasanCheck', 'belum_bisa_bayar')
            ->set('janjiBayar', 'besok')
            ->call('backToActionPicker')
            ->assertSet('chosenAction', null)
            ->assertSet('janjiBayar', '')
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

    /** Pengunci (a): "Tagih Saja" dicabut dari UI + chooseAction('tagih') ditolak. */
    public function test_tagih_saja_option_removed_and_rejected(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id]);
        Delivery::factory()->create([
            'kiosk_id' => $kiosk->id, 'trip_id' => $this->trip->id,
            'qty_delivered' => 5, 'product_variant_id' => $this->variant->id,
        ]);

        $this->actingAs($this->operator);

        $component = Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->assertSee('Tagih + Titip Baru')
            ->assertDontSee('Tagih Saja');

        // 'tagih' tidak lagi di whitelist → diabaikan, tetap di layar pilih aksi.
        $component->call('chooseAction', 'tagih')
            ->assertSet('chosenAction', null);
    }

    /** Pengunci (b): "Tagih + Titip" wajib drop > 0 — Simpan ke-disable saat drop=0. */
    public function test_tagih_titip_requires_drop_to_save(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id]);
        Delivery::factory()->create([
            'kiosk_id' => $kiosk->id, 'trip_id' => $this->trip->id,
            'qty_delivered' => 5, 'product_variant_id' => $this->variant->id,
        ]);

        $this->actingAs($this->operator);

        $component = Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->call('chooseAction', 'tagih_titip')
            ->assertSet('chosenAction', 'tagih_titip');

        // drop=0 → tombol "Isi jumlah titipan dulu" + arahan ke Hentikan Kedai.
        $component->assertSee('Isi jumlah titipan dulu')
            ->assertSee('Hentikan Kedai')
            ->assertDontSee('Simpan Kunjungan');

        // isi titipan → tombol aktif lagi.
        $component->set('dropBaru', 3)
            ->assertSee('Simpan Kunjungan')
            ->assertDontSee('Isi jumlah titipan dulu');
    }

    /** Pengunci (c): Cek Sisa (drop=0) — biasa & "belum bisa bayar" — TIDAK ke-blok guard drop. */
    public function test_cek_not_blocked_by_drop_guard(): void
    {
        $this->actingAs($this->operator);

        // Cek "belum bisa bayar" (drop=0) tetap bisa disimpan → check_only, titipan pending.
        $k1 = Kiosk::factory()->create(['cluster_id' => $this->cluster->id]);
        Delivery::factory()->create([
            'kiosk_id' => $k1->id, 'trip_id' => $this->trip->id,
            'qty_delivered' => 5, 'product_variant_id' => $this->variant->id,
        ]);
        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $k1->id)
            ->call('chooseAction', 'cek')
            ->set('alasanCheck', 'belum_bisa_bayar')
            ->set('janjiBayar', 'lusa')
            ->assertSet('dropBaru', 0)
            ->call('saveVisit')
            ->assertHasNoErrors();
        $this->assertDatabaseHas('kiosk_visits', [
            'kiosk_id' => $k1->id, 'visit_action' => 'check_only', 'alasan_check' => 'belum_bisa_bayar',
        ]);

        // Cek biasa (alasan lain, drop=0) → check_only TANPA notes janji bayar.
        $k2 = Kiosk::factory()->create(['cluster_id' => $this->cluster->id]);
        Delivery::factory()->create([
            'kiosk_id' => $k2->id, 'trip_id' => $this->trip->id,
            'qty_delivered' => 5, 'product_variant_id' => $this->variant->id,
        ]);
        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $k2->id)
            ->call('chooseAction', 'cek')
            ->set('alasanCheck', 'kios_tutup')
            ->call('saveVisit')
            ->assertHasNoErrors();
        $visit2 = KioskVisit::where('kiosk_id', $k2->id)->first();
        $this->assertEquals('check_only', $visit2->visit_action);
        $this->assertNull($visit2->notes); // alasan lain → tanpa janji bayar
        // Tidak ada settlement (Cek tak menutup titipan).
        $this->assertEquals(0, Settlement::count());
    }
}
