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
 * Membuktikan opsi "Cek Sisa" untuk kios yang MASIH ADA TITIPAN:
 * mencatat sisa dodol + alasan TANPA menyentuh titipan (tidak settle, tidak
 * buat delivery). Titipan WAJIB tetap pending — syarat mutlak fitur ini.
 */
class ActiveTripCekSisaBertitipanTest extends TestCase
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
        $this->cluster = Cluster::create(['name' => 'Cluster Test']);
        $this->trip = Trip::factory()->create([
            'operator_id' => $this->operator->id,
            'starting_cluster_id' => $this->cluster->id,
            'qty_carried_total' => 50,
            'started_at' => now(),
            'trip_date' => today()->format('Y-m-d'),
        ]);
        $this->variant = ProductVariant::factory()->create(['is_active' => true, 'sale_price_per_pack' => 12000]);
    }

    /**
     * Kios bertitipan + Cek Sisa → check_only dengan sisa_biji, TIDAK ada
     * Settlement, TIDAK ada Delivery baru, dan titipan lama TETAP pending.
     */
    public function test_cek_sisa_kios_bertitipan_tidak_settle_titipan(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id]);

        // Titipan lama yang belum di-settle (pendingDelivery).
        $pendingDelivery = Delivery::factory()->create([
            'kiosk_id' => $kiosk->id,
            'trip_id' => $this->trip->id,
            'qty_delivered' => 2, // 2 mika = 30 biji (skenario "Karya 2")
            'product_variant_id' => $this->variant->id,
        ]);

        $this->actingAs($this->operator);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            // Modal harus mendeteksi titipan aktif.
            ->assertSet('pendingDelivery.id', $pendingDelivery->id)
            ->call('chooseAction', 'cek')
            ->assertSet('chosenAction', 'cek')
            // drop di-nol-kan saat memilih cek (tidak ada titip baru).
            ->assertSet('dropBaru', 0)
            ->set('alasanCheck', 'kios_tutup')
            ->set('sisaBiji', 18)
            ->call('saveVisit')
            ->assertHasNoErrors();

        // 1. Visit tercatat sebagai check_only dengan alasan + sisa biji.
        $visit = KioskVisit::where('trip_id', $this->trip->id)
            ->where('kiosk_id', $kiosk->id)
            ->first();

        $this->assertNotNull($visit);
        $this->assertEquals('check_only', $visit->visit_action);
        $this->assertEquals('kios_tutup', $visit->alasan_check);
        $this->assertEquals(18, $visit->sisa_biji);
        // Tidak menyentuh titipan: tidak menandai delivery apa pun.
        $this->assertNull($visit->new_delivery_id);
        $this->assertNull($visit->settled_delivery_id);

        // 2. TIDAK ada Settlement — titipan tidak di-settle.
        $this->assertEquals(0, Settlement::count());

        // 3. TIDAK ada Delivery baru — hanya titipan lama yang tetap ada.
        $this->assertEquals(1, Delivery::count());

        // 4. Titipan TETAP pending (belum punya settlement) — syarat mutlak.
        $stillPending = Delivery::where('kiosk_id', $kiosk->id)
            ->doesntHave('settlement')
            ->where('id', $pendingDelivery->id)
            ->exists();
        $this->assertTrue($stillPending, 'Titipan lama harus tetap pending setelah Cek Sisa.');

        // 5. Data prediksi habis tersedia: latestCheckVisit terbaca dengan sisa_biji.
        $this->assertEquals(18, $kiosk->fresh()->latestCheckVisit?->sisa_biji);
    }

    /**
     * Tunda Bayar boleh mencatat sisa dodol (pendataan) TANPA menutup titipan.
     * Wajib: (a) KioskVisit punya sisa_biji, (b) tidak ada Settlement → titipan
     * tetap pending, (c) extensionCount naik (hitungan max 2x jalan), (d) sisa
     * terbaca oleh latestCheckVisit/prediksi habis.
     */
    public function test_tunda_bayar_catat_sisa_tanpa_settle_titipan(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id]);

        $pendingDelivery = Delivery::factory()->create([
            'kiosk_id' => $kiosk->id,
            'trip_id' => $this->trip->id,
            'qty_delivered' => 2, // 30 biji
            'product_variant_id' => $this->variant->id,
        ]);

        $this->actingAs($this->operator);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->assertSet('pendingDelivery.id', $pendingDelivery->id)
            ->call('chooseAction', 'tunda')
            ->assertSet('chosenAction', 'tunda')
            ->assertSet('extensionGranted', true)
            ->assertSet('dropBaru', 0)
            ->set('sisaBiji', 12)
            ->call('saveVisit')
            ->assertHasNoErrors();

        // (a) Visit tunda (settle_only + extension) menyimpan sisa_biji.
        $visit = KioskVisit::where('trip_id', $this->trip->id)
            ->where('kiosk_id', $kiosk->id)
            ->first();
        $this->assertNotNull($visit);
        $this->assertEquals('settle_only', $visit->visit_action);
        $this->assertTrue((bool) $visit->extension_granted);
        $this->assertEquals(12, $visit->sisa_biji);
        // Menandai titipan lama (untuk jejak & hitungan), tapi tidak melunasinya.
        $this->assertEquals($pendingDelivery->id, $visit->settled_delivery_id);

        // (b) TIDAK ada Settlement → titipan TETAP pending.
        $this->assertEquals(0, Settlement::count());
        $this->assertTrue(
            Delivery::whereKey($pendingDelivery->id)->doesntHave('settlement')->exists(),
            'Tunda + catat sisa TIDAK boleh menutup titipan.'
        );

        // (c) extensionCount naik → hitungan max 2x tunda tetap jalan.
        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->assertSet('extensionCount', 1);

        // (d) Sisa dari visit Tunda terbaca sebagai sumber prediksi habis.
        $this->assertEquals(12, $kiosk->fresh()->latestCheckVisit?->sisa_biji);
        $this->assertNotNull($kiosk->fresh()->prediksi_habis);
    }

    /**
     * Whitelist chooseAction mengizinkan 'cek' untuk kios bertitipan
     * (regresi: sebelumnya 'cek' ditolak saat ada titipan).
     */
    public function test_chooseaction_cek_diizinkan_untuk_kios_bertitipan(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id]);
        Delivery::factory()->create([
            'kiosk_id' => $kiosk->id,
            'trip_id' => $this->trip->id,
            'qty_delivered' => 3,
            'product_variant_id' => $this->variant->id,
        ]);

        $this->actingAs($this->operator);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->call('chooseAction', 'cek')
            ->assertSet('chosenAction', 'cek');
    }
}
