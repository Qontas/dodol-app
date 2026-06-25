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
 * Hentikan kedai (stop titipan) — 1 pintu, 2 jalur:
 *   (a) Stop + Tagih Terakhir  → catat tagihan lalu kios non-aktif (atomik).
 *   (b) Stop Tanpa Tagih       → sisa titipan dicatat sebagai kerugian.
 * Keduanya menutup titipan lewat Settlement supaya tidak ada "silent loss".
 */
class ActiveTripStopKiosTest extends TestCase
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
        $this->cluster = Cluster::create(['name' => 'Cluster Stop']);
        $this->trip = Trip::factory()->create([
            'operator_id' => $this->operator->id,
            'starting_cluster_id' => $this->cluster->id,
            'qty_carried_total' => 50,
            'started_at' => now(),
            'trip_date' => today()->format('Y-m-d'),
        ]);
        $this->variant = ProductVariant::factory()->create(['is_active' => true, 'sale_price_per_pack' => 12000]);
    }

    private function pendingDelivery(Kiosk $kiosk, int $mika = 5): Delivery
    {
        return Delivery::factory()->create([
            'kiosk_id' => $kiosk->id,
            'trip_id' => $this->trip->id,
            'qty_delivered' => $mika,
            'product_variant_id' => $this->variant->id,
            'delivery_type' => 'consignment',
        ]);
    }

    // ---------------------------------------------------------------------
    // Jalur (b) tanpa titipan: stop biasa
    // ---------------------------------------------------------------------
    public function test_stop_tanpa_tagih_without_pending_deactivates_and_records_visit(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id, 'is_active' => true]);

        $this->actingAs($this->operator);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->call('startStop')
            ->call('chooseStopMode', 'tanpa_tagih')
            ->set('stopReason', 'tutup_permanen')
            ->call('requestStopConfirm')
            ->assertSet('stopConfirming', true)
            ->call('executeStop')
            ->assertHasNoErrors()
            ->assertSet('isVisitModalOpen', false);

        $kiosk->refresh();
        $this->assertFalse($kiosk->is_active);
        $this->assertNotNull($kiosk->stopped_at);
        $this->assertEquals('tutup_permanen', $kiosk->stop_reason);
        $this->assertEquals('operator', $kiosk->stopped_by);

        $visit = KioskVisit::where('trip_id', $this->trip->id)->where('kiosk_id', $kiosk->id)->first();
        $this->assertNotNull($visit);
        $this->assertEquals('check_only', $visit->visit_action);
        $this->assertEquals('stop_titipan', $visit->alasan_check);
    }

    // ---------------------------------------------------------------------
    // Konfirmasi & alasan wajib
    // ---------------------------------------------------------------------
    public function test_stop_requires_reason_before_confirm(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id, 'is_active' => true]);

        $this->actingAs($this->operator);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->call('startStop')
            ->call('chooseStopMode', 'tanpa_tagih')
            ->call('requestStopConfirm')
            ->assertHasErrors('stopReason')
            ->assertSet('stopConfirming', false);

        $this->assertTrue($kiosk->fresh()->is_active);
        $this->assertEquals(0, KioskVisit::where('kiosk_id', $kiosk->id)->count());
    }

    public function test_execute_stop_ignored_without_confirmation(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id, 'is_active' => true]);

        $this->actingAs($this->operator);

        // Langsung executeStop tanpa requestStopConfirm → diabaikan (bukan 1-tap jalan).
        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->call('startStop')
            ->call('chooseStopMode', 'tanpa_tagih')
            ->set('stopReason', 'tutup_permanen')
            ->call('executeStop');

        $this->assertTrue($kiosk->fresh()->is_active);
        $this->assertEquals(0, KioskVisit::where('kiosk_id', $kiosk->id)->count());
    }

    // ---------------------------------------------------------------------
    // Jalur (a): Stop + Tagih Terakhir → Settlement + kios non-aktif
    // ---------------------------------------------------------------------
    public function test_stop_tagih_records_settlement_and_deactivates(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id, 'is_active' => true]);
        $delivery = $this->pendingDelivery($kiosk, 5); // 5 mika = 75 biji

        $this->actingAs($this->operator);

        // 1 mika basi (15 biji) → terjual 60 biji → tagihan 60 * 800 = 48.000
        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->call('startStop')
            ->call('chooseStopMode', 'tagih')
            ->set('returnFresh', 0)
            ->set('returnExpired', 15)
            ->set('uangDiterima', 48000)
            ->set('stopReason', 'pemilik_minta_stop')
            ->call('requestStopConfirm')
            ->call('executeStop')
            ->assertHasNoErrors()
            ->assertSet('isVisitModalOpen', false);

        // Kios non-aktif
        $kiosk->refresh();
        $this->assertFalse($kiosk->is_active);
        $this->assertEquals('pemilik_minta_stop', $kiosk->stop_reason);

        // Settlement tercatat dengan omset benar
        $settlement = Settlement::where('delivery_id', $delivery->id)->first();
        $this->assertNotNull($settlement);
        $this->assertEquals(60, $settlement->qty_sold);
        $this->assertEquals(15, $settlement->qty_returned_expired);
        $this->assertEquals(48000, $settlement->amount_paid);
        $this->assertEquals('paid', $settlement->status);
        // Stop + Tagih = penjualan nyata, BUKAN kerugian.
        $this->assertFalse((bool) $settlement->is_writeoff);

        // Titipan tidak lagi menggantung
        $this->assertFalse(
            Delivery::whereKey($delivery->id)->doesntHave('settlement')->exists()
        );
    }

    public function test_stop_tagih_unavailable_without_pending(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id, 'is_active' => true]);

        $this->actingAs($this->operator);

        // Tanpa titipan, jalur tagih tidak relevan → tetap di layar 'pick'.
        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->call('startStop')
            ->call('chooseStopMode', 'tagih')
            ->assertSet('stopMode', 'pick');
    }

    // ---------------------------------------------------------------------
    // Jalur (b) dengan titipan: kerugian dicatat, tidak ada silent loss
    // ---------------------------------------------------------------------
    public function test_stop_tanpa_tagih_records_loss_and_closes_titipan(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id, 'is_active' => true]);
        $delivery = $this->pendingDelivery($kiosk, 5); // 75 biji

        $this->actingAs($this->operator);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->call('startStop')
            ->call('chooseStopMode', 'tanpa_tagih')
            ->set('stopReason', 'kurang_laku')
            ->call('requestStopConfirm')
            ->call('executeStop')
            ->assertHasNoErrors()
            ->assertSet('isVisitModalOpen', false);

        $kiosk->refresh();
        $this->assertFalse($kiosk->is_active);

        // Settlement kerugian: semua sisa = basi, uang 0, laku 0.
        $settlement = Settlement::where('delivery_id', $delivery->id)->first();
        $this->assertNotNull($settlement, 'Titipan harus ditutup dengan settlement kerugian (bukan menggantung).');
        $this->assertEquals(0, $settlement->qty_sold);
        $this->assertEquals(75, $settlement->qty_returned_expired);
        $this->assertEquals(0, $settlement->amount_paid);
        $this->assertEquals(0, $settlement->amount_due);
        // Ditandai kerugian untuk laporan owner (poin 5).
        $this->assertTrue((bool) $settlement->is_writeoff);

        // Tidak menggantung sebagai outstanding (status paid, bukan pending).
        $this->assertEquals('paid', $settlement->status);
        $this->assertEquals(
            0,
            Settlement::where('delivery_id', $delivery->id)->where('status', 'pending')->count()
        );

        // Delivery tidak lagi terhitung pending (sudah punya settlement).
        $this->assertFalse(
            Delivery::whereKey($delivery->id)->doesntHave('settlement')->exists()
        );
    }

    // ---------------------------------------------------------------------
    // ATOMICITY: settle gagal → kios TIDAK ter-nonaktif (rollback total)
    // ---------------------------------------------------------------------
    public function test_atomicity_stop_tagih_rolls_back_when_settle_fails(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id, 'is_active' => true]);
        $delivery = $this->pendingDelivery($kiosk, 5); // 75 biji

        $this->actingAs($this->operator);

        // Retur 100 biji > 75 biji titipan → persistVisitFromState gagal.
        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->call('startStop')
            ->call('chooseStopMode', 'tagih')
            ->set('returnFresh', 50)
            ->set('returnExpired', 50)
            ->set('uangDiterima', 0)
            ->set('stopReason', 'pemilik_minta_stop')
            ->call('requestStopConfirm')
            ->call('executeStop')
            ->assertHasErrors('general');

        // Settle gagal → kios TETAP aktif, tidak ada settlement, titipan utuh.
        $kiosk->refresh();
        $this->assertTrue($kiosk->is_active, 'Kios tidak boleh non-aktif saat settle gagal (atomicity).');
        $this->assertNull($kiosk->stopped_at);
        $this->assertEquals(0, Settlement::where('delivery_id', $delivery->id)->count());
        $this->assertTrue(
            Delivery::whereKey($delivery->id)->doesntHave('settlement')->exists(),
            'Titipan harus tetap menggantung-aktif (belum ditutup) karena settle di-rollback.'
        );
    }
}
