<?php

namespace Tests\Feature\Operator;

use App\Livewire\Operator\ActiveTrip;
use App\Livewire\Operator\CreateKiosk;
use App\Models\Cluster;
use App\Models\Delivery;
use App\Models\Kiosk;
use App\Models\KioskVisit;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Trip;
use App\Models\TripStockAddition;
use App\Models\User;
use App\Support\MotorStockGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * BRIEF 27 Okt 2026 "PENGAMAN STOK DI MOTOR" — kumpulan pengunci:
 *   1. Guard menolak titip/jual yang melebihi stok di motor di SEMUA jalur yang
 *      mengeluarkan mika dari motor (Tagih+Titip Ulang, Titip Cash, Mulai Titipan,
 *      Jual Cash Cepat, Kios Baru konsinyasi dalam trip aktif, Koreksi yang
 *      MENINGKATKAN angka).
 *   2. "➕ Tambah Stok di Motor" menaikkan stok efektif → guard lolos sesudahnya.
 *   3. Dodol Sisa (bs_redistribution) TIDAK PERNAH diblokir guard ini (berasal
 *      dari kios lain, bukan dari stok motor — lihat Trip::getStokDiMotor()).
 *   4. Multi-tenant: operator tidak bisa menambah stok trip operator lain.
 *   5. Format tampilan "90 + 20 tambahan = 110".
 */
