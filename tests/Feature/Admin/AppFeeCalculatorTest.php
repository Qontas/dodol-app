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
