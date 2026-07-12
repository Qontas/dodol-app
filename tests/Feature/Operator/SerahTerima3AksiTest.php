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
use App\Support\OpeningBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Serah Terima 3-AKSI (owner 11 Juli 2026) — kumpulan pengunci perilaku baru:
 *   AKSI 3 "Lewati / Belum Habis" (catat sisa + catatan), blokir 2-langkah UI, dan
 *   FLAG kios lama vs baru (via saldo awal / OpeningBalance — TANPA kolom baru).
 */
class SerahTerima3AksiTest extends TestCase
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
        $this->cluster = Cluster::create(['name' => 'Cluster 3Aksi', 'owner_id' => $this->owner->id]);
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

    /** AKSI 3 "Lewati": 0 transaksi, catat sisa biji + catatan (KioskVisit.notes). */
    public function test_aksi_lewati_records_sisa_and_catatan_without_transaction(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id, 'default_qty_mika' => 4]);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->call('chooseAction', 'cek')
            ->set('alasanCheck', 'dodol_masih_banyak')
            ->set('sisaBiji', 20)
            ->set('janjiBayar', 'pemilik lagi sepi')
            ->call('saveVisit')
            ->assertHasNoErrors();

        $visit = KioskVisit::where('kiosk_id', $kiosk->id)->firstOrFail();
        $this->assertSame('check_only', $visit->visit_action);
        $this->assertSame(20, (int) $visit->sisa_biji);
        $this->assertSame('pemilik lagi sepi', $visit->notes); // catatan bebas apa adanya
        // 0 transaksi: tak ada delivery / settlement.
        $this->assertSame(0, Delivery::where('kiosk_id', $kiosk->id)->count());
        $this->assertSame(0, Settlement::count());
        // Jatah tetap.
        $this->assertEquals(4, $kiosk->fresh()->default_qty_mika);
    }

    /**
     * AKSI 3 (Lewati) TIDAK menawarkan ubah-jatah lagi (owner 12 Juli 2026) — hanya AKSI 1.
     * UI: form Lewati tak menampilkan checkbox "Ubah jatah permanen". Logika: meski flag
     * ubahJatah dipaksa true dari klien, jatah kedai TIDAK berubah (tak ada transaksi).
     */
    public function test_aksi_lewati_has_no_ubah_jatah(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id, 'default_qty_mika' => 4]);

        $component = Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->call('chooseAction', 'cek');

        // UI: tak ada checkbox ubah-jatah di layar Lewati.
        $component->assertDontSee('Ubah jatah permanen');

        // Logika: flag diakali → tetap tak mengubah jatah.
        $component->set('alasanCheck', 'dodol_masih_banyak')
            ->set('ubahJatah', true)
            ->call('saveVisit')
            ->assertHasNoErrors();

        $this->assertEquals(4, $kiosk->fresh()->default_qty_mika); // jatah TETAP
        $visit = KioskVisit::where('kiosk_id', $kiosk->id)->firstOrFail();
        $this->assertFalse((bool) $visit->changed_default);
        $this->assertSame(0, Delivery::where('kiosk_id', $kiosk->id)->count()); // tetap 0 transaksi
    }

    /**
     * FLAG KIOS LAMA (via saldo awal / OpeningBalance): kios yang punya titipan
     * berjalan → operator melihat pendingDelivery → bisa "Tagih + Titip Ulang" di
     * kunjungan PERTAMA. Tanpa kolom flag: murni dari delivery pending.
     */
    public function test_kios_lama_opening_balance_allows_tagih_titip_first_visit(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id, 'default_qty_mika' => 4]);

        // Saldo awal 4 mika (titipan berjalan sebelum sistem).
        $opening = OpeningBalance::create($kiosk, 4);
        $this->assertNotNull($opening);
        $this->assertSame('consignment', $opening->delivery_type);
        $this->assertTrue(Delivery::whereKey($opening->id)->doesntHave('settlement')->exists());

        // Kunjungan pertama: operator melihat titipan lama → Tagih + Titip Ulang.
        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->assertSet('pendingDelivery.id', $opening->id)
            ->assertSee('Tagih + Titip Ulang')
            ->call('chooseAction', 'tagih_titip')
            ->set('returnFresh', 0)
            ->set('returnExpired', 0)
            ->set('uangDiterima', 48000) // 4 mika = 60 biji x 800
            ->set('dropBaru', 4)         // titip ulang = jatah
            ->call('hitungTagihan')
            ->call('saveVisit')
            ->assertHasNoErrors();

        // Titipan lama ter-settle; titipan baru 4 mika pending.
        $this->assertDatabaseHas('settlements', ['delivery_id' => $opening->id, 'amount_paid' => 48000]);
        $visit = KioskVisit::where('kiosk_id', $kiosk->id)->where('settled_delivery_id', $opening->id)->firstOrFail();
        $this->assertSame('drop_and_settle', $visit->visit_action);
    }

    /** Saldo awal tidak digandakan: kios yang sudah punya titipan pending → null. */
    public function test_opening_balance_is_idempotent(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id, 'default_qty_mika' => 3]);

        $first = OpeningBalance::create($kiosk, 3);
        $this->assertNotNull($first);
        $second = OpeningBalance::create($kiosk, 5);
        $this->assertNull($second); // sudah ada titipan pending → tidak menumpuk

        $this->assertSame(1, Delivery::where('kiosk_id', $kiosk->id)->count());
    }

    /** KEDAI TANPA TITIPAN: tak ada pending → aksi adaptif "Mulai Titipan", bukan tagih. */
    public function test_kios_baru_has_no_pending_and_shows_mulai_titipan(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id, 'default_qty_mika' => null]);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->assertSet('pendingDelivery', null)
            ->assertSee('Mulai Titipan')
            ->assertDontSee('Tagih + Titip Ulang');
    }

    /** BLOKIR UI (2-langkah, AKSI 1): titip != jatah tanpa "Ubah jatah" → tombol arahan. */
    public function test_blokir_ui_disables_save_when_titip_not_equal_jatah(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id, 'default_qty_mika' => 4]);
        // Kedai punya titipan berjalan → aksi "Tagih + Titip Ulang" tersedia (blokir di sini).
        Delivery::factory()->create([
            'kiosk_id' => $kiosk->id, 'trip_id' => $this->trip->id,
            'qty_delivered' => 4, 'product_variant_id' => $this->variant->id,
        ]);

        $component = Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->call('chooseAction', 'tagih_titip')
            ->set('dropBaru', 6); // 6 != jatah 4

        $component->assertSee('Betulkan titip = jatah dulu')
            ->assertSee('Titip harus sama dengan jatah (4)');

        // Centang ubah jatah → blokir hilang, tombol simpan aktif.
        $component->set('ubahJatah', true)
            ->assertSee('Simpan Kunjungan')
            ->assertDontSee('Betulkan titip = jatah dulu');
    }
}
