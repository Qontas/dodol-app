<?php

namespace Tests\Feature\Operator;

use App\Livewire\Operator\ActiveTrip;
use App\Livewire\Operator\CreateKiosk;
use App\Models\Cluster;
use App\Models\Delivery;
use App\Models\Kiosk;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * LEBUR dua field mika jadi SATU (owner 14 Juli 2026): form kios cukup "Jatah Titipan"
 * (default_qty_mika). Jatah ini OTOMATIS jadi titipan awal (OpeningBalance) untuk kedai
 * konsinyasi → langsung bisa "Tagih + Titip Ulang" kunjungan pertama; jumlah tagihan
 * dikoreksi otomatis oleh input sisa/BS operator di lapangan (owner tak perlu tahu stok fisik).
 */
class JatahOpeningBalanceE2ETest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected User $operator;
    protected Cluster $cluster;
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
        $this->cluster = Cluster::create(['name' => 'Area E2E', 'owner_id' => $this->owner->id]);
        $product = Product::factory()->create(['owner_id' => $this->owner->id]);
        ProductVariant::factory()->create([
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
        Livewire::actingAs($this->operator);
    }

    /**
     * END-TO-END: buat kedai konsinyasi jatah 4 (SATU field) → titipan awal 4 mika (60 biji)
     * → kunjungan pertama operator input sisa 30 biji → tagihan = (60−30)×800 = Rp 24.000
     * (= 2 mika laku). Owner tak perlu tahu stok fisik; koreksi dari input operator.
     */
    public function test_jatah_becomes_opening_balance_and_first_visit_bill_corrected_by_operator_sisa(): void
    {
        // 1) Buat kedai konsinyasi lewat form operator — SATU field mika (Jatah Titipan = 4).
        Livewire::test(CreateKiosk::class)
            ->set('namaKios', 'Kedai Jatah 4')
            ->set('namaPemilik', 'Bu Empat')
            ->set('clusterId', $this->cluster->id)
            ->set('jenisKedai', 'konsinyasi')
            ->set('defaultQtyMika', 4)
            ->call('saveKiosk');

        $kiosk = Kiosk::where('name', 'Kedai Jatah 4')->firstOrFail();
        $this->assertSame(4, (int) $kiosk->default_qty_mika);

        // Titipan awal 4 mika otomatis (dari jatah, bukan field terpisah).
        $opening = Delivery::where('kiosk_id', $kiosk->id)->doesntHave('settlement')->firstOrFail();
        $this->assertSame(4, (int) $opening->qty_delivered);

        // 2) Kunjungan pertama: fisik tinggal 2 mika → operator isi sisa bagus 30 biji.
        //    Sistem hitung tagihan = (60 − 30) × 800 = 24.000. Titip ulang = jatah (4).
        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->assertSet('pendingDelivery.id', $opening->id)
            ->call('chooseAction', 'tagih_titip')
            ->set('returnFresh', 30)     // sisa bagus (biji) = 2 mika belum laku
            ->set('returnExpired', 0)    // BS 0
            ->set('dropBaru', 4)         // titip ulang = jatah (blokir 2-langkah puas)
            ->call('hitungTagihan')
            ->assertSet('tagihan', 24000)
            ->call('saveVisit')
            ->assertHasNoErrors();

        // Tagihan tercatat Rp 24.000 di settlement titipan awal.
        $this->assertDatabaseHas('settlements', [
            'delivery_id' => $opening->id,
            'amount_paid' => 24000,
        ]);
    }

    /** Kedai cash-only lewat form operator → tak ada jatah & tak ada titipan awal. */
    public function test_cash_only_has_no_jatah_and_no_opening_balance(): void
    {
        Livewire::test(CreateKiosk::class)
            ->set('namaKios', 'Kedai Cash E2E')
            ->set('namaPemilik', 'Pak Tunai')
            ->set('clusterId', $this->cluster->id)
            ->set('jenisKedai', 'cash_only')
            ->call('saveKiosk')
            ->assertHasNoErrors();

        $kiosk = Kiosk::where('name', 'Kedai Cash E2E')->firstOrFail();
        $this->assertTrue((bool) $kiosk->is_cash_only);
        $this->assertNull($kiosk->default_qty_mika);
        $this->assertSame(0, Delivery::where('kiosk_id', $kiosk->id)->count());
    }

    /** BLOKIR 2-langkah tetap jalan: titip != jatah tanpa "Ubah jatah" → ditolak. */
    public function test_blokir_titip_beda_jatah_masih_jalan(): void
    {
        Livewire::test(CreateKiosk::class)
            ->set('namaKios', 'Kedai Blokir')
            ->set('namaPemilik', 'Bu Blok')
            ->set('clusterId', $this->cluster->id)
            ->set('jenisKedai', 'konsinyasi')
            ->set('defaultQtyMika', 4)
            ->call('saveKiosk');

        $kiosk = Kiosk::where('name', 'Kedai Blokir')->firstOrFail();
        $opening = Delivery::where('kiosk_id', $kiosk->id)->doesntHave('settlement')->firstOrFail();

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->assertSet('pendingDelivery.id', $opening->id)
            ->call('chooseAction', 'tagih_titip')
            ->set('returnFresh', 0)
            ->set('returnExpired', 0)
            ->set('dropBaru', 6)   // titip 6 != jatah 4, tanpa ubahJatah → blokir
            ->set('ubahJatah', false)
            ->call('hitungTagihan')
            ->call('saveVisit')
            ->assertHasErrors('general');
    }
}
