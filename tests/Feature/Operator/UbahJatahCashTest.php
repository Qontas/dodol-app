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
 * Serah Terima 3-AKSI (owner 11 Juli 2026):
 *   AKSI 1 "Tagih + Titip Ulang" — titip HARUS = jatah, kecuali centang "Ubah jatah"
 *     (satu-angka: angka titip = jatah baru). Blokir 2-langkah kalau titip != jatah.
 *   AKSI 2 "Titip Cash" — naruh ekstra dibayar tunai, TIDAK nagih titipan lama, jatah TETAP.
 * Aturan tagihan AKSI 1: SELALU dari titipan LAMA.
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
     * AKSI 2 "Titip Cash": naruh 3 mika dibayar tunai. Titipan lama (4 mika) TIDAK
     * disentuh (tetap pending, tak ada settlement), jatah TETAP 4. Cash 3 mika lunas.
     */
    public function test_titip_cash_keeps_jatah_and_does_not_bill_old_titipan(): void
    {
        $kiosk = Kiosk::factory()->create([
            'cluster_id' => $this->cluster->id,
            'is_cash_only' => false,
            'default_qty_mika' => 4,
        ]);
        $pending = $this->pendingTitipan($kiosk, 4);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->call('chooseAction', 'titip_cash')
            ->set('dropBaru', 3)           // naruh cash 3 mika
            ->call('saveVisit')
            ->assertHasNoErrors();

        // Jatah TIDAK berubah.
        $this->assertEquals(4, $kiosk->fresh()->default_qty_mika);

        // Titipan lama TIDAK di-settle (tetap pending).
        $this->assertNull(Settlement::where('delivery_id', $pending->id)->first());
        $this->assertTrue(Delivery::whereKey($pending->id)->doesntHave('settlement')->exists());

        // Cash 3 mika = 36.000 lunas.
        $cash = Delivery::where('kiosk_id', $kiosk->id)->where('delivery_type', 'cash_sale')->firstOrFail();
        $this->assertEquals(3, $cash->qty_delivered);
        $this->assertEquals(36000, Settlement::where('delivery_id', $cash->id)->value('amount_paid'));

        // Visit = cash_sale; komisi terhitung (drop cash).
        $visit = KioskVisit::where('kiosk_id', $kiosk->id)->firstOrFail();
        $this->assertSame('cash_sale', $visit->visit_action);
    }

    /**
     * AKSI 2 TIDAK punya ubah-jatah lagi (owner 12 Juli 2026): Titip Cash bebas naruh
     * berapa saja & jatah kedai TETAP — meski flag ubahJatah dipaksa true dari klien,
     * jatah tidak berubah dan visit tidak menandai changed_default.
     */
    public function test_titip_cash_never_changes_jatah_even_if_flag_forced(): void
    {
        $kiosk = Kiosk::factory()->create([
            'cluster_id' => $this->cluster->id,
            'is_cash_only' => false,
            'default_qty_mika' => 4,
        ]);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->call('chooseAction', 'titip_cash')
            ->set('dropBaru', 6)
            ->set('ubahJatah', true) // diakali dari klien → HARUS diabaikan di AKSI 2
            ->call('saveVisit')
            ->assertHasNoErrors();

        $this->assertEquals(4, $kiosk->fresh()->default_qty_mika); // jatah TETAP
        $visit = KioskVisit::where('kiosk_id', $kiosk->id)->firstOrFail();
        $this->assertFalse((bool) $visit->changed_default);
    }

    /**
     * AKSI 1 + UBAH JATAH satu-angka (naik 2→6): angka titip = jatah baru. Default jadi 6,
     * konsinyasi hari ini 6 mika. Tagihan tetap dari titipan LAMA (4 mika = 48.000).
     */
    public function test_ubah_jatah_single_angka_naik(): void
    {
        $kiosk = Kiosk::factory()->create([
            'cluster_id' => $this->cluster->id,
            'is_cash_only' => false,
            'default_qty_mika' => 2,
        ]);
        $pending = $this->pendingTitipan($kiosk, 4);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->call('chooseAction', 'tagih_titip')
            ->set('dropBaru', 6)       // satu angka: titip 6 = jatah baru
            ->set('ubahJatah', true)
            ->set('uangDiterima', 48000)
            ->call('saveVisit')
            ->assertHasNoErrors();

        // Jatah baru permanen = 6.
        $this->assertEquals(6, $kiosk->fresh()->default_qty_mika);

        // Konsinyasi hari ini = 6 mika.
        $this->assertDatabaseHas('deliveries', [
            'kiosk_id' => $kiosk->id,
            'delivery_type' => 'consignment',
            'qty_delivered' => 6,
        ]);

        // Tagihan dari titipan LAMA 4 mika = 48.000.
        $this->assertEquals(48000, Settlement::where('delivery_id', $pending->id)->value('amount_due'));

        $visit = KioskVisit::where('kiosk_id', $kiosk->id)->where('settled_delivery_id', $pending->id)->firstOrFail();
        $this->assertTrue((bool) $visit->changed_default);
    }

    /** REGRESI: titip = jatah (tanpa centang) TIDAK ubah default. */
    public function test_normal_visit_does_not_change_default(): void
    {
        $kiosk = Kiosk::factory()->create([
            'cluster_id' => $this->cluster->id,
            'is_cash_only' => false,
            'default_qty_mika' => 4,
        ]);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->call('chooseAction', 'titip')
            ->set('dropBaru', 4) // titip = jatah, tanpa ubahJatah
            ->call('saveVisit')
            ->assertHasNoErrors();

        $this->assertEquals(4, $kiosk->fresh()->default_qty_mika);
        $visit = KioskVisit::where('kiosk_id', $kiosk->id)->firstOrFail();
        $this->assertFalse((bool) $visit->changed_default);
    }

    /** BLOKIR: titip LEBIH dari jatah tanpa "Ubah jatah" → ditolak, tak tersimpan. */
    public function test_block_titip_more_than_jatah_without_ubah_jatah(): void
    {
        $kiosk = Kiosk::factory()->create([
            'cluster_id' => $this->cluster->id,
            'is_cash_only' => false,
            'default_qty_mika' => 2,
        ]);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->call('chooseAction', 'titip')
            ->set('dropBaru', 5) // 5 > jatah 2, tanpa ubahJatah
            ->call('saveVisit')
            ->assertHasErrors('general');

        $this->assertSame(0, KioskVisit::where('kiosk_id', $kiosk->id)->count());
        $this->assertSame(0, Delivery::where('kiosk_id', $kiosk->id)->count());
        $this->assertEquals(2, $kiosk->fresh()->default_qty_mika);
    }

    /** BLOKIR: titip KURANG dari jatah tanpa "Ubah jatah" → ditolak (owner: kurang pun ditolak). */
    public function test_block_titip_less_than_jatah_without_ubah_jatah(): void
    {
        $kiosk = Kiosk::factory()->create([
            'cluster_id' => $this->cluster->id,
            'is_cash_only' => false,
            'default_qty_mika' => 20,
        ]);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->call('chooseAction', 'titip')
            ->set('dropBaru', 15) // 15 < jatah 20 → sekarang DIBLOKIR
            ->call('saveVisit')
            ->assertHasErrors('general');

        $this->assertSame(0, Delivery::where('kiosk_id', $kiosk->id)->count());
    }

    /** VERIFIKASI 2-LANGKAH: centang "Ubah jatah" → titip beda diperbolehkan, jatah berubah. */
    public function test_ubah_jatah_unblocks_titip_beda(): void
    {
        $kiosk = Kiosk::factory()->create([
            'cluster_id' => $this->cluster->id,
            'is_cash_only' => false,
            'default_qty_mika' => 20,
        ]);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->call('chooseAction', 'titip')
            ->set('dropBaru', 15)
            ->set('ubahJatah', true) // langkah-2: aksi sadar
            ->call('saveVisit')
            ->assertHasNoErrors();

        $this->assertEquals(15, $kiosk->fresh()->default_qty_mika);
        $this->assertDatabaseHas('deliveries', [
            'kiosk_id' => $kiosk->id,
            'delivery_type' => 'consignment',
            'qty_delivered' => 15,
        ]);
    }

    /** KIOS BARU (jatah null): titip pertama bebas menetapkan baseline, TANPA blokir. */
    public function test_kios_baru_first_titip_sets_baseline_without_block(): void
    {
        $kiosk = Kiosk::factory()->create([
            'cluster_id' => $this->cluster->id,
            'is_cash_only' => false,
            'default_qty_mika' => null,
        ]);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->call('chooseAction', 'titip')
            ->set('dropBaru', 7)
            ->call('saveVisit')
            ->assertHasNoErrors();

        // Baseline ditetapkan = titip pertama; BUKAN "ubah" (masih bisa dikoreksi).
        $this->assertEquals(7, $kiosk->fresh()->default_qty_mika);
        $visit = KioskVisit::where('kiosk_id', $kiosk->id)->firstOrFail();
        $this->assertFalse((bool) $visit->changed_default);
    }
}