class MotorStockGuardTest extends TestCase
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
        $this->cluster = Cluster::create(['name' => 'Cluster Guard']);
        // Stok kecil & bulat (10) supaya mudah melebihi di tiap test.
        $this->trip = Trip::factory()->create([
            'operator_id' => $this->operator->id,
            'starting_cluster_id' => $this->cluster->id,
            'qty_carried_total' => 10,
            'started_at' => now(),
            'trip_date' => today()->format('Y-m-d'),
        ]);
        $this->variant = ProductVariant::factory()->create(['is_active' => true, 'sale_price_per_pack' => 12000]);

        $this->actingAs($this->operator);
    }

    private function guardMessage(int $available, int $requested): string
    {
        return "Stok di motor tinggal {$available} mika, kamu mau menaruh {$requested} mika. "
            .'Kalau memang bawa lebih, tekan ➕ Tambah Stok di Motor dulu.';
    }

    // ===================== 1. TAGIH + TITIP ULANG =====================

    public function test_tagih_titip_ulang_ditolak_jika_melebihi_stok_di_motor(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id, 'default_qty_mika' => 20]);
        // Titipan lama HARUS nempel ke trip LAIN (trip lampau), bukan trip yang sedang
        // diuji — kalau nempel ke $this->trip, Trip::getTotalDropReal() menghitungnya
        // sebagai drop trip INI sendiri dan getStokDiMotor() jadi salah (negatif).
        // Konvensi sama seperti ActiveTripSaveVisitTest::test_save_visit_settle_only.
        $pastTrip = Trip::factory()->create([
            'operator_id' => $this->operator->id,
            'starting_cluster_id' => $this->cluster->id,
            'trip_date' => today()->subDays(2)->format('Y-m-d'),
        ]);
        $pending = Delivery::factory()->create([
            'kiosk_id' => $kiosk->id,
            'trip_id' => $pastTrip->id,
            'qty_delivered' => 20,
            'product_variant_id' => $this->variant->id,
        ]);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->call('chooseAction', 'tagih_titip')
            // titip ulang = jatah (20) > stok motor (10) — ditolak guard, bukan blokir jatah.
            ->set('returnFresh', 0)
            ->set('returnExpired', 0)
            ->set('uangDiterima', 0)
            ->call('hitungTagihan')
            ->call('saveVisit')
            ->assertHasErrors('general')
            ->assertSee($this->guardMessage(10, 20));

        // Tidak ada delivery/visit baru — hanya titipan lama yang masih pending.
        $this->assertEquals(1, Delivery::where('kiosk_id', $kiosk->id)->count());
        $this->assertEquals(0, KioskVisit::where('kiosk_id', $kiosk->id)->count());
        $this->trip->refresh();
        $this->assertSame(10, $this->trip->getStokDiMotor());
    }

    public function test_tagih_titip_ulang_lolos_setelah_tambah_stok_di_motor(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id, 'default_qty_mika' => 20]);
        // Lihat catatan konvensi $pastTrip di test_tagih_titip_ulang_ditolak_...
        $pastTrip = Trip::factory()->create([
            'operator_id' => $this->operator->id,
            'starting_cluster_id' => $this->cluster->id,
            'trip_date' => today()->subDays(2)->format('Y-m-d'),
        ]);
        Delivery::factory()->create([
            'kiosk_id' => $kiosk->id,
            'trip_id' => $pastTrip->id,
            'qty_delivered' => 20,
            'product_variant_id' => $this->variant->id,
        ]);

        $component = Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->call('chooseAction', 'tagih_titip')
            ->set('returnFresh', 0)
            ->set('returnExpired', 0)
            ->set('uangDiterima', 0)
            ->call('hitungTagihan')
            ->call('saveVisit')
            ->assertHasErrors('general');

        // Operator tambah stok 15 mika → stok efektif 10+15=25, cukup untuk titip 20.
        $component->set('tambahStokQty', 15)
            ->call('confirmAddStock')
            ->assertHasNoErrors();

        $this->trip->refresh();
        $this->assertSame(25, $this->trip->getStokDiMotor());

        $component->call('openVisitModal', $kiosk->id)
            ->call('chooseAction', 'tagih_titip')
            ->set('returnFresh', 0)
            ->set('returnExpired', 0)
            ->set('uangDiterima', 0)
            ->call('hitungTagihan')
            ->call('saveVisit')
            ->assertHasNoErrors();

        $visit = KioskVisit::where('kiosk_id', $kiosk->id)->whereNotNull('new_delivery_id')->first();
        $this->assertNotNull($visit);
        $this->assertDatabaseHas('deliveries', [
            'id' => $visit->new_delivery_id,
            'qty_delivered' => 20,
        ]);
    }

    // ===================== 2. TITIP CASH =====================

    public function test_titip_cash_ditolak_jika_melebihi_stok_di_motor(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id, 'default_qty_mika' => null]);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->call('chooseAction', 'titip_cash')
            ->set('dropBaru', 15)
            ->call('saveVisit')
            ->assertHasErrors('general')
            ->assertSee($this->guardMessage(10, 15));

        $this->assertEquals(0, Delivery::where('kiosk_id', $kiosk->id)->count());
    }

    // ===================== 3. MULAI TITIPAN =====================

    public function test_mulai_titipan_ditolak_jika_melebihi_stok_di_motor(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id, 'default_qty_mika' => null]);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->call('chooseAction', 'mulai_titipan')
            ->set('dropBaru', 15)
            ->set('jatahMulai', 5)
            ->call('saveVisit')
            ->assertHasErrors('general')
            ->assertSee($this->guardMessage(10, 15));

        $this->assertEquals(0, Delivery::where('kiosk_id', $kiosk->id)->count());
        // Kios belum jadi konsinyasi karena transaksi dibatalkan penuh.
        $this->assertNull($kiosk->fresh()->default_qty_mika);
    }

    // ===================== 4. JUAL CASH CEPAT (WALK-IN) =====================

    public function test_jual_cash_cepat_ditolak_jika_melebihi_stok_di_motor(): void
    {
        Livewire::test(ActiveTrip::class)
            ->call('openWalkInModal')
            ->set('walkInMika', 15)
            ->call('saveWalkInCash')
            ->assertHasErrors('general')
            ->assertSee($this->guardMessage(10, 15));

        $this->assertEquals(0, KioskVisit::where('trip_id', $this->trip->id)->count());
    }

    // ===================== 5. KIOS BARU KONSINYASI DALAM TRIP AKTIF =====================

    public function test_kios_baru_konsinyasi_ditolak_jika_melebihi_stok_di_motor(): void
    {
        $product = Product::factory()->create();
        ProductVariant::factory()->create(['product_id' => $product->id, 'is_active' => true, 'sale_price_per_pack' => 12000]);

        Livewire::test(CreateKiosk::class)
            ->set('namaKios', 'Kios Baru Kelebihan Stok')
            ->set('namaPemilik', 'Pak Lebih')
            ->set('clusterId', $this->cluster->id)
            ->set('jenisKedai', 'konsinyasi')
            ->set('defaultQtyMika', 15)
            ->call('saveKiosk')
            ->assertHasErrors(['defaultQtyMika'])
            ->assertSee($this->guardMessage(10, 15));

        // SELURUH pembuatan kios dibatalkan (rollback), bukan kios tersimpan tanpa titipan.
        $this->assertDatabaseMissing('kiosks', ['name' => 'Kios Baru Kelebihan Stok']);
        $this->trip->refresh();
        $this->assertSame(10, $this->trip->getStokDiMotor());
    }

    // ===================== 6. KOREKSI YANG MENINGKATKAN MIKA =====================

    public function test_koreksi_ditolak_jika_menaikkan_mika_melebihi_stok_di_motor(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id, 'default_qty_mika' => null]);

        // Titip awal 5 mika (stok sisa 5 setelah ini).
        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->call('chooseAction', 'mulai_titipan')
            ->set('dropBaru', 5)
            ->set('jatahMulai', 5)
            ->call('saveVisit')
            ->assertHasNoErrors();

        $visit = KioskVisit::where('kiosk_id', $kiosk->id)->firstOrFail();
        $this->trip->refresh();
        $this->assertSame(5, $this->trip->getStokDiMotor());

        // Koreksi menaikkan ke 20 mika: delivery lama (5) dihapus dulu → stok efektif
        // yang dicek guard = 10 (bukan 5), tapi 20 tetap melebihi → ditolak.
        // Lewat jalur UI SUNGGUHAN (openCorrectionModal → submitCorrection), bukan
        // correctVisit() langsung — MotorStockGuard menaruh pesan di bag 'general',
        // bukan 'correction', dan modal koreksi HANYA PERNAH merender bag 'general'
        // saat $isCorrectionModalOpen bernilai true (lihat perbaikan blade & cek
        // submitCorrection() 27 Okt 2026).
        Livewire::test(ActiveTrip::class)
            ->call('openCorrectionModal', $kiosk->id)
            ->set('dropBaru', 20)
            ->call('submitCorrection')
            ->assertHasErrors('general')
            ->assertSet('isCorrectionModalOpen', true)
            ->assertSee($this->guardMessage(10, 20));

        // Rollback total: visit lama TIDAK ditandai dikoreksi, delivery lama masih ada.
        $visit->refresh();
        $this->assertNull($visit->corrected_at);
        $this->assertDatabaseHas('deliveries', ['kiosk_visit_id' => $visit->id, 'qty_delivered' => 5]);
        $this->trip->refresh();
        $this->assertSame(5, $this->trip->getStokDiMotor());
    }

    public function test_koreksi_lolos_jika_kenaikan_masih_dalam_stok_di_motor(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id, 'default_qty_mika' => null]);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->call('chooseAction', 'mulai_titipan')
            ->set('dropBaru', 5)
            ->set('jatahMulai', 5)
            ->call('saveVisit')
            ->assertHasNoErrors();

        $visit = KioskVisit::where('kiosk_id', $kiosk->id)->firstOrFail();

        // Naik ke 8 (<=10 setelah delivery lama 5 dihapus) — lolos. Jalur UI
        // sungguhan (openCorrectionModal → submitCorrection), lihat catatan di
        // test_koreksi_ditolak_jika_menaikkan_mika_melebihi_stok_di_motor.
        Livewire::test(ActiveTrip::class)
            ->call('openCorrectionModal', $kiosk->id)
            ->set('dropBaru', 8)
            ->call('submitCorrection')
            ->assertHasNoErrors()
            ->assertSet('isCorrectionModalOpen', false);

        $visit->refresh();
        $this->assertNotNull($visit->corrected_at);
        $newVisit = KioskVisit::where('correction_of_visit_id', $visit->id)->firstOrFail();
        $this->assertDatabaseHas('deliveries', ['kiosk_visit_id' => $newVisit->id, 'qty_delivered' => 8]);
        $this->trip->refresh();
        $this->assertSame(2, $this->trip->getStokDiMotor());
    }

    // ===================== 7. DODOL SISA (BS REDISTRIBUSI) TIDAK PERNAH DIBLOKIR =====================

    public function test_dodol_sisa_tidak_pernah_diblokir_guard_walau_melebihi_stok_di_motor(): void
    {
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id, 'default_qty_mika' => null]);

        // Drop konsinyasi 5 (dalam stok 10), tapi Dodol Sisa 1000 (JAUH melebihi stok
        // motor) — guard HANYA menjaga $drop, bukan BS (lihat Trip::getStokDiMotor()).
        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->call('chooseAction', 'mulai_titipan')
            ->set('dropBaru', 5)
            ->set('jatahMulai', 5)
            ->set('adaBsRedistribusi', true)
            ->set('qtyBsMika', 1000)
            ->call('saveVisit')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('deliveries', [
            'kiosk_id' => $kiosk->id,
            'delivery_type' => 'bs_redistribution',
            'qty_delivered' => 1000,
        ]);

        // Stok di motor TIDAK berkurang oleh BS — hanya oleh $drop konsinyasi (5).
        $this->trip->refresh();
        $this->assertSame(5, $this->trip->getStokDiMotor());
        $this->assertSame(1000, (int) $this->trip->qty_bs_redistributed);
    }

    // ===================== 8. MULTI-TENANT: confirmAddStock =====================

    public function test_operator_tidak_bisa_tambah_stok_trip_operator_lain(): void
    {
        $operatorB = User::factory()->create(['role' => 'operator', 'is_active' => true]);
        $clusterB = Cluster::create(['name' => 'Cluster Lain']);
        $tripB = Trip::factory()->create([
            'operator_id' => $operatorB->id,
            'starting_cluster_id' => $clusterB->id,
            'qty_carried_total' => 10,
            'started_at' => now(),
            'trip_date' => today()->format('Y-m-d'),
        ]);

        // CATATAN TEKNIS: Livewire\Features\SupportModels\ModelSynth::hydrate() SELALU
        // memuat ulang model dari meta['class']+meta['key'] milik snapshot TERAKHIR dan
        // MENGABAIKAN nilai baru yang dikirim lewat Testable::set() — jadi
        // ->set('trip', $tripB) tidak bisa dipakai memalsukan properti model yang sudah
        // di-hydrate (beda dari properti primitif spt $selectedKiosk yg default null,
        // yang memang tidak punya meta synth sehingga ->set() menulis nilai mentah
        // langsung). Untuk membuktikan defense-in-depth operator_id di confirmAddStock(),
        // kita ambil instance komponen & tulis langsung properti PHP-nya (meniru snapshot
        // Livewire yang ditamper tanpa terhalang serializer), lalu panggil method-nya
        // langsung (bypass wire protocol) — method itulah yang sesungguhnya diuji.
        $component = Livewire::test(ActiveTrip::class)->instance();
        $component->trip = $tripB;
        $component->tambahStokQty = 50;
        $component->confirmAddStock();

        $this->assertTrue($component->getErrorBag()->has('general'), 'confirmAddStock() harus menolak trip operator lain.');
        $this->assertEquals(0, TripStockAddition::where('trip_id', $tripB->id)->count());
        $tripB->refresh();
        $this->assertSame(10, $tripB->getStokDiMotor());
    }

    // ===================== 9. FORMAT TAMPILAN "90 + 20 tambahan = 110" =====================

    public function test_mika_dibawa_display_format_dengan_tambahan(): void
    {
        $tripDisplay = Trip::factory()->create([
            'operator_id' => $this->operator->id,
            'starting_cluster_id' => $this->cluster->id,
            'qty_carried_total' => 90,
            'started_at' => now(),
            'trip_date' => today()->format('Y-m-d'),
        ]);

        $this->assertSame('90', $tripDisplay->mika_dibawa_display);

        TripStockAddition::create(['trip_id' => $tripDisplay->id, 'qty' => 20, 'added_by' => $this->operator->id]);

        $tripDisplay->refresh();
        $this->assertSame('90 + 20 tambahan = 110', $tripDisplay->mika_dibawa_display);
        $this->assertSame(110, $tripDisplay->getStokDiMotor());
    }

    // ===================== 10. "RACE" — 2 PENYIMPANAN SIMULTAN =====================

    /**
     * Pembuktian keamanan race-condition. CATATAN JUJUR: PHPUnit berjalan SATU
     * proses/SATU koneksi DB per test (RefreshDatabase membungkus test dalam
     * transaction), dan proyek ini SENGAJA menghindari banyak proses `php artisan
     * test` paralel di satu DB MariaDB lokal (bisa crash — lihat NEXT_SESSION.md
     * "CATATAN LINGKUNGAN"). Jadi test ini TIDAK membuktikan konkurensi OS-level
     * sungguhan; ia membuktikan properti yang sebenarnya membuat concurrency aman:
     * MotorStockGuard::assertAvailable() SELALU lockForUpdate() + membaca ULANG
     * baris trip dari DB di dalam transaction tulis — bukan dari state PHP yang
     * mungkin basi. Dua "permintaan" di bawah memakai KOMPONEN Livewire TERPISAH
     * (meniru 2 tab/device operator) yang KEDUANYA mount saat stok masih 10 mika,
     * lalu disimpan BERURUTAN (satu-satunya urutan yang mungkin di proses tunggal).
     * Jika guard memakai angka yang di-cache di awal (bug TOCTOU), permintaan KEDUA
     * akan lolos juga (total 6+6=12 > stok 10). Guard yang benar membaca ulang →
     * permintaan kedua ditolak karena permintaan pertama sudah memakai sebagian stok.
     * Di produksi (2 request HTTP beneran), `lockForUpdate()` membuat request kedua
     * MENUNGGU request pertama commit sebelum membaca — hasil akhirnya SAMA
     * (serial), hanya urutannya ditentukan siapa yang commit duluan, bukan siapa
     * yang mount duluan.
     */
    public function test_dua_penyimpanan_simultan_tetap_serial_dan_benar(): void
    {
        // DUA kios berbeda — bukan karena race-nya per-kios, tapi karena
        // saveVisit() punya guard idempotensi TERPISAH (tagih duplikat dalam 10
        // detik ke KIOS+TRIP yang sama, lihat baris $alreadySaved). Memakai kios
        // yang sama utk 2 "tab" yang disimpan berurutan dlm hitungan milidetik
        // akan ketiban guard ITU dulu (bukan guard stok yang mau kita uji).
        // Race yang diuji di sini murni level TRIP (stok motor dibagi semua kios).
        $kiosk1 = Kiosk::factory()->create(['cluster_id' => $this->cluster->id, 'default_qty_mika' => null]);
        $kiosk2 = Kiosk::factory()->create(['cluster_id' => $this->cluster->id, 'default_qty_mika' => null]);

        // Dua komponen terpisah, KEDUANYA mount selagi stok motor masih 10 mika.
        $tabOperator1 = Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk1->id)
            ->call('chooseAction', 'mulai_titipan')
            ->set('dropBaru', 6)
            ->set('jatahMulai', 6);

        $tabOperator2 = Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk2->id)
            ->call('chooseAction', 'titip_cash')
            ->set('dropBaru', 6);

        // Permintaan PERTAMA commit duluan → stok 10-6=4 tersisa.
        $tabOperator1->call('saveVisit')->assertHasNoErrors();
        $this->trip->refresh();
        $this->assertSame(4, $this->trip->getStokDiMotor());

        // Permintaan KEDUA membaca ULANG stok (bukan angka 10 saat ia mount) → 6 > 4 → ditolak.
        $tabOperator2->call('saveVisit')
            ->assertHasErrors('general')
            ->assertSee($this->guardMessage(4, 6));

        // Total mika yang benar-benar keluar dari motor TETAP 6 — tidak pernah jadi 12.
        $this->trip->refresh();
        $this->assertSame(4, $this->trip->getStokDiMotor());
        $this->assertSame(6, $this->trip->getTotalDropReal());
    }

    /**
     * Unit-level: MotorStockGuard::assertAvailable() sendiri melempar exception
     * dengan angka yang tepat & TIDAK menulis apa pun saat ditolak (dipanggil di
     * luar konteks Livewire, langsung ke Support class).
     */
    public function test_motor_stock_guard_assert_available_melempar_exception_dengan_angka_tepat(): void
    {
        $this->expectException(\App\Exceptions\InsufficientMotorStockException::class);
        $this->expectExceptionMessage($this->guardMessage(10, 11));

        MotorStockGuard::assertAvailable($this->trip, 11);
    }

    public function test_motor_stock_guard_assert_available_lolos_saat_cukup(): void
    {
        $locked = MotorStockGuard::assertAvailable($this->trip, 10);
        $this->assertInstanceOf(Trip::class, $locked);
    }
}
