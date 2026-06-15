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
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class CorrectVisitTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected User $operator;
    protected Cluster $cluster;
    protected Kiosk $kiosk;
    protected ProductVariant $variant;
    protected Trip $trip;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $this->operator = User::factory()->create([
            'role' => 'operator', 'is_active' => true, 'owner_id' => $this->owner->id,
        ]);
        $this->cluster = Cluster::create(['name' => 'Cluster Koreksi', 'owner_id' => $this->owner->id]);
        $this->kiosk = Kiosk::factory()->create([
            'cluster_id' => $this->cluster->id,
            'default_qty_mika' => 10,
            'first_titip_date' => today(),
            'is_active' => true,
        ]);
        $this->variant = ProductVariant::factory()->create(['is_active' => true, 'sale_price_per_pack' => 12000]);

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

    /** Buat pending titipan lama (consignment tanpa settlement) di kios. */
    private function makePendingTitipan(int $qtyMika): Delivery
    {
        return Delivery::create([
            'kiosk_id' => $this->kiosk->id,
            'trip_id' => $this->trip->id,
            'product_variant_id' => $this->variant->id,
            'source_type' => 'new_procurement',
            'delivery_type' => 'consignment',
            'qty_delivered' => $qtyMika,
            'unit_price' => 12000,
        ]);
    }

    public function test_correct_drop_mika_replaces_delivery_and_keeps_stock_consistent(): void
    {
        $component = Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $this->kiosk->id)
            ->set('dropBaru', 5)
            ->call('saveVisit')
            ->assertHasNoErrors();

        $oldVisit = KioskVisit::where('kiosk_id', $this->kiosk->id)->firstOrFail();
        $oldDelivery = Delivery::where('kiosk_id', $this->kiosk->id)->firstOrFail();
        $this->assertEquals(5, $oldDelivery->qty_delivered);
        $this->assertEquals($oldVisit->id, $oldDelivery->kiosk_visit_id);

        $component->call('correctVisit', $oldVisit->id, 3, 0, 0, 0)
            ->assertHasNoErrors();

        // Delivery lama TERHAPUS, diganti satu delivery baru qty 3.
        $this->assertNull(Delivery::find($oldDelivery->id));
        $this->assertSame(1, Delivery::where('kiosk_id', $this->kiosk->id)->count());
        $this->assertEquals(3, Delivery::where('kiosk_id', $this->kiosk->id)->value('qty_delivered'));

        // Visit lama disimpan + ditandai; hanya 1 visit aktif.
        $this->assertNotNull($oldVisit->fresh()->corrected_at);
        $this->assertSame(1, KioskVisit::active()->where('trip_id', $this->trip->id)->count());
        $newVisit = KioskVisit::active()->where('kiosk_id', $this->kiosk->id)->firstOrFail();
        $this->assertEquals($oldVisit->id, $newVisit->correction_of_visit_id);

        // Stok gudang konsisten: hanya 3 mika yang keluar (bukan 5 sisa, bukan 5+3=8).
        $stock = DB::table('v_warehouse_stock')->where('product_variant_id', $this->variant->id)->value('qty_in_warehouse');
        $this->assertEquals(-3, (int) $stock);
    }

    public function test_correct_uang_diterima_updates_settlement_and_status(): void
    {
        $titipan = $this->makePendingTitipan(4); // 4 mika = 60 biji, tagihan 48000

        $component = Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $this->kiosk->id)
            ->set('dropBaru', 0)
            ->set('uangDiterima', 20000)
            ->call('saveVisit')
            ->assertHasNoErrors();

        $settlementBefore = Settlement::where('delivery_id', $titipan->id)->firstOrFail();
        $this->assertEquals(20000, $settlementBefore->amount_paid);
        $this->assertSame('pending', $settlementBefore->status);

        $visit = KioskVisit::active()->where('kiosk_id', $this->kiosk->id)->firstOrFail();

        $component->call('correctVisit', $visit->id, 0, 0, 0, 48000)
            ->assertHasNoErrors();

        // Settlement lama dihapus, dibuat baru dengan angka benar → lunas.
        $settlementAfter = Settlement::where('delivery_id', $titipan->id)->firstOrFail();
        $this->assertNotEquals($settlementBefore->id, $settlementAfter->id);
        $this->assertEquals(48000, $settlementAfter->amount_paid);
        $this->assertSame('paid', $settlementAfter->status);
        $this->assertSame(1, Settlement::where('delivery_id', $titipan->id)->count());
    }

    public function test_omset_balance_reflects_new_numbers_not_doubled(): void
    {
        $titipan = $this->makePendingTitipan(4);

        $component = Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $this->kiosk->id)
            ->set('dropBaru', 0)
            ->set('uangDiterima', 20000)
            ->call('saveVisit')
            ->assertHasNoErrors();

        $this->assertEquals(20000.0, $this->trip->fresh()->omset_val);

        $visit = KioskVisit::active()->where('kiosk_id', $this->kiosk->id)->firstOrFail();
        $component->call('correctVisit', $visit->id, 0, 0, 0, 48000)->assertHasNoErrors();

        // Omset = angka baru saja (48000), bukan 20000+48000.
        $this->assertEquals(48000.0, $this->trip->fresh()->omset_val);
    }

    public function test_corrected_visit_excluded_from_aggregates(): void
    {
        $component = Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $this->kiosk->id)
            ->set('dropBaru', 5)
            ->call('saveVisit')
            ->assertHasNoErrors();

        $oldVisit = KioskVisit::where('kiosk_id', $this->kiosk->id)->firstOrFail();
        $component->call('correctVisit', $oldVisit->id, 3, 0, 0, 0)->assertHasNoErrors();

        // Dua baris fisik (lama + koreksi), tapi agregat hanya hitung yang aktif.
        $this->assertSame(2, KioskVisit::where('trip_id', $this->trip->id)->count());
        $this->assertSame(1, KioskVisit::active()->where('trip_id', $this->trip->id)->count());

        $trip = $this->trip->fresh();
        $this->assertSame(1, $trip->kios_baru_count);     // tidak dobel
        $this->assertEquals(3.0, $trip->mika_kios_baru);  // qty baru, bukan 5 atau 5+3
    }

    public function test_reject_correction_when_trip_ended(): void
    {
        $component = Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $this->kiosk->id)
            ->set('dropBaru', 5)
            ->call('saveVisit')
            ->assertHasNoErrors();

        $visit = KioskVisit::where('kiosk_id', $this->kiosk->id)->firstOrFail();
        $this->trip->update(['ended_at' => now(), 'ended_reason' => 'target_done']);

        $component->call('correctVisit', $visit->id, 3, 0, 0, 0)
            ->assertHasErrors('correction');

        // Tidak ada perubahan: delivery tetap qty 5, visit belum dikoreksi.
        $this->assertEquals(5, Delivery::where('kiosk_id', $this->kiosk->id)->value('qty_delivered'));
        $this->assertNull($visit->fresh()->corrected_at);
    }

    public function test_reject_correction_when_not_latest_visit(): void
    {
        $component = Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $this->kiosk->id)
            ->set('dropBaru', 5)
            ->call('saveVisit')
            ->assertHasNoErrors();

        $oldVisit = KioskVisit::where('kiosk_id', $this->kiosk->id)->firstOrFail();

        // Kunjungan aktif lebih baru ke kios yang sama.
        KioskVisit::create([
            'trip_id' => $this->trip->id,
            'kiosk_id' => $this->kiosk->id,
            'visited_at' => now()->addMinutes(5),
            'visit_action' => 'check_only',
            'extension_granted' => false,
        ]);

        $component->call('correctVisit', $oldVisit->id, 3, 0, 0, 0)
            ->assertHasErrors('correction');

        $this->assertNull($oldVisit->fresh()->corrected_at);
    }

    public function test_reject_correction_when_visit_changed_default(): void
    {
        $kiosk = Kiosk::factory()->create([
            'cluster_id' => $this->cluster->id,
            'default_qty_mika' => 2,
            'is_active' => true,
        ]);

        $component = Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->set('dropBaru', 5)
            ->set('extraDropMode', 'konsinyasi') // konsinyasi penuh → naikkan default
            ->call('saveVisit')
            ->assertHasNoErrors();

        $visit = KioskVisit::where('kiosk_id', $kiosk->id)->firstOrFail();
        $this->assertTrue((bool) $visit->changed_default);
        $this->assertEquals(5, $kiosk->fresh()->default_qty_mika);

        $component->call('correctVisit', $visit->id, 3, 0, 0, 0)
            ->assertHasErrors('correction');

        $this->assertNull($visit->fresh()->corrected_at);
    }

    public function test_old_titipan_becomes_active_during_reversal(): void
    {
        // drop_and_settle: settle titipan lama 4 mika + drop baru 6 mika.
        $titipan = $this->makePendingTitipan(4);

        $component = Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $this->kiosk->id)
            ->set('dropBaru', 6)
            ->set('uangDiterima', 48000)
            ->call('saveVisit')
            ->assertHasNoErrors();

        $visit = KioskVisit::active()->where('kiosk_id', $this->kiosk->id)->firstOrFail();
        $this->assertEquals($titipan->id, $visit->settled_delivery_id);
        $oldSettlementId = Settlement::where('delivery_id', $titipan->id)->value('id');
        $this->assertNotNull($oldSettlementId);

        $component->call('correctVisit', $visit->id, 4, 0, 0, 32000)
            ->assertHasNoErrors();

        // Titipan lama dipertahankan (id sama), settlement lama dihapus & dibuat ulang
        // (id berbeda) → membuktikan titipan sempat aktif lagi lalu di-settle ulang.
        $this->assertNotNull(Delivery::find($titipan->id));
        $newSettlement = Settlement::where('delivery_id', $titipan->id)->firstOrFail();
        $this->assertNotEquals($oldSettlementId, $newSettlement->id);
        $this->assertSame(1, Settlement::where('delivery_id', $titipan->id)->count());
        $this->assertEquals(32000, $newSettlement->amount_paid);

        // Drop baru juga diganti: qty 4 (bukan 6).
        $newDrop = Delivery::where('kiosk_id', $this->kiosk->id)
            ->where('delivery_type', 'consignment')
            ->where('id', '!=', $titipan->id)
            ->value('qty_delivered');
        $this->assertEquals(4, $newDrop);
    }
}
