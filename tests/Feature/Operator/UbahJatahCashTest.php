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
 * Serah Terima operator — dua jalur "nambah/ubah" yang BEDA arti bisnis:
 *   A. UBAH JATAH (permanen, dua arah) → default_qty_mika berubah, titip hari ini ikut.
 *   B. TAMBAH CASH SEKALI → dibayar tunai, jatah TETAP.
 * Plus guard: titip konsinyasi melebihi jatah TANPA "Ubah jatah" = diblokir.
 * Aturan tagihan: SELALU dari titipan LAMA, tak terpengaruh jatah baru / cash.
 */
class UbahJatahCashTest extends TestCase
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
        $this->cluster = Cluster::create(['name' => 'Cluster Jatah']);
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

    private function pendingTitipan(Kiosk $kiosk, int $mika): Delivery
    {
        return Delivery::factory()->create([
            'kiosk_id' => $kiosk->id,
            'trip_id' => $this->trip->id,
            'qty_delivered' => $mika,
            'product_variant_id' => $this->variant->id,
            'delivery_type' => 'consignment',
        ]);
    }

    /**
     * JALUR B: tambah cash sekali menyertai titipan + tagih titipan lama.
     * Jatah TETAP. Tagihan hari ini = jualan titipan LAMA (4 mika = 48.000);
     * cash 3 mika = 36.000 lunas seketika (menambah uang masuk, bukan tagihan lama).
     */
    public function test_cash_sekali_keeps_jatah_and_bills_old_titipan(): void
    {
        $kiosk = Kiosk::factory()->create([
            'cluster_id' => $this->cluster->id,
            'is_cash_only' => false,
            'default_qty_mika' => 4,
        ]);
        $pending = $this->pendingTitipan($kiosk, 4);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->set('dropBaru', 4)           // titip hari ini = jatah (tak melebihi → tak diblokir)
            ->set('pakaiCashExtra', true)  // JALUR B
            ->set('cashExtra', 3)
            ->set('uangDiterima', 48000)   // tagih titipan lama 4 mika
            ->call('saveVisit')
            ->assertHasNoErrors();

        // Jatah TIDAK berubah (jalur B).
        $this->assertEquals(4, $kiosk->fresh()->default_qty_mika);

        // Tagihan hari ini dari titipan LAMA = 48.000.
        $this->assertEquals(48000, Settlement::where('delivery_id', $pending->id)->value('amount_due'));

        // Cash sekali 3 mika = 36.000, langsung lunas.
        $cash = Delivery::where('kiosk_id', $kiosk->id)->where('delivery_type', 'cash_sale')->firstOrFail();
        $this->assertEquals(3, $cash->qty_delivered);
        $this->assertEquals(36000, Settlement::where('delivery_id', $cash->id)->value('amount_paid'));

        // Titipan baru hari ini = konsinyasi 4 (belum di-settle).
        $newTitip = Delivery::where('kiosk_id', $kiosk->id)
            ->where('delivery_type', 'consignment')
            ->where('id', '!=', $pending->id)
            ->firstOrFail();
        $this->assertEquals(4, $newTitip->qty_delivered);
        $this->assertNull(Settlement::where('delivery_id', $newTitip->id)->first());
    }

    /**
     * OVERRIDE: ubah jatah naik 2→6, tapi stok kurang → operator override titip hari
     * ini jadi 4. Yang diserahkan hari ini 4, TAPI default tetap jadi 6 (jatah baru
     * permanen walau hari ini cuma bisa serah 4). Dua nilai beda, tak boleh ketuker.
     */
    public function test_override_titip_less_than_new_jatah_keeps_new_default(): void
    {
        $kiosk = Kiosk::factory()->create([
            'cluster_id' => $this->cluster->id,
            'is_cash_only' => false,
            'default_qty_mika' => 2,
        ]);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->set('ubahJatah', true)   // auto: jatahBaru=2, dropBaru=2
            ->set('jatahBaru', 6)      // auto: dropBaru=6
            ->assertSet('dropBaru', 6)
            ->set('dropBaru', 4)       // override manual: hari ini cuma serah 4 (stok kurang)
            ->call('saveVisit')
            ->assertHasNoErrors();

        // Jatah baru permanen = 6 (walau hari ini serah 4).
        $this->assertEquals(6, $kiosk->fresh()->default_qty_mika);

        // Yang diserahkan hari ini = konsinyasi 4.
        $this->assertDatabaseHas('deliveries', [
            'kiosk_id' => $kiosk->id,
            'delivery_type' => 'consignment',
            'qty_delivered' => 4,
        ]);

        $visit = KioskVisit::where('kiosk_id', $kiosk->id)->firstOrFail();
        $this->assertTrue((bool) $visit->changed_default);
    }

    /** REGRESI: kunjungan normal (titip biasa, tanpa centang apa pun) TIDAK ubah default. */
    public function test_normal_visit_does_not_change_default(): void
    {
        $kiosk = Kiosk::factory()->create([
            'cluster_id' => $this->cluster->id,
            'is_cash_only' => false,
            'default_qty_mika' => 4,
        ]);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->set('dropBaru', 4) // titip sesuai jatah, tanpa ubahJatah / cash
            ->call('saveVisit')
            ->assertHasNoErrors();

        $this->assertEquals(4, $kiosk->fresh()->default_qty_mika);

        $visit = KioskVisit::where('kiosk_id', $kiosk->id)->firstOrFail();
        $this->assertFalse((bool) $visit->changed_default);
    }

    /**
     * BLOKIR: titip konsinyasi melebihi jatah TANPA centang "Ubah jatah" → tidak
     * tersimpan + pesan actionable. ("Titip konsinyasi > jatah" tak eksis; = salah ketik.)
     */
    public function test_block_titip_exceeds_jatah_without_ubah_jatah(): void
    {
        $kiosk = Kiosk::factory()->create([
            'cluster_id' => $this->cluster->id,
            'is_cash_only' => false,
            'default_qty_mika' => 2,
        ]);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->set('dropBaru', 5) // 5 > jatah 2, tanpa ubahJatah / cash
            ->call('saveVisit')
            ->assertHasErrors('general');

        // Tidak ada yang tersimpan (rollback / early return).
        $this->assertSame(0, KioskVisit::where('kiosk_id', $kiosk->id)->count());
        $this->assertSame(0, Delivery::where('kiosk_id', $kiosk->id)->count());
        $this->assertEquals(2, $kiosk->fresh()->default_qty_mika);
    }

    /** BLOKIR tidak menyala saat jatah kios memang besar (drop <= jatah normal). */
    public function test_no_block_when_titip_within_large_jatah(): void
    {
        $kiosk = Kiosk::factory()->create([
            'cluster_id' => $this->cluster->id,
            'is_cash_only' => false,
            'default_qty_mika' => 20,
        ]);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->set('dropBaru', 15) // <= jatah 20 → tidak diblokir
            ->call('saveVisit')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('deliveries', [
            'kiosk_id' => $kiosk->id,
            'delivery_type' => 'consignment',
            'qty_delivered' => 15,
        ]);
    }

    /** MUTUALLY-EXCLUSIVE: mengaktifkan satu jalur otomatis mematikan yang lain. */
    public function test_ubah_jatah_and_cash_are_mutually_exclusive(): void
    {
        $kiosk = Kiosk::factory()->create([
            'cluster_id' => $this->cluster->id,
            'is_cash_only' => false,
            'default_qty_mika' => 3,
        ]);

        // Aktifkan Jalur A lalu Jalur B → A mati.
        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->set('ubahJatah', true)
            ->set('jatahBaru', 5)
            ->set('pakaiCashExtra', true)
            ->assertSet('ubahJatah', false)   // Jalur A dimatikan otomatis
            ->assertSet('jatahBaru', 0);

        // Sebaliknya: aktifkan Jalur B lalu Jalur A → B mati.
        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->set('pakaiCashExtra', true)
            ->set('cashExtra', 3)
            ->set('ubahJatah', true)
            ->assertSet('pakaiCashExtra', false); // Jalur B dimatikan otomatis
    }
}
