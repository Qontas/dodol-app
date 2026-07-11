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
 * KOMISI RIAN — basis DROP (Opsi Y, ground-truth owner 11 Juli 2026):
 *   Komisi = Rp 1.000 x mika yang Rian BAWA & DILETAKKAN (saat drop, bukan laku),
 *   EXCLUDE BS daur-ulang & stop-tanpa-drop. Bonus kios-baru dilebur ke tarif flat.
 * Basis komisi (mika_komisi) SENGAJA terpisah dari mika_terjual (omset/untung).
 */
class KomisiDropBasisTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected User $operator;
    protected Cluster $cluster;
    protected Trip $trip;
    protected ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        // Tarif di-set eksplisit Rp 1.000 → test deterministik (tak bergantung default DB).
        $this->owner = User::factory()->create([
            'role' => 'owner', 'is_active' => true,
            'hpp_per_mika' => 9500, 'komisi_per_mika' => 1000,
        ]);
        $this->operator = User::factory()->create([
            'role' => 'operator', 'is_active' => true, 'owner_id' => $this->owner->id,
        ]);
        $this->cluster = Cluster::create(['name' => 'Cluster Komisi', 'owner_id' => $this->owner->id]);
        // Varian WAJIB milik produk owner ini — alur Livewire resolveActiveVariant() ter-scope owner.
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
    }

    private function drop(Trip $trip, Kiosk $kiosk, int $mika, string $type = 'consignment'): Delivery
    {
        return Delivery::factory()->create([
            'kiosk_id' => $kiosk->id,
            'trip_id' => $trip->id,
            'qty_delivered' => $mika,
            'product_variant_id' => $this->variant->id,
            'delivery_type' => $type,
        ]);
    }

    /** SKENARIO 1: bawa 75, drop 50 (25 ga keantar) → komisi 50 x 1.000, BUKAN basis laku. */
    public function test_komisi_basis_drop_bukan_laku(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id]);
        $this->drop($this->trip, $kiosk, 50); // di-drop, belum tentu laku

        $trip = $this->trip->fresh();
        $this->assertSame(50.0, $trip->mika_komisi);
        $this->assertSame(50000.0, $trip->komisi_rian); // 50 drop x 1.000
        // Belum ada yang laku → kalau basis LAKU komisi 0. Ini membuktikan basis DROP.
        $this->assertSame(0.0, $trip->mika_terjual);
    }

    /** SKENARIO 2: +2 mika BS daur-ulang → komisi TETAP 50 (BS bukan stok bawaan). */
    public function test_bs_daur_ulang_tidak_dapat_komisi(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id]);
        $this->drop($this->trip, $kiosk, 50, 'consignment');
        $this->drop($this->trip, $kiosk, 2, 'bs_redistribution'); // daur ulang → exclude

        $trip = $this->trip->fresh();
        $this->assertSame(50.0, $trip->mika_komisi);      // BS 2 mika TIDAK ikut
        $this->assertSame(50000.0, $trip->komisi_rian);
    }

    /** SKENARIO 3: stop+tagih TANPA drop mika baru → 0 komisi dari situ. */
    public function test_stop_tagih_tanpa_drop_nol_komisi(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id]);

        // Titipan lama dari trip lampau, di-settle di trip ini tanpa drop baru
        // (pola Stop+Tagih: dropBaru dipaksa 0). Delivery-nya milik pastTrip.
        $pastTrip = Trip::factory()->create([
            'owner_id' => $this->owner->id, 'operator_id' => $this->operator->id,
            'trip_date' => today()->subDays(3)->format('Y-m-d'), 'ended_at' => now()->subDays(3),
        ]);
        $pending = $this->drop($pastTrip, $kiosk, 5);
        Settlement::create([
            'delivery_id' => $pending->id, 'visit_date' => today(),
            'qty_sold' => 75, 'qty_returned_fresh' => 0, 'qty_returned_expired' => 0,
            'amount_due' => 60000, 'amount_paid' => 60000,
        ]);
        KioskVisit::create([
            'trip_id' => $this->trip->id, 'kiosk_id' => $kiosk->id, 'visited_at' => now(),
            'visit_action' => 'settle_only', 'settled_delivery_id' => $pending->id,
            'extension_granted' => false,
        ]);

        $trip = $this->trip->fresh();
        // Nagih menghasilkan omset, TAPI tak ada mika keluar dari Rian → komisi 0.
        $this->assertSame(0.0, $trip->mika_komisi);
        $this->assertSame(0.0, $trip->komisi_rian);
        $this->assertSame(60000.0, $trip->omset_val); // omset tetap ada (nagih)
    }

    /** SKENARIO 4: drop ke KEDAI BARU dihitung sama seperti kedai lama (bukan bonus terpisah). */
    public function test_kedai_baru_sama_dengan_kedai_lama(): void
    {
        $kiosBaru = Kiosk::factory()->create([
            'cluster_id' => $this->cluster->id, 'first_titip_date' => today(),
        ]);
        $this->drop($this->trip, $kiosBaru, 4); // drop perdana kedai baru

        $trip = $this->trip->fresh();
        $this->assertSame(4.0, $trip->mika_komisi);
        $this->assertSame(4000.0, $trip->komisi_rian);   // 4 x 1.000 flat
        $this->assertSame(0.0, $trip->komisi_kios_baru); // bonus terpisah SUDAH dilebur
    }

    /** SKENARIO 5: cash walk-in 3 mika → masuk komisi 3 x 1.000. */
    public function test_cash_walk_in_masuk_komisi(): void
    {
        $sentinel = Kiosk::walkInSentinelFor($this->owner->id);
        $this->drop($this->trip, $sentinel, 3, 'cash_sale');

        $trip = $this->trip->fresh();
        $this->assertSame(3.0, $trip->mika_komisi);
        $this->assertSame(3000.0, $trip->komisi_rian);
    }

    /** SKENARIO 6: tambah ekstra jatah (naik 2→4) → SEMUA 4 mika drop masuk komisi. */
    public function test_ubah_jatah_naik_semua_drop_masuk_komisi(): void
    {
        $kiosk = Kiosk::factory()->create([
            'cluster_id' => $this->cluster->id, 'default_qty_mika' => 2,
        ]);
        // Titipan lama (jatah 2) milik trip lampau → tak dihitung drop trip ini.
        $pastTrip = Trip::factory()->create([
            'owner_id' => $this->owner->id, 'operator_id' => $this->operator->id,
            'trip_date' => today()->subDays(2)->format('Y-m-d'), 'ended_at' => now()->subDays(2),
        ]);
        $this->drop($pastTrip, $kiosk, 2);

        $this->actingAs($this->operator);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->call('chooseAction', 'tagih_titip')
            ->set('dropBaru', 4)         // satu angka: titip 4 = jatah baru (naik 2→4)
            ->set('ubahJatah', true)
            ->set('uangDiterima', 24000) // settle titipan lama 2 mika = 30 biji x 800
            ->call('hitungTagihan')
            ->call('saveVisit')
            ->assertHasNoErrors();

        $trip = $this->trip->fresh();
        // Drop hari ini = 4 konsinyasi (jatah baru). Semua 4 masuk komisi (basis DROP).
        $this->assertSame(4.0, $trip->mika_komisi);
        $this->assertSame(4000.0, $trip->komisi_rian);
    }

    /** REGRESI: omset & untung_kotor (basis LAKU) TIDAK berubah oleh rework komisi. */
    public function test_omset_dan_untung_tidak_berubah(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id]);
        $delivery = $this->drop($this->trip, $kiosk, 10);
        Settlement::create([
            'delivery_id' => $delivery->id, 'visit_date' => today(),
            'qty_sold' => 150, 'qty_returned_fresh' => 0, 'qty_returned_expired' => 0,
            'amount_due' => 120000, 'amount_paid' => 120000,
        ]);
        KioskVisit::create([
            'trip_id' => $this->trip->id, 'kiosk_id' => $kiosk->id, 'visited_at' => now(),
            'visit_action' => 'drop_and_settle', 'new_delivery_id' => $delivery->id,
            'settled_delivery_id' => $delivery->id, 'extension_granted' => false,
        ]);

        $trip = $this->trip->fresh();
        $this->assertSame(10.0, $trip->mika_terjual);
        $this->assertSame(120000.0, $trip->omset_val);   // basis LAKU, tak berubah
        $this->assertSame(25000.0, $trip->untung_kotor); // 10 * (12000-9500), tak berubah
        // Komisi (drop) terpisah: 10 mika drop x 1.000.
        $this->assertSame(10000.0, $trip->komisi_rian);
    }
}
