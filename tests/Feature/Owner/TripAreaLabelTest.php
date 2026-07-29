<?php

namespace Tests\Feature\Owner;

use App\Livewire\Owner\LiveTripProgress;
use App\Models\Cluster;
use App\Models\Delivery;
use App\Models\Kiosk;
use App\Models\KioskVisit;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Settlement;
use App\Models\Trip;
use App\Models\User;
use App\Support\TripAreas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * BAGIAN C — LAPORAN TETAP JUJUR SAAT LINTAS AREA.
 *
 * Masalah yang MUNCUL DARI fix B: trip dengan starting_cluster_id "Kota 1" tapi
 * berisi kunjungan di Pancing membuat kolom "Area" di Riwayat Trip dan denominator
 * "5 dari 54" di LiveTripProgress jadi BOHONG.
 *
 * ⚠️ Yang TIDAK boleh berubah: komisi, omset, untung, stok. Lintas area cuma soal
 * kios mana yang tampil & dikunjungi, bukan cara menghitung uang. Test di bawah
 * MENGUNCI angka-angka itu.
 */
class TripAreaLabelTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $operator;
    private Cluster $kota1;
    private Cluster $pancing;
    private ProductVariant $variant;

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
        $this->kota1 = Cluster::create(['name' => 'Kota 1', 'is_active' => true, 'owner_id' => $this->owner->id]);
        $this->pancing = Cluster::create(['name' => 'Pancing', 'is_active' => true, 'owner_id' => $this->owner->id]);

        $product = Product::factory()->create(['owner_id' => $this->owner->id]);
        $this->variant = ProductVariant::factory()->create([
            'product_id' => $product->id, 'is_active' => true, 'sale_price_per_pack' => 12000,
        ]);
    }

    private function trip(?Cluster $start, bool $ended = true, int $nomor = 1): Trip
    {
        return Trip::factory()->create([
            'owner_id' => $this->owner->id,
            'operator_id' => $this->operator->id,
            'trip_date' => today(),
            'trip_number_of_day' => $nomor,
            'starting_cluster_id' => $start?->id,
            'qty_carried_total' => 75,
            'started_at' => now()->subHours(3),
            'ended_at' => $ended ? now() : null,
        ]);
    }

    /** Catat satu kunjungan + drop konsinyasi di kios tertentu. */
    private function kunjungi(Trip $trip, Kiosk $kiosk, int $mika): Delivery
    {
        $delivery = Delivery::factory()->create([
            'kiosk_id' => $kiosk->id,
            'trip_id' => $trip->id,
            'product_variant_id' => $this->variant->id,
            'delivery_type' => 'consignment',
            'qty_delivered' => $mika,
        ]);

        KioskVisit::create([
            'trip_id' => $trip->id,
            'kiosk_id' => $kiosk->id,
            'visited_at' => now(),
            'visit_action' => 'drop_only',
            'new_delivery_id' => $delivery->id,
        ]);

        return $delivery;
    }

    /** C1/C2 — trip yang menyeberang ditandai, labelnya menyebut area lain. */
    public function test_crossed_trip_is_flagged_and_labelled_honestly(): void
    {
        $trip = $this->trip($this->kota1);

        $a = Kiosk::factory()->create(['cluster_id' => $this->kota1->id, 'name' => 'Kedai Kota']);
        $b = Kiosk::factory()->create(['cluster_id' => $this->pancing->id, 'name' => 'Kedai Pancing']);

        $this->kunjungi($trip, $a, 5);
        $this->kunjungi($trip, $b, 4);

        $area = TripAreas::forTrip($trip->id);

        $this->assertTrue($area['crossed']);
        $this->assertSame('Kota 1 + lintas area: Pancing', $area['label']);
        $this->assertEqualsCanonicalizing([$this->kota1->id, $this->pancing->id], array_keys($area['visited']));
        $this->assertEqualsCanonicalizing([$this->kota1->id, $this->pancing->id], $area['relevant_cluster_ids']);
    }

    /** Trip yang TIDAK menyeberang: perilaku lama, label polos, tak ada penanda. */
    public function test_single_area_trip_keeps_the_plain_label(): void
    {
        $trip = $this->trip($this->kota1);
        $a = Kiosk::factory()->create(['cluster_id' => $this->kota1->id]);
        $this->kunjungi($trip, $a, 5);

        $area = TripAreas::forTrip($trip->id);

        $this->assertFalse($area['crossed']);
        $this->assertSame('Kota 1', $area['label']);
    }

    /** Trip Bebas tetap "Semua Kios" — memang lintas area by design, bukan anomali. */
    public function test_trip_bebas_is_not_reported_as_crossed(): void
    {
        $trip = $this->trip(null);
        $this->kunjungi($trip, Kiosk::factory()->create(['cluster_id' => $this->kota1->id]), 3);
        $this->kunjungi($trip, Kiosk::factory()->create(['cluster_id' => $this->pancing->id]), 3);

        $area = TripAreas::forTrip($trip->id);

        $this->assertFalse($area['crossed']);
        $this->assertSame('Semua Kios', $area['label']);
    }

    /** Kunjungan yang DIKOREKSI tidak boleh membuat trip terlihat menyeberang. */
    public function test_corrected_visit_does_not_count_as_crossing(): void
    {
        $trip = $this->trip($this->kota1);
        $this->kunjungi($trip, Kiosk::factory()->create(['cluster_id' => $this->kota1->id]), 5);

        $salah = Kiosk::factory()->create(['cluster_id' => $this->pancing->id]);
        KioskVisit::create([
            'trip_id' => $trip->id, 'kiosk_id' => $salah->id,
            'visited_at' => now(), 'visit_action' => 'check_only',
            'corrected_at' => now(),
        ]);

        $this->assertFalse(TripAreas::forTrip($trip->id)['crossed']);
        $this->assertSame('Kota 1', TripAreas::forTrip($trip->id)['label']);
    }

    /** Penjualan walk-in (kios sentinel) bukan "menyeberang area". */
    public function test_walk_in_sale_does_not_count_as_crossing(): void
    {
        $trip = $this->trip($this->kota1);
        $this->kunjungi($trip, Kiosk::factory()->create(['cluster_id' => $this->kota1->id]), 5);

        $sentinel = Kiosk::walkInSentinelFor($this->owner->id);
        KioskVisit::create([
            'trip_id' => $trip->id, 'kiosk_id' => $sentinel->id,
            'visited_at' => now(), 'visit_action' => 'cash_sale',
        ]);

        $area = TripAreas::forTrip($trip->id);
        $this->assertFalse($area['crossed']);
        $this->assertSame('Kota 1', $area['label']);
    }

    /** C2 — kolom Area di Riwayat Trip tidak boleh tertulis "Kota 1" polos. */
    public function test_trip_history_list_shows_the_honest_area_label(): void
    {
        $trip = $this->trip($this->kota1);
        $this->kunjungi($trip, Kiosk::factory()->create(['cluster_id' => $this->kota1->id]), 5);
        $this->kunjungi($trip, Kiosk::factory()->create(['cluster_id' => $this->pancing->id]), 4);

        $this->actingAs($this->owner)
            ->get(route('owner.trips.index'))
            ->assertOk()
            ->assertSee('Kota 1 + lintas area: Pancing')
            ->assertSee('lintas');
    }

    /** C2 — detail trip juga. */
    public function test_trip_detail_shows_the_honest_area_label(): void
    {
        $trip = $this->trip($this->kota1);
        $this->kunjungi($trip, Kiosk::factory()->create(['cluster_id' => $this->kota1->id]), 5);
        $this->kunjungi($trip, Kiosk::factory()->create(['cluster_id' => $this->pancing->id]), 4);

        $this->actingAs($this->owner)
            ->get(route('owner.trips.show', $trip))
            ->assertOk()
            ->assertSee('Kota 1 + lintas area: Pancing')
            ->assertSee('Trip ini menyeberang area');
    }

    /** Riwayat Trip tetap BEBAS N+1 walau kolom Area ditambahkan (batch, bukan per-baris). */
    public function test_trip_history_query_count_stays_bounded_with_the_area_column(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $t = Trip::factory()->create([
                'owner_id' => $this->owner->id, 'operator_id' => $this->operator->id,
                'trip_date' => today()->subDays($i), 'trip_number_of_day' => 1,
                'starting_cluster_id' => $this->kota1->id,
                'qty_carried_total' => 50,
                'started_at' => now()->subDays($i), 'ended_at' => now()->subDays($i)->addHours(3),
            ]);
            $this->kunjungi($t, Kiosk::factory()->create(['cluster_id' => $this->kota1->id]), 2);
            $this->kunjungi($t, Kiosk::factory()->create(['cluster_id' => $this->pancing->id]), 2);
        }

        $this->actingAs($this->owner);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get(route('owner.trips.index'))->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Sebelum fitur ini: 8 query untuk 20 baris. TripAreas menambah 2 query BATCH
        // (konstan), bukan 2 per baris. Ambang lama <30 dipertahankan.
        $this->assertLessThan(30, $count, "Riwayat Trip harus konstan, bukan N+1. Aktual: {$count}");
    }

    /**
     * C3 — denominator LiveTripProgress. Kota 1 punya 3 kios, Pancing 4 kios.
     * Operator sudah menyeberang → penyebut = 3 + 4 = 7, BUKAN 3 (yang bikin
     * "4 dari 3 kios") dan BUKAN seluruh kios bisnis.
     */
    public function test_live_trip_progress_denominator_covers_touched_areas_only(): void
    {
        $trip = $this->trip($this->kota1, ended: false);

        $kotaKios = collect(range(1, 3))->map(fn ($i) => Kiosk::factory()->create([
            'cluster_id' => $this->kota1->id, 'name' => "Kota {$i}",
        ]));
        collect(range(1, 4))->each(fn ($i) => Kiosk::factory()->create([
            'cluster_id' => $this->pancing->id, 'name' => "Pancing {$i}",
        ]));
        // Area KETIGA yang belum pernah disentuh — tidak boleh ikut penyebut.
        $medan = Cluster::create(['name' => 'Medan Baru', 'is_active' => true, 'owner_id' => $this->owner->id]);
        collect(range(1, 9))->each(fn ($i) => Kiosk::factory()->create([
            'cluster_id' => $medan->id, 'name' => "Medan {$i}",
        ]));

        // 3 kunjungan di Kota 1, lalu 1 kunjungan di Pancing (menyeberang).
        foreach ($kotaKios as $k) {
            $this->kunjungi($trip, $k, 2);
        }
        $this->kunjungi($trip, Kiosk::where('name', 'Pancing 1')->first(), 2);

        $this->actingAs($this->owner);

        $stats = Livewire::test(LiveTripProgress::class)->viewData('tripStats')[$trip->id];

        $this->assertSame(7, $stats['total_kios'], 'Penyebut = Kota 1 (3) + Pancing (4), tanpa Medan Baru (9).');
        $this->assertTrue($stats['area_crossed']);
        $this->assertSame('Kota 1 + lintas area: Pancing', $stats['area_label']);

        // Pembilang (4) tak pernah melampaui penyebut (7) — gejala paling kasat mata.
        $visited = 7 - $stats['unvisited_kiosks']->count();
        $this->assertSame(4, $visited);
        $this->assertLessThanOrEqual($stats['total_kios'], $visited);
    }

    /** Trip satu-area: denominator PERSIS seperti sebelum perubahan. */
    public function test_live_trip_progress_denominator_unchanged_for_single_area_trip(): void
    {
        $trip = $this->trip($this->kota1, ended: false);

        collect(range(1, 5))->each(fn ($i) => Kiosk::factory()->create([
            'cluster_id' => $this->kota1->id, 'name' => "Kota {$i}",
        ]));
        collect(range(1, 9))->each(fn ($i) => Kiosk::factory()->create([
            'cluster_id' => $this->pancing->id, 'name' => "Pancing {$i}",
        ]));

        $this->kunjungi($trip, Kiosk::where('name', 'Kota 1')->first(), 2);

        $this->actingAs($this->owner);

        $stats = Livewire::test(LiveTripProgress::class)->viewData('tripStats')[$trip->id];

        $this->assertSame(5, $stats['total_kios'], 'Hanya Kota 1 yang disentuh → penyebut tetap 5.');
        $this->assertFalse($stats['area_crossed']);
        $this->assertSame('Kota 1', $stats['area_label']);
    }

    /**
     * 🔴 C4 — KOMISI, OMSET, UNTUNG, STOK IDENTIK. Dua trip dengan isi persis sama
     * (9 mika drop, uang sama), bedanya cuma SATU: yang kedua kios keduanya di area
     * lain. Setiap angka finansial WAJIB sama persis.
     */
    public function test_money_is_identical_whether_or_not_the_trip_crosses_areas(): void
    {
        // Trip A — dua kedai, keduanya di Kota 1 (tidak menyeberang).
        $tripA = $this->trip($this->kota1);
        $a1 = Kiosk::factory()->create(['cluster_id' => $this->kota1->id]);
        $a2 = Kiosk::factory()->create(['cluster_id' => $this->kota1->id]);
        $this->lunasi($this->kunjungi($tripA, $a1, 5), 5);
        $this->lunasi($this->kunjungi($tripA, $a2, 4), 4);

        // Trip B — isi identik, tapi kedai kedua di PANCING (menyeberang).
        $tripB = $this->trip($this->kota1, nomor: 2);
        $b1 = Kiosk::factory()->create(['cluster_id' => $this->kota1->id]);
        $b2 = Kiosk::factory()->create(['cluster_id' => $this->pancing->id]);
        $this->lunasi($this->kunjungi($tripB, $b1, 5), 5);
        $this->lunasi($this->kunjungi($tripB, $b2, 4), 4);

        $tripA->refresh();
        $tripB->refresh();

        // Label BERBEDA (itu memang tujuan bagian C) …
        $this->assertFalse(TripAreas::forTrip($tripA->id)['crossed']);
        $this->assertTrue(TripAreas::forTrip($tripB->id)['crossed']);

        // … tapi UANG & STOK identik, angka konkret.
        $this->assertSame(9, $tripA->getTotalDropReal());
        $this->assertSame(9, $tripB->getTotalDropReal());
        $this->assertSame(9000.0, (float) $tripA->komisi_rian);
        $this->assertSame(9000.0, (float) $tripB->komisi_rian);

        foreach (['omset_val', 'mika_terjual', 'mika_komisi', 'untung_kotor', 'untung_bersih_owner', 'hpp_estimasi'] as $angka) {
            $this->assertEqualsWithDelta(
                (float) $tripA->{$angka},
                (float) $tripB->{$angka},
                0.0001,
                "Angka '{$angka}' berubah hanya karena trip menyeberang area — tidak boleh."
            );
        }

        // Nilai absolutnya juga dikunci, bukan cuma "sama satu sama lain":
        // 9 mika x 15 biji x Rp 800 = Rp 108.000 omset.
        $this->assertSame(108000.0, (float) $tripB->omset_val);
        $this->assertSame(9.0, (float) $tripB->mika_komisi);
    }

    /** Lunasi satu titipan penuh (semua laku, tanpa retur). */
    private function lunasi(Delivery $delivery, int $mika): void
    {
        $biji = $mika * 15;

        $settlement = Settlement::create([
            'delivery_id' => $delivery->id,
            'visit_date' => today(),
            'qty_sold' => $biji,
            'qty_returned_fresh' => 0,
            'qty_returned_expired' => 0,
            'amount_due' => $biji * 800,
            'amount_paid' => $biji * 800,
        ]);

        KioskVisit::where('trip_id', $delivery->trip_id)
            ->where('kiosk_id', $delivery->kiosk_id)
            ->update(['settled_delivery_id' => $delivery->id]);

        $this->assertNotNull($settlement->id);
    }
}
