<?php

namespace Tests\Feature\Operator;

use App\Livewire\Operator\ActiveTrip;
use App\Models\Cluster;
use App\Models\Delivery;
use App\Models\Kiosk;
use App\Models\KioskVisit;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Settlement;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * AKSI 2 "Titip Cash" + BS per biji (owner 12 Juli 2026).
 *
 * Model bisnis (ground truth owner, dikonfirmasi angka):
 *  - Operator BEBAS naruh berapa mika (tak terikat jatah).
 *  - BS (biji tak layak jual) dicatat per biji.
 *  - cash_dibayar = (biji_ditaruh − biji_BS) × 800  (kedai TIDAK bayar BS).
 *  - BS = KERUGIAN owner → mekanisme SAMA dgn Stop Tanpa Tagih
 *        (settlement is_writeoff=true + qty_returned_expired) → satu laporan kerugian.
 *  - Jatah kios TIDAK berubah; titipan lama TIDAK di-settle (tetap berjalan).
 *  - Komisi = mika DITARUH penuh (TIDAK dikurangi BS).
 */
class TitipCashBsTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected User $operator;
    protected Cluster $cluster;
    protected ProductVariant $variant;
    protected Trip $trip;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create([
            'role' => 'owner', 'is_active' => true,
            'hpp_per_mika' => 9500, 'komisi_per_mika' => 1000,
        ]);
        $this->operator = User::factory()->create([
            'role' => 'operator', 'is_active' => true, 'owner_id' => $this->owner->id,
        ]);
        $this->cluster = Cluster::create(['name' => 'Cluster CashBS', 'owner_id' => $this->owner->id]);
        $product = Product::factory()->create(['owner_id' => $this->owner->id]);
        $this->variant = ProductVariant::factory()->create([
            'product_id' => $product->id, 'is_active' => true, 'sale_price_per_pack' => 12000,
        ]);
        $this->trip = Trip::factory()->create([
            'owner_id' => $this->owner->id,
            'operator_id' => $this->operator->id,
            'starting_cluster_id' => $this->cluster->id,
            'qty_carried_total' => 75,
            'started_at' => now(),
            'ended_at' => null,
            'trip_date' => today()->format('Y-m-d'),
        ]);

        $this->actingAs($this->operator);
    }

    /**
     * Titipan lama = dari SIKLUS SEBELUMNYA (trip lain yang sudah selesai), bukan trip
     * berjalan — supaya komisi basis-drop trip ini hanya menghitung mika yang ditaruh
     * hari ini, persis kondisi nyata di lapangan.
     */
    private function pendingTitipan(Kiosk $kiosk, int $mika): Delivery
    {
        $priorTrip = Trip::factory()->create([
            'owner_id' => $this->owner->id,
            'operator_id' => $this->operator->id,
            'starting_cluster_id' => $this->cluster->id,
            'started_at' => now()->subDays(3),
            'ended_at' => now()->subDays(3),
            'trip_date' => today()->subDays(3)->format('Y-m-d'),
        ]);

        return Delivery::factory()->create([
            'kiosk_id' => $kiosk->id,
            'trip_id' => $priorTrip->id,
            'qty_delivered' => $mika,
            'product_variant_id' => $this->variant->id,
            'delivery_type' => 'consignment',
        ]);
    }

    /**
     * CONTOH WAJIB (owner): jatah 4 mika (60 biji). Naruh 3 mika (45 biji). BS 3 biji.
     *   → cash = (45 − 3) × 800 = Rp 33.600
     *   → BS 3 biji = kerugian owner (is_writeoff + qty_returned_expired = 3)
     *   → jatah TETAP 4; titipan lama TIDAK di-settle; komisi = 3 mika (penuh).
     */
    public function test_titip_cash_with_bs_pays_placed_minus_bs_and_records_loss(): void
    {
        $kiosk = Kiosk::factory()->create([
            'cluster_id' => $this->cluster->id, 'is_cash_only' => false, 'default_qty_mika' => 4,
        ]);
        $pending = $this->pendingTitipan($kiosk, 4); // titipan lama 4 mika berjalan

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->call('chooseAction', 'titip_cash')
            ->set('dropBaru', 3)     // naruh 3 mika = 45 biji
            ->set('qtyBsCash', 3)    // BS 3 biji
            ->call('saveVisit')
            ->assertHasNoErrors();

        // Delivery cash_sale = 3 mika PENUH (komisi tak dikurangi BS).
        $cash = Delivery::where('kiosk_id', $kiosk->id)->where('delivery_type', 'cash_sale')->firstOrFail();
        $this->assertEquals(3, $cash->qty_delivered);

        // Settlement: cash = (45 − 3) × 800 = 33.600; BS 3 biji → write-off kerugian.
        $settlement = Settlement::where('delivery_id', $cash->id)->firstOrFail();
        $this->assertEquals(33600, (int) $settlement->amount_paid);
        $this->assertEquals(33600, (int) $settlement->amount_due);
        $this->assertEquals(42, (int) $settlement->qty_sold);              // 45 − 3
        $this->assertEquals(3, (int) $settlement->qty_returned_expired);   // BS = 3 biji
        $this->assertTrue((bool) $settlement->is_writeoff);                // reuse mekanisme kerugian

        // Jatah TETAP 4.
        $this->assertEquals(4, $kiosk->fresh()->default_qty_mika);

        // Titipan lama TIDAK di-settle (tetap berjalan).
        $this->assertTrue(Delivery::whereKey($pending->id)->doesntHave('settlement')->exists());

        // Visit self-settle ke delivery cash; jatah tak berubah.
        $visit = KioskVisit::where('kiosk_id', $kiosk->id)->firstOrFail();
        $this->assertSame('cash_sale', $visit->visit_action);
        $this->assertEquals($cash->id, (int) $visit->settled_delivery_id);
        $this->assertFalse((bool) $visit->changed_default);

        // Komisi = 3 mika DITARUH penuh (basis DROP, tidak terpengaruh BS).
        $this->assertEquals(3.0, (float) $this->trip->fresh()->mika_komisi);
        $this->assertEquals(3000.0, (float) $this->trip->fresh()->komisi_rian);
    }

    /**
     * KERUGIAN BS AKSI 2 masuk ke laporan kerugian yang SAMA dengan Stop Tanpa Tagih:
     * widget "Kerugian titipan" owner = Σ qty_returned_expired (is_writeoff) × HPP/mika.
     * 3 biji = 3/15 mika × Rp 9.500 = Rp 1.900.
     */
    public function test_bs_loss_flows_into_owner_kerugian_report(): void
    {
        $kiosk = Kiosk::factory()->create([
            'cluster_id' => $this->cluster->id, 'is_cash_only' => false, 'default_qty_mika' => 4,
        ]);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->call('chooseAction', 'titip_cash')
            ->set('dropBaru', 3)
            ->set('qtyBsCash', 3)
            ->call('saveVisit')
            ->assertHasNoErrors();

        $response = $this->actingAs($this->owner)->get('/owner/dashboard');
        $response->assertOk();
        $kerugian = $response->viewData('kerugianTitipan');

        $this->assertEquals(3, $kerugian['biji']);
        $this->assertEquals(0.2, $kerugian['mika']);
        $this->assertEquals(1900, $kerugian['nilai']);
    }

    /** REGRESI: Titip Cash TANPA BS = biji ditaruh × 800 penuh, tak ada write-off. */
    public function test_titip_cash_without_bs_pays_full(): void
    {
        $kiosk = Kiosk::factory()->create([
            'cluster_id' => $this->cluster->id, 'is_cash_only' => false, 'default_qty_mika' => 4,
        ]);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->call('chooseAction', 'titip_cash')
            ->set('dropBaru', 3)   // 45 biji, tanpa BS
            ->call('saveVisit')
            ->assertHasNoErrors();

        $cash = Delivery::where('kiosk_id', $kiosk->id)->where('delivery_type', 'cash_sale')->firstOrFail();
        $settlement = Settlement::where('delivery_id', $cash->id)->firstOrFail();

        $this->assertEquals(36000, (int) $settlement->amount_paid); // 45 × 800 penuh
        $this->assertEquals(45, (int) $settlement->qty_sold);
        $this->assertEquals(0, (int) $settlement->qty_returned_expired);
        $this->assertFalse((bool) $settlement->is_writeoff);
        $this->assertEquals(3.0, (float) $this->trip->fresh()->mika_komisi);
    }

    /** BS tidak boleh melebihi biji yang ditaruh → ditolak, tak ada yang tersimpan. */
    public function test_bs_exceeding_placed_is_rejected(): void
    {
        $kiosk = Kiosk::factory()->create([
            'cluster_id' => $this->cluster->id, 'is_cash_only' => false, 'default_qty_mika' => 4,
        ]);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->call('chooseAction', 'titip_cash')
            ->set('dropBaru', 1)     // 15 biji
            ->set('qtyBsCash', 20)   // 20 > 15
            ->call('saveVisit')
            ->assertHasErrors('general');

        $this->assertSame(0, Delivery::where('kiosk_id', $kiosk->id)->count());
        $this->assertSame(0, Settlement::count());
        $this->assertSame(0, KioskVisit::where('kiosk_id', $kiosk->id)->count());
    }

    /** UI: layar Titip Cash TIDAK menampilkan checkbox ubah-jatah, TAPI menampilkan field BS. */
    public function test_titip_cash_ui_has_bs_field_and_no_ubah_jatah(): void
    {
        $kiosk = Kiosk::factory()->create([
            'cluster_id' => $this->cluster->id, 'is_cash_only' => false, 'default_qty_mika' => 4,
        ]);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->call('chooseAction', 'titip_cash')
            ->assertSee('Barang Sisa / BS (Biji)')
            ->assertDontSee('Ubah jatah permanen');
    }
}
