<?php

namespace Tests\Feature\Admin;

use App\Models\Cluster;
use App\Models\Delivery;
use App\Models\Kiosk;
use App\Models\ProductVariant;
use App\Models\Trip;
use App\Models\User;
use App\Support\AppFeeCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TUGAS 4c (BRIEF 23 Okt 2026) — skenario wajib brief poin 4g, dalam SATU trip demi
 * satu per owner (dan beberapa trip tambahan untuk kasus arsip/sentinel/tarif-berubah),
 * membuktikan SETIAP pengecualian AppFeeCalculator persis sesuai aturan 4c:
 *
 *   Owner A (tarif Rp 300):
 *     - Kios lama "Bu Sri" (sudah ada delivery di trip SENTINEL = saldo awal) →
 *       "tagih + titip ulang" 4 mika di trip nyata → DIHITUNG (bukan "pertama").
 *     - Kios baru "Pak Amin" → titipan PERTAMA 3 mika di trip 1 → TIDAK dihitung (0);
 *       titipan KE-2 3 mika di trip 2 → DIHITUNG.
 *     - Titip Cash 2 mika → DIHITUNG semua (bukan consignment, tak kena aturan "pertama").
 *     - Jual Cash Cepat (walk-in) 2 mika → DIHITUNG semua.
 *     - Dodol Sisa (bs_redistribution) 1 mika → TIDAK dihitung.
 *     - Trip SENTINEL (opening balance Bu Sri, 10 mika) → TIDAK dihitung sama sekali.
 *     - Trip DIARSIP (7 mika, consignment, bukan "pertama" kiosnya) → TIDAK dihitung.
 *     Total mika kena fee = 4+0+3+2+2 = 11, fee = 11 × Rp300 = Rp3.300.
 *
 *   Owner B (tarif NULL/Rp0): mika kena fee tetap terhitung tapi fee Rp = Rp0.
 *
 *   Owner C (tarif DIUBAH saat trip sudah berjalan): trip pakai snapshot tarif LAMA,
 *     bukan tarif BARU owner saat ini — fee harus tetap pakai snapshot.
 */
class AppFeeCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->variant = ProductVariant::factory()->create(['is_active' => true]);
    }

    public function test_full_brief_4g_scenario_owner_a(): void
    {
        $ownerA = User::factory()->create(['role' => 'owner', 'is_active' => true, 'app_fee_per_pack' => 300]);
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $ownerA->id]);
        $sentinelOperator = User::factory()->create([
            'role' => 'operator', 'is_active' => true, 'owner_id' => $ownerA->id,
            'email' => User::SYSTEM_OPERATOR_EMAIL_PREFIX.'ownera@test.test',
        ]);
        $cluster = Cluster::create(['name' => 'Cluster A', 'owner_id' => $ownerA->id]);

        $buSri = Kiosk::factory()->create(['cluster_id' => $cluster->id, 'name' => 'Kios Bu Sri']);
        $pakAmin = Kiosk::factory()->create(['cluster_id' => $cluster->id, 'name' => 'Kios Pak Amin']);

        // --- Trip SENTINEL (saldo awal) — Bu Sri sudah tercatat di sini, 10 mika,
        //     operator akun sistem → TIDAK PERNAH dihitung (aturan 2), DAN inilah
        //     delivery "pertama" (MIN id) Bu Sri yang membuat delivery non-sentinel
        //     berikutnya TIDAK lagi dianggap "pertama".
        $tripSentinel = Trip::create([
            'owner_id' => $ownerA->id, 'operator_id' => $sentinelOperator->id,
            'trip_date' => '2000-01-01', 'trip_number_of_day' => 1,
        ]);
        Delivery::factory()->create([
            'kiosk_id' => $buSri->id, 'trip_id' => $tripSentinel->id,
            'product_variant_id' => $this->variant->id,
            'delivery_type' => 'consignment', 'qty_delivered' => 10,
        ]);

        // --- Trip 1 (nyata, hari ini, tarif snapshot 300).
        $trip1 = Trip::create([
            'owner_id' => $ownerA->id, 'operator_id' => $operator->id,
            'trip_date' => today(), 'trip_number_of_day' => 2,
            'started_at' => now(), 'ended_at' => now(),
            'app_fee_per_pack_snapshot' => 300,
        ]);

        // Bu Sri: "tagih + titip ulang" 4 mika — BUKAN "pertama" (sudah ada di
        // sentinel) → DIHITUNG PENUH.
        Delivery::factory()->create([
            'kiosk_id' => $buSri->id, 'trip_id' => $trip1->id,
            'product_variant_id' => $this->variant->id,
            'delivery_type' => 'consignment', 'qty_delivered' => 4,
        ]);

        // Pak Amin: titipan KONSINYASI PERTAMA kios ini (tak ada riwayat sentinel)
        // 3 mika → TIDAK DIHITUNG.
        Delivery::factory()->create([
            'kiosk_id' => $pakAmin->id, 'trip_id' => $trip1->id,
            'product_variant_id' => $this->variant->id,
            'delivery_type' => 'consignment', 'qty_delivered' => 3,
        ]);

        // Titip Cash 2 mika → DIHITUNG (cash_sale tak kena aturan "pertama").
        Delivery::factory()->create([
            'kiosk_id' => $buSri->id, 'trip_id' => $trip1->id,
            'product_variant_id' => $this->variant->id,
            'delivery_type' => 'cash_sale', 'qty_delivered' => 2,
        ]);

        // Jual Cash Cepat (walk-in) 2 mika → DIHITUNG.
        Delivery::factory()->create([
            'kiosk_id' => $pakAmin->id, 'trip_id' => $trip1->id,
            'product_variant_id' => $this->variant->id,
            'delivery_type' => 'cash_sale', 'qty_delivered' => 2,
        ]);

        // Dodol Sisa (bs_redistribution) 1 mika → TIDAK DIHITUNG.
        Delivery::factory()->create([
            'kiosk_id' => $buSri->id, 'trip_id' => $trip1->id,
            'product_variant_id' => $this->variant->id,
            'delivery_type' => 'bs_redistribution', 'qty_delivered' => 1,
        ]);

        // --- Trip 2 (nyata, hari ini) — Pak Amin titip LAGI (3 mika) → ini titipan
        //     KE-2 kiosnya → DIHITUNG.
        $trip2 = Trip::create([
            'owner_id' => $ownerA->id, 'operator_id' => $operator->id,
            'trip_date' => today(), 'trip_number_of_day' => 3,
            'started_at' => now(), 'ended_at' => now(),
            'app_fee_per_pack_snapshot' => 300,
        ]);
        Delivery::factory()->create([
            'kiosk_id' => $pakAmin->id, 'trip_id' => $trip2->id,
            'product_variant_id' => $this->variant->id,
            'delivery_type' => 'consignment', 'qty_delivered' => 3,
        ]);

        // --- Trip 3: DIARSIP (soft delete). 7 mika consignment ke Bu Sri (bukan
        //     "pertama" kiosnya) → TIDAK DIHITUNG karena tripnya terarsip (aturan 3),
        //     TERLEPAS dari aturan "pertama".
        $trip3 = Trip::create([
            'owner_id' => $ownerA->id, 'operator_id' => $operator->id,
            'trip_date' => today(), 'trip_number_of_day' => 4,
            'started_at' => now(), 'ended_at' => now(),
            'app_fee_per_pack_snapshot' => 300,
        ]);
        Delivery::factory()->create([
            'kiosk_id' => $buSri->id, 'trip_id' => $trip3->id,
            'product_variant_id' => $this->variant->id,
            'delivery_type' => 'consignment', 'qty_delivered' => 7,
        ]);
        $trip3->delete(); // soft delete (arsip)

        $summary = AppFeeCalculator::summaryForOwners();

        $this->assertArrayHasKey($ownerA->id, $summary);
        $row = $summary[$ownerA->id];

        $this->assertSame(300.0, $row['rate']);
        $this->assertSame(11, $row['mika_today'], 'mika hari ini: 4 (BuSri) + 0 (Amin#1) + 3 (Amin#2) + 2 (cash) + 2 (cash) = 11.');
        $this->assertSame(11, $row['mika_month']);
        $this->assertSame(11, $row['mika_total']);
        $this->assertEqualsWithDelta(3300.0, $row['fee_today'], 0.001, '11 mika × Rp300 = Rp3.300.');
        $this->assertEqualsWithDelta(3300.0, $row['fee_month'], 0.001);
        $this->assertEqualsWithDelta(3300.0, $row['fee_total'], 0.001);
    }

    public function test_owner_with_zero_rate_shows_rp0_fee_regardless_of_volume(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true, 'app_fee_per_pack' => null]);
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $owner->id]);
        $cluster = Cluster::create(['name' => 'Cluster B', 'owner_id' => $owner->id]);
        $kiosk = Kiosk::factory()->create(['cluster_id' => $cluster->id]);

        $trip = Trip::create([
            'owner_id' => $owner->id, 'operator_id' => $operator->id,
            'trip_date' => today(), 'trip_number_of_day' => 1,
            'started_at' => now(), 'ended_at' => now(),
            'app_fee_per_pack_snapshot' => 0,
        ]);
        Delivery::factory()->create([
            'kiosk_id' => $kiosk->id, 'trip_id' => $trip->id,
            'product_variant_id' => $this->variant->id,
            'delivery_type' => 'cash_sale', 'qty_delivered' => 5,
        ]);

        $summary = AppFeeCalculator::summaryForOwners();
        $row = $summary[$owner->id];

        $this->assertSame(0.0, $row['rate']);
        $this->assertSame(5, $row['mika_total'], 'Mika tetap dihitung meski tarif 0 — cuma Rp-nya yang 0.');
        $this->assertEqualsWithDelta(0.0, $row['fee_total'], 0.001);
    }

    public function test_rate_change_mid_history_does_not_affect_already_started_trip(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true, 'app_fee_per_pack' => 300]);
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $owner->id]);
        $cluster = Cluster::create(['name' => 'Cluster C', 'owner_id' => $owner->id]);
        $kiosk = Kiosk::factory()->create(['cluster_id' => $cluster->id]);

        // Trip dimulai SAAT tarif owner masih Rp300 → snapshot = 300.
        $trip = Trip::create([
            'owner_id' => $owner->id, 'operator_id' => $operator->id,
            'trip_date' => today(), 'trip_number_of_day' => 1,
            'started_at' => now(), 'ended_at' => now(),
            'app_fee_per_pack_snapshot' => 300,
        ]);
        Delivery::factory()->create([
            'kiosk_id' => $kiosk->id, 'trip_id' => $trip->id,
            'product_variant_id' => $this->variant->id,
            'delivery_type' => 'cash_sale', 'qty_delivered' => 4,
        ]);

        // Owner menaikkan tarif SETELAH trip ini berjalan.
        $owner->update(['app_fee_per_pack' => 500]);

        $summary = AppFeeCalculator::summaryForOwners();
        $row = $summary[$owner->id];

        $this->assertSame(500.0, $row['rate'], 'Kolom "Tarif" di widget tampilkan tarif owner SAAT INI (referensi).');
        $this->assertSame(4, $row['mika_total']);
        $this->assertEqualsWithDelta(1200.0, $row['fee_total'], 0.001, 'Fee Rp WAJIB pakai snapshot lama (300), bukan tarif baru (500): 4×300=1200, BUKAN 4×500=2000.');
    }

    /**
     * TUGAS 1e (BRIEF 23 Okt 2026, lanjutan) — skenario Bu Sri/Pak Amin dari
     * test_full_brief_4g_scenario_owner_a() di atas, TAPI disebar ke 2
     * TANGGAL berbeda dalam bulan yang sama: trip1 (Bu Sri 4 + Amin#1 3 +
     * cash 2 + cash 2 + BS 1) di tanggal awal bulan+4 hari, trip2 (Amin#2 3)
     * di tanggal awal bulan+9 hari. Trip diarsip (7 mika) juga di tanggal
     * pertama — harus tetap 0 meski tanggalnya sama dengan trip1 yang valid
     * (exclude-nya per-trip, bukan per-tanggal).
     *
     * Dipakai juga sebagai bukti TUGAS 1c: total Fee method baru ini HARUS
     * SAMA PERSIS dengan "Fee bulan ini" (fee_month) dari summaryForOwners() —
     * lihat test_summary_by_owner_and_date_total_matches_summary_for_owners
     * di bawah untuk pembuktian eksplisit.
     */
    public function test_summary_by_owner_and_date_spread_across_two_dates(): void
    {
        $ownerA = User::factory()->create(['role' => 'owner', 'is_active' => true, 'app_fee_per_pack' => 300]);
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $ownerA->id]);
        $sentinelOperator = User::factory()->create([
            'role' => 'operator', 'is_active' => true, 'owner_id' => $ownerA->id,
            'email' => User::SYSTEM_OPERATOR_EMAIL_PREFIX.'ownera@test.test',
        ]);
        $cluster = Cluster::create(['name' => 'Cluster A Harian', 'owner_id' => $ownerA->id]);

        $buSri = Kiosk::factory()->create(['cluster_id' => $cluster->id, 'name' => 'Kios Bu Sri H']);
        $pakAmin = Kiosk::factory()->create(['cluster_id' => $cluster->id, 'name' => 'Kios Pak Amin H']);

        // Dua tanggal berbeda, SELALU di dalam bulan berjalan (aman dari batas
        // bulan — setiap bulan minimal 28 hari, +4 dan +9 dari awal bulan pasti ada).
        $monthStart = today()->startOfMonth();
        $date1 = (clone $monthStart)->addDays(4);
        $date2 = (clone $monthStart)->addDays(9);

        // Trip SENTINEL (saldo awal Bu Sri, 10 mika) — di luar bulan ini, tak
        // pernah dihitung (aturan 2), tapi menandai delivery "pertama" Bu Sri.
        $tripSentinel = Trip::create([
            'owner_id' => $ownerA->id, 'operator_id' => $sentinelOperator->id,
            'trip_date' => '2000-01-01', 'trip_number_of_day' => 1,
        ]);
        Delivery::factory()->create([
            'kiosk_id' => $buSri->id, 'trip_id' => $tripSentinel->id,
            'product_variant_id' => $this->variant->id,
            'delivery_type' => 'consignment', 'qty_delivered' => 10,
        ]);

        // --- TANGGAL 1 ($date1): trip1 — Bu Sri (bukan "pertama") 4 + Pak Amin
        //     ("pertama" kiosnya) 3 + cash 2 + cash 2 + BS 1 (tak dihitung sama
        //     sekali, aturan 1).
        $trip1 = Trip::create([
            'owner_id' => $ownerA->id, 'operator_id' => $operator->id,
            'trip_date' => $date1->toDateString(), 'trip_number_of_day' => 2,
            'started_at' => $date1, 'ended_at' => $date1,
            'app_fee_per_pack_snapshot' => 300,
        ]);
        Delivery::factory()->create([
            'kiosk_id' => $buSri->id, 'trip_id' => $trip1->id,
            'product_variant_id' => $this->variant->id,
            'delivery_type' => 'consignment', 'qty_delivered' => 4,
        ]);
        Delivery::factory()->create([
            'kiosk_id' => $pakAmin->id, 'trip_id' => $trip1->id,
            'product_variant_id' => $this->variant->id,
            'delivery_type' => 'consignment', 'qty_delivered' => 3,
        ]);
        Delivery::factory()->create([
            'kiosk_id' => $buSri->id, 'trip_id' => $trip1->id,
            'product_variant_id' => $this->variant->id,
            'delivery_type' => 'cash_sale', 'qty_delivered' => 2,
        ]);
        Delivery::factory()->create([
            'kiosk_id' => $pakAmin->id, 'trip_id' => $trip1->id,
            'product_variant_id' => $this->variant->id,
            'delivery_type' => 'cash_sale', 'qty_delivered' => 2,
        ]);
        Delivery::factory()->create([
            'kiosk_id' => $buSri->id, 'trip_id' => $trip1->id,
            'product_variant_id' => $this->variant->id,
            'delivery_type' => 'bs_redistribution', 'qty_delivered' => 1,
        ]);

        // Trip DIARSIP, tanggal SAMA dengan trip1 ($date1), 7 mika ke Bu Sri —
        // TIDAK DIHITUNG sama sekali (aturan 3), biar tanggal $date1 tak diam-diam
        // kebocoran dari trip terarsip.
        $tripArsip = Trip::create([
            'owner_id' => $ownerA->id, 'operator_id' => $operator->id,
            'trip_date' => $date1->toDateString(), 'trip_number_of_day' => 3,
            'started_at' => $date1, 'ended_at' => $date1,
            'app_fee_per_pack_snapshot' => 300,
        ]);
        Delivery::factory()->create([
            'kiosk_id' => $buSri->id, 'trip_id' => $tripArsip->id,
            'product_variant_id' => $this->variant->id,
            'delivery_type' => 'consignment', 'qty_delivered' => 7,
        ]);
        $tripArsip->delete();

        // --- TANGGAL 2 ($date2): trip2 — Pak Amin titip KE-2 (3 mika, bukan
        //     "pertama" lagi) → DIHITUNG PENUH.
        $trip2 = Trip::create([
            'owner_id' => $ownerA->id, 'operator_id' => $operator->id,
            'trip_date' => $date2->toDateString(), 'trip_number_of_day' => 4,
            'started_at' => $date2, 'ended_at' => $date2,
            'app_fee_per_pack_snapshot' => 300,
        ]);
        Delivery::factory()->create([
            'kiosk_id' => $pakAmin->id, 'trip_id' => $trip2->id,
            'product_variant_id' => $this->variant->id,
            'delivery_type' => 'consignment', 'qty_delivered' => 3,
        ]);

        $rows = AppFeeCalculator::summaryByOwnerAndDate((int) $monthStart->year, (int) $monthStart->month);

        $byDate = collect($rows)->keyBy('date');

        $this->assertCount(2, $rows, 'Hanya 2 tanggal yang ada aktivitas (tak ada baris nihil).');

        $row1 = $byDate[$date1->toDateString()];
        $this->assertSame($ownerA->id, $row1['owner_id']);
        $this->assertSame(11, $row1['mika_diantar'], 'Mika diantar tgl 1: 4(BuSri)+3(Amin#1, TETAP dihitung di sini)+2(cash)+2(cash)=11 (BS & arsip tak ikut).');
        $this->assertSame(8, $row1['mika_kena_fee'], 'Mika kena fee tgl 1: 4(BuSri, bukan pertama)+0(Amin#1, PERTAMA)+2(cash)+2(cash)=8.');
        $this->assertEqualsWithDelta(2400.0, $row1['fee'], 0.001, '8 mika × Rp300 = Rp2.400.');

        $row2 = $byDate[$date2->toDateString()];
        $this->assertSame($ownerA->id, $row2['owner_id']);
        $this->assertSame(3, $row2['mika_diantar'], 'Mika diantar tgl 2: Amin#2 3 mika.');
        $this->assertSame(3, $row2['mika_kena_fee'], 'Mika kena fee tgl 2: Amin#2 BUKAN pertama lagi → dihitung penuh.');
        $this->assertEqualsWithDelta(900.0, $row2['fee'], 0.001, '3 mika × Rp300 = Rp900.');

        // Total bulan ini (jumlah 2 tanggal) — IDENTIK dgn skenario 4g non-sebar
        // (11 mika kena fee, Rp3.300) karena aktivitasnya sama, cuma tanggalnya beda.
        $totalMikaKenaFee = array_sum(array_column($rows, 'mika_kena_fee'));
        $totalFee = array_sum(array_column($rows, 'fee'));
        $this->assertSame(11, $totalMikaKenaFee);
        $this->assertEqualsWithDelta(3300.0, $totalFee, 0.001);
    }

    /**
     * TUGAS 1c (BRIEF 23 Okt 2026, lanjutan) — total Fee bulan ini dari
     * summaryByOwnerAndDate() (dijumlah lintas semua tanggal) HARUS SAMA
     * PERSIS dengan kolom "Fee bulan ini" (fee_month) milik summaryForOwners(),
     * sumber kebenaran widget "Fee Aplikasi" yang sudah ada. Pakai skenario
     * acak sederhana (beda dari test di atas) supaya pembuktian ini independen
     * dari satu skenario spesifik.
     */
    public function test_summary_by_owner_and_date_total_matches_summary_for_owners(): void
    {
        $ownerA = $this->seedOwnerWithCashSale(); // trip_date = today(), tarif 300, 5 mika cash_sale
        $ownerB = $this->seedOwnerWithCashSale();
        $ownerC = $this->seedOwnerWithCashSale();

        $byOwner = AppFeeCalculator::summaryForOwners();
        $byDate = AppFeeCalculator::summaryByOwnerAndDate((int) today()->year, (int) today()->month);

        $totalPerOwnerFromDaily = [];
        foreach ($byDate as $row) {
            $totalPerOwnerFromDaily[$row['owner_id']] = ($totalPerOwnerFromDaily[$row['owner_id']] ?? 0.0) + $row['fee'];
        }

        foreach ([$ownerA, $ownerB, $ownerC] as $owner) {
            $this->assertEqualsWithDelta(
                $byOwner[$owner->id]['fee_month'],
                $totalPerOwnerFromDaily[$owner->id] ?? 0.0,
                0.001,
                "Owner {$owner->id}: total fee widget harian HARUS sama dengan fee_month widget Fee Aplikasi."
            );
        }
    }

    public function test_query_count_for_one_owner(): void
    {
        $this->seedOwnerWithCashSale();
        $count = $this->countCalculatorQueries();

        $this->assertLessThanOrEqual(3, $count, "1 owner: {$count} query — AppFeeCalculator: 1 query owners + 2 query batch = 3 query konstan.");
    }

    public function test_query_count_for_five_owners_stays_in_same_order_of_magnitude(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->seedOwnerWithCashSale();
        }
        $count = $this->countCalculatorQueries();

        $this->assertLessThanOrEqual(3, $count, "5 owner: {$count} query — indikasi N+1 per-owner kembali (harus tetap 3, IDENTIK dgn 1 owner).");
    }

    private function seedOwnerWithCashSale(): User
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true, 'app_fee_per_pack' => 300]);
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $owner->id]);
        $cluster = Cluster::create(['name' => 'C-'.$owner->id, 'owner_id' => $owner->id]);
        $kiosk = Kiosk::factory()->create(['cluster_id' => $cluster->id]);
        $trip = Trip::create([
            'owner_id' => $owner->id, 'operator_id' => $operator->id,
            'trip_date' => today(), 'trip_number_of_day' => 1,
            'started_at' => now(), 'ended_at' => now(),
            'app_fee_per_pack_snapshot' => 300,
        ]);
        Delivery::factory()->create([
            'kiosk_id' => $kiosk->id, 'trip_id' => $trip->id,
            'product_variant_id' => $this->variant->id,
            'delivery_type' => 'cash_sale', 'qty_delivered' => 5,
        ]);

        return $owner;
    }

    private function countCalculatorQueries(): int
    {
        \Illuminate\Support\Facades\DB::flushQueryLog();
        \Illuminate\Support\Facades\DB::enableQueryLog();
        AppFeeCalculator::summaryForOwners();
        $count = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();

        return $count;
    }
}
