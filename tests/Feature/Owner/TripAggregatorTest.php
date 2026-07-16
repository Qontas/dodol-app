<?php

namespace Tests\Feature\Owner;

use App\Models\Cluster;
use App\Models\Delivery;
use App\Models\Kiosk;
use App\Models\KioskVisit;
use App\Models\ProductVariant;
use App\Models\Settlement;
use App\Models\Trip;
use App\Models\User;
use App\Support\TripAggregator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PENGAMAN UTAMA optimasi: TripAggregator (batch) HARUS menghasilkan angka PERSIS SAMA
 * dengan accessor Trip per-baris. Ini bukan sekadar "ada", tapi nilai IDENTIK — kalau
 * accessor & batch pernah geser, test ini merah. Skenario kaya: konsinyasi ter-settle,
 * cash sale, BS daur-ulang (dikecualikan komisi), kios BARU (drop_only, first_titip=trip
 * date), kios LAMA, dan satu kunjungan DIKOREKSI (harus diabaikan agregat).
 */
class TripAggregatorTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected User $operator;
    protected Cluster $cluster;
    protected ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create([
            'role' => 'owner', 'is_active' => true,
            'hpp_per_mika' => 9500, 'komisi_per_mika' => 1000,
        ]);
        $this->operator = User::factory()->create([
            'role' => 'operator', 'is_active' => true, 'name' => 'Rian', 'owner_id' => $this->owner->id,
        ]);
        $this->cluster = Cluster::create(['name' => 'C', 'owner_id' => $this->owner->id]);
        $this->variant = ProductVariant::factory()->create(['is_active' => true, 'sale_price_per_pack' => 12000]);
    }

    private function buildRichTrip($date): Trip
    {
        $trip = Trip::factory()->create([
            'owner_id' => $this->owner->id, 'operator_id' => $this->operator->id,
            'starting_cluster_id' => $this->cluster->id, 'trip_date' => \Illuminate\Support\Carbon::parse($date)->format('Y-m-d'),
            'trip_number_of_day' => 1, 'started_at' => \Illuminate\Support\Carbon::parse($date),
            'ended_at' => \Illuminate\Support\Carbon::parse($date), 'qty_carried_total' => 100,
        ]);

        // Kios BARU (first_titip_date = trip_date) → drop_only, masuk kios_baru & mika_kios_baru.
        $kiosBaru = Kiosk::factory()->create(['cluster_id' => $this->cluster->id, 'first_titip_date' => \Illuminate\Support\Carbon::parse($date)->format('Y-m-d')]);
        $dBaru = $this->drop($trip, $kiosBaru, 7, 'consignment');
        KioskVisit::create(['trip_id' => $trip->id, 'kiosk_id' => $kiosBaru->id, 'visited_at' => \Illuminate\Support\Carbon::parse($date), 'visit_action' => 'drop_only', 'new_delivery_id' => $dBaru->id, 'extension_granted' => false]);

        // Kios LAMA (first_titip_date lama) → tagih+titip (settle → omset & mika_terjual).
        $kiosLama = Kiosk::factory()->create(['cluster_id' => $this->cluster->id, 'first_titip_date' => \Illuminate\Support\Carbon::parse($date)->subDays(30)->format('Y-m-d')]);
        $dLama = $this->drop($trip, $kiosLama, 10, 'consignment');
        Settlement::create(['delivery_id' => $dLama->id, 'visit_date' => \Illuminate\Support\Carbon::parse($date)->format('Y-m-d'), 'qty_sold' => 150, 'qty_returned_fresh' => 0, 'qty_returned_expired' => 0, 'amount_due' => 120000, 'amount_paid' => 120000, 'status' => 'paid']);
        KioskVisit::create(['trip_id' => $trip->id, 'kiosk_id' => $kiosLama->id, 'visited_at' => \Illuminate\Support\Carbon::parse($date), 'visit_action' => 'drop_and_settle', 'new_delivery_id' => $dLama->id, 'settled_delivery_id' => $dLama->id, 'extension_granted' => false]);

        // Cash sale (drop → komisi, tak nambah omset).
        $this->drop($trip, $kiosLama, 3, 'cash_sale');

        // BS daur-ulang (dikecualikan komisi).
        $this->drop($trip, $kiosLama, 2, 'bs_redistribution');

        // Kunjungan DIKOREKSI (corrected_at terisi) → HARUS diabaikan agregat aktif.
        KioskVisit::create(['trip_id' => $trip->id, 'kiosk_id' => $kiosLama->id, 'visited_at' => \Illuminate\Support\Carbon::parse($date), 'visit_action' => 'check_only', 'corrected_at' => now(), 'extension_granted' => false]);

        return $trip;
    }

    private function drop(Trip $trip, Kiosk $kiosk, int $mika, string $type): Delivery
    {
        return Delivery::factory()->create([
            'kiosk_id' => $kiosk->id, 'trip_id' => $trip->id,
            'qty_delivered' => $mika, 'product_variant_id' => $this->variant->id, 'delivery_type' => $type,
        ]);
    }

    public function test_batch_numbers_identical_to_per_row_accessors(): void
    {
        // Dua trip untuk membuktikan batch benar per-trip (bukan tercampur).
        $tripA = $this->buildRichTrip(today());
        $tripB = $this->buildRichTrip(today()->subDay());

        $hpp = $this->owner->getHppPerMikaValue();
        $komisi = $this->owner->getKomisiPerMikaValue();
        $batch = TripAggregator::for([$tripA->id, $tripB->id], $hpp, $komisi);

        foreach ([$tripA, $tripB] as $trip) {
            $trip->refresh();
            $a = $batch[$trip->id];

            // Nilai IDENTIK accessor per-baris (bukan sekadar "ada").
            $this->assertEqualsWithDelta($trip->omset_val, $a['omset'], 0.0001, 'omset');
            $this->assertEqualsWithDelta($trip->mika_terjual, $a['mika_terjual'], 0.0001, 'mika_terjual');
            $this->assertEqualsWithDelta($trip->mika_komisi, $a['mika_komisi'], 0.0001, 'mika_komisi');
            $this->assertEqualsWithDelta($trip->komisi_rian, $a['komisi'], 0.0001, 'komisi_rian');
            $this->assertEqualsWithDelta($trip->hpp_estimasi, $a['hpp_estimasi'], 0.0001, 'hpp_estimasi');
            $this->assertEqualsWithDelta($trip->untung_kotor, $a['untung_kotor'], 0.0001, 'untung_kotor');
            $this->assertEqualsWithDelta($trip->untung_bersih_owner, $a['untung_bersih'], 0.0001, 'untung_bersih');
            $this->assertSame($trip->kios_baru_count, $a['kios_baru_count'], 'kios_baru_count');
            $this->assertEqualsWithDelta($trip->mika_kios_baru, $a['mika_kios_baru'], 0.0001, 'mika_kios_baru');
            $this->assertSame($trip->kios_lama_count, $a['kios_lama_count'], 'kios_lama_count');
            $this->assertSame((int) $trip->deliveries()->sum('qty_delivered'), $a['mika_diantar'], 'mika_diantar');
        }

        // Sanity angka konkret trip A: omset 120.000; mika_komisi = 7+10+3 = 20 (BS 2 keluar);
        // komisi 20.000; mika_terjual 10; untung_kotor 10×2.500=25.000; untung_bersih 5.000;
        // kios_baru 1 (mika 7); active_visits 2 (koreksi diabaikan) → kios_lama 1.
        $aA = $batch[$tripA->id];
        $this->assertEqualsWithDelta(120000, $aA['omset'], 0.0001);
        $this->assertEqualsWithDelta(20000, $aA['komisi'], 0.0001);
        $this->assertEqualsWithDelta(10, $aA['mika_terjual'], 0.0001);
        $this->assertEqualsWithDelta(25000, $aA['untung_kotor'], 0.0001);
        $this->assertEqualsWithDelta(5000, $aA['untung_bersih'], 0.0001);
        $this->assertSame(1, $aA['kios_baru_count']);
        $this->assertEqualsWithDelta(7, $aA['mika_kios_baru'], 0.0001);
        $this->assertSame(1, $aA['kios_lama_count']);
        $this->assertSame(2, $aA['active_visits']);
    }

    public function test_empty_trip_ids_returns_empty(): void
    {
        $this->assertSame([], TripAggregator::for([], 9500, 1000));
    }
}
