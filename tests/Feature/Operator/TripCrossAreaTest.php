<?php

namespace Tests\Feature\Operator;

use App\Livewire\Operator\ActiveTrip;
use App\Models\Cluster;
use App\Models\Commission;
use App\Models\Delivery;
use App\Models\Kiosk;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * BAGIAN B — LINTAS AREA DI TENGAH TRIP (permintaan owner 29 Juli 2026).
 *
 * KONTEKS BISNIS: operator bawa 75 mika, selesai keliling Kota 1, dodol MASIH SISA
 * dan harus habis sebelum pulang. Dia perlu lanjut ke kedai area LAIN tanpa
 * mengakhiri trip — kalau akhiri lalu mulai trip baru, komisi & data pengantaran
 * terpecah jadi dua trip.
 *
 * TEMUAN INVESTIGASI yang jadi dasar fix: jalur TULIS sudah siap. openVisitModal()
 * → ownedKiosk() hanya memeriksa OWNER, tak pernah memeriksa cluster. Yang dikunci
 * selama ini cuma DAFTARNYA (`where('cluster_id', $this->starting_cluster_id)`,
 * dibaca sekali di mount, tanpa UI untuk menggantinya).
 *
 * CATATAN GPS: cakupan koordinat cuma ~12% (119 dari 1014 kios), jadi lintas area
 * TIDAK BOLEH bersandar pada "Urutkan Jarak" — pemilihan area harus eksplisit.
 */
class TripCrossAreaTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: User, 2: Cluster, 3: Cluster} */
    private function scaffold(): array
    {
        $owner = User::factory()->create([
            'role' => 'owner', 'is_active' => true,
            'komisi_per_mika' => 1000, 'hpp_per_mika' => 9500,
        ]);
        $operator = User::factory()->create([
            'role' => 'operator', 'is_active' => true, 'owner_id' => $owner->id,
        ]);

        $kota1 = Cluster::create(['name' => 'Kota 1', 'is_active' => true, 'owner_id' => $owner->id]);
        $pancing = Cluster::create(['name' => 'Pancing', 'is_active' => true, 'owner_id' => $owner->id]);

        return [$owner, $operator, $kota1, $pancing];
    }

    /** Varian WAJIB milik produk owner ini — resolveActiveVariant() ter-scope owner. */
    private function variantFor(User $owner): ProductVariant
    {
        $product = Product::factory()->create(['owner_id' => $owner->id]);

        return ProductVariant::factory()->create([
            'product_id' => $product->id, 'is_active' => true, 'sale_price_per_pack' => 12000,
        ]);
    }

    private function tripDi(User $owner, User $operator, ?Cluster $cluster, int $bawa = 75): Trip
    {
        return Trip::factory()->create([
            'owner_id' => $owner->id,
            'operator_id' => $operator->id,
            'trip_date' => today(),
            'trip_number_of_day' => 1,
            'starting_cluster_id' => $cluster?->id,
            'started_at' => now(),
            'ended_at' => null,
            'qty_carried_total' => $bawa,
        ]);
    }

    /** B1 — pilih area lain → kedai area itu tampil, trip TIDAK berubah/berakhir. */
    public function test_switching_area_shows_the_other_areas_kiosks_without_touching_the_trip(): void
    {
        [$owner, $operator, $kota1, $pancing] = $this->scaffold();
        $trip = $this->tripDi($owner, $operator, $kota1);

        Kiosk::factory()->create(['cluster_id' => $kota1->id, 'name' => 'Kedai Kota Satu', 'sort_order' => 1]);
        Kiosk::factory()->create(['cluster_id' => $pancing->id, 'name' => 'Sidorukun', 'sort_order' => 54]);

        $this->actingAs($operator);

        $component = Livewire::test(ActiveTrip::class);

        // Awalnya terkunci area awal (perilaku lama tetap).
        $this->assertSame(['Kedai Kota Satu'], $component->viewData('kiosks')->pluck('name')->all());

        $component->call('switchArea', $pancing->id);

        $this->assertSame(['Sidorukun'], $component->viewData('kiosks')->pluck('name')->all());

        // 🔴 Trip TIDAK berubah dan TIDAK berakhir — cuma daftarnya yang bergeser.
        $trip->refresh();
        $this->assertNull($trip->ended_at);
        $this->assertSame($kota1->id, $trip->starting_cluster_id, 'starting_cluster_id tetap catatan area AWAL.');
        $this->assertSame(1, Trip::count(), 'Tidak boleh ada trip kedua.');
        $component->assertSet('trip.id', $trip->id);
    }

    /** B1 — opsi "Semua Area" juga tersedia. */
    public function test_semua_area_shows_every_area(): void
    {
        [$owner, $operator, $kota1, $pancing] = $this->scaffold();
        $this->tripDi($owner, $operator, $kota1);

        Kiosk::factory()->create(['cluster_id' => $kota1->id, 'name' => 'Kedai Kota Satu', 'sort_order' => 1]);
        Kiosk::factory()->create(['cluster_id' => $pancing->id, 'name' => 'Sidorukun', 'sort_order' => 1]);

        $this->actingAs($operator);

        $names = Livewire::test(ActiveTrip::class)
            ->call('switchArea', null)
            ->viewData('kiosks')->pluck('name')->all();

        $this->assertEqualsCanonicalizing(['Kedai Kota Satu', 'Sidorukun'], $names);
    }

    /**
     * B3 — saat "Semua Area", area ASAL trip didahulukan di paling atas, baru area
     * lain. "Pancing" lebih dulu secara abjad, tapi area awal Kota 1 harus menang.
     */
    public function test_semua_area_puts_the_trips_own_area_first(): void
    {
        [$owner, $operator, $kota1, $pancing] = $this->scaffold();
        $this->tripDi($owner, $operator, $kota1);

        Kiosk::factory()->create(['cluster_id' => $pancing->id, 'name' => 'Kedai Pancing', 'sort_order' => 1]);
        Kiosk::factory()->create(['cluster_id' => $kota1->id, 'name' => 'Kedai Kota Satu', 'sort_order' => 1]);

        $this->actingAs($operator);

        $component = Livewire::test(ActiveTrip::class)->call('switchArea', null);

        $this->assertSame(
            ['Kedai Kota Satu', 'Kedai Pancing'],
            $component->viewData('kiosks')->pluck('name')->all(),
            'Area awal trip harus di atas, walau namanya kalah abjad.'
        );

        // Judul pemisah per area muncul, area awal ditandai.
        $groups = $component->viewData('kioskGroups');
        $this->assertCount(2, $groups);
        $this->assertSame('Kota 1 · area awal', $groups[0]['label']);
        $this->assertSame('Pancing', $groups[1]['label']);
        $this->assertTrue($component->viewData('showGroupLabels'));
        $component->assertSee('Kota 1 · area awal')->assertSee('Pancing');
    }

    /** Trip satu-area: judul pemisah DISEMBUNYIKAN (pengulangan header, buang tempat). */
    public function test_single_area_trip_hides_the_area_separator(): void
    {
        [$owner, $operator, $kota1] = $this->scaffold();
        $this->tripDi($owner, $operator, $kota1);
        Kiosk::factory()->create(['cluster_id' => $kota1->id, 'name' => 'Kedai Kota Satu', 'sort_order' => 1]);

        $this->actingAs($operator);

        $component = Livewire::test(ActiveTrip::class);
        $this->assertFalse($component->viewData('showGroupLabels'));
        $component->assertDontSee('Kota 1 · area awal');
    }

    /** B4 — ganti area MERESET batch, supaya operator tak tenggelam di area baru. */
    public function test_switching_area_resets_the_batch(): void
    {
        [$owner, $operator, $kota1, $pancing] = $this->scaffold();
        $this->tripDi($owner, $operator, $kota1);

        for ($i = 1; $i <= 60; $i++) {
            Kiosk::factory()->create(['cluster_id' => $kota1->id, 'name' => sprintf('Kota %02d', $i), 'sort_order' => $i]);
            Kiosk::factory()->create(['cluster_id' => $pancing->id, 'name' => sprintf('Pancing %02d', $i), 'sort_order' => $i]);
        }

        $this->actingAs($operator);

        $component = Livewire::test(ActiveTrip::class)->call('loadMoreKiosks');
        $this->assertSame(100, $component->get('kioskLimit'));
        $this->assertCount(60, $component->viewData('kiosks'));

        $component->call('switchArea', $pancing->id);

        $this->assertSame(50, $component->get('kioskLimit'), 'Batch harus balik ke batch pertama.');
        $this->assertCount(50, $component->viewData('kiosks'));
        $this->assertSame(60, $component->viewData('totalMatched'));

        // "Muat lebih banyak" tetap menjangkau ekor area baru.
        $component->call('loadMoreKiosks');
        $this->assertCount(60, $component->viewData('kiosks'));
        $component->assertSee('Pancing 60');
    }

    /**
     * 🔴 INTI PERMINTAAN OWNER: kunjungi kedai area LAIN → tercatat di trip yang SAMA,
     * komisi & stok benar. Angka konkret, bukan "kelihatannya jalan".
     */
    public function test_visiting_a_kiosk_in_another_area_records_on_the_same_trip_with_correct_komisi_and_stock(): void
    {
        [$owner, $operator, $kota1, $pancing] = $this->scaffold();
        $trip = $this->tripDi($owner, $operator, $kota1, bawa: 75);

        $this->variantFor($owner);

        $kedaiKota = Kiosk::factory()->create([
            'cluster_id' => $kota1->id, 'name' => 'Kedai Kota Satu', 'is_cash_only' => false,
        ]);
        $kedaiPancing = Kiosk::factory()->create([
            'cluster_id' => $pancing->id, 'name' => 'Kedai Pancing', 'is_cash_only' => false,
        ]);

        $this->actingAs($operator);

        $component = Livewire::test(ActiveTrip::class);

        // 1) Kedai area AWAL: titip 5 mika.
        $component->call('openVisitModal', $kedaiKota->id)
            ->call('chooseAction', 'mulai_titipan')
            ->set('dropBaru', 5)
            ->set('jatahMulai', 5)
            ->call('saveVisit')
            ->assertHasNoErrors();

        // 2) Pindah ke area LAIN, lalu kunjungi kedai di sana: titip 4 mika.
        $component->call('switchArea', $pancing->id)
            ->call('openVisitModal', $kedaiPancing->id)
            ->call('chooseAction', 'mulai_titipan')
            ->set('dropBaru', 4)
            ->set('jatahMulai', 4)
            ->call('saveVisit')
            ->assertHasNoErrors();

        $trip->refresh();

        // KEDUA kunjungan menempel di trip yang SAMA — tidak terpecah.
        $this->assertSame(1, Trip::count());
        $this->assertSame(2, $trip->visits()->count());
        $this->assertEqualsCanonicalizing(
            [$kedaiKota->id, $kedaiPancing->id],
            $trip->visits()->pluck('kiosk_id')->all()
        );

        // STOK: 5 + 4 = 9 mika diletakkan; sisa dari 75 = 66.
        $this->assertSame(9, $trip->getTotalDropReal(), 'Mika diletakkan harus 5 + 4 = 9.');
        $this->assertSame(9.0, (float) $trip->deliveries()->sum('qty_delivered'));
        $this->assertSame(66, $trip->qty_carried_total - $trip->getTotalDropReal());

        // KOMISI: basis DROP, Rp 1.000 x 9 mika = Rp 9.000 — termasuk drop lintas area.
        $this->assertSame(9000.0, (float) $trip->komisi_rian);

        // Kunjungan lintas area memang tersimpan dengan cluster yang benar.
        $clusterKunjungan = DB::table('kiosk_visits')
            ->join('kiosks', 'kiosk_visits.kiosk_id', '=', 'kiosks.id')
            ->where('kiosk_visits.trip_id', $trip->id)
            ->pluck('kiosks.cluster_id')->all();
        $this->assertEqualsCanonicalizing([$kota1->id, $pancing->id], $clusterKunjungan);
    }

    /** Akhiri trip setelah lintas area → komisi tersimpan sesuai (Rp 9.000). */
    public function test_end_trip_after_cross_area_stores_the_full_commission(): void
    {
        [$owner, $operator, $kota1, $pancing] = $this->scaffold();
        $trip = $this->tripDi($owner, $operator, $kota1, bawa: 75);
        $this->variantFor($owner);

        $a = Kiosk::factory()->create(['cluster_id' => $kota1->id, 'name' => 'Kedai A']);
        $b = Kiosk::factory()->create(['cluster_id' => $pancing->id, 'name' => 'Kedai B']);

        $this->actingAs($operator);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $a->id)->call('chooseAction', 'mulai_titipan')
            ->set('dropBaru', 5)->set('jatahMulai', 5)->call('saveVisit')
            ->call('switchArea', $pancing->id)
            ->call('openVisitModal', $b->id)->call('chooseAction', 'mulai_titipan')
            ->set('dropBaru', 4)->set('jatahMulai', 4)->call('saveVisit')
            ->set('endReason', 'stock_habis')
            ->call('confirmEndTrip');

        $trip->refresh();
        $this->assertNotNull($trip->ended_at);

        $this->assertSame(
            9000.0,
            (float) Commission::where('trip_id', $trip->id)->sum('commission_amount'),
            'Komisi lintas area = 1.000 x 9 mika, sama seperti kalau semua di satu area.'
        );
    }

    /** 🔒 Isolasi tenant: area owner lain ditolak, daftar tidak bocor. */
    public function test_operator_cannot_switch_to_another_owners_area(): void
    {
        [$owner, $operator, $kota1] = $this->scaffold();
        $this->tripDi($owner, $operator, $kota1);
        Kiosk::factory()->create(['cluster_id' => $kota1->id, 'name' => 'Kedai Kota Satu']);

        $ownerLain = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $areaLain = Cluster::create(['name' => 'Area Tetangga', 'is_active' => true, 'owner_id' => $ownerLain->id]);
        Kiosk::factory()->create(['cluster_id' => $areaLain->id, 'name' => 'Kedai Tetangga']);

        $this->actingAs($operator);

        $component = Livewire::test(ActiveTrip::class)->call('switchArea', $areaLain->id);

        $component->assertHasErrors('general');
        $this->assertSame($kota1->id, $component->get('viewClusterId'), 'Area tak boleh berpindah ke milik owner lain.');
        $this->assertSame(['Kedai Kota Satu'], $component->viewData('kiosks')->pluck('name')->all());
    }

    /** 🔒 "Semua Area" pun tak boleh membocorkan kedai owner lain. */
    public function test_semua_area_does_not_leak_other_owners_kiosks(): void
    {
        [$owner, $operator, $kota1, $pancing] = $this->scaffold();
        $this->tripDi($owner, $operator, $kota1);
        Kiosk::factory()->create(['cluster_id' => $kota1->id, 'name' => 'Kedai Kota Satu']);
        Kiosk::factory()->create(['cluster_id' => $pancing->id, 'name' => 'Kedai Pancing']);

        $ownerLain = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $areaLain = Cluster::create(['name' => 'Aaa Tetangga', 'is_active' => true, 'owner_id' => $ownerLain->id]);
        Kiosk::factory()->create(['cluster_id' => $areaLain->id, 'name' => 'Kedai Tetangga']);

        $this->actingAs($operator);

        $component = Livewire::test(ActiveTrip::class)->call('switchArea', null);

        $names = $component->viewData('kiosks')->pluck('name')->all();
        $this->assertEqualsCanonicalizing(['Kedai Kota Satu', 'Kedai Pancing'], $names);
        $this->assertSame(2, $component->viewData('totalMatched'));

        // Panel pemilih area juga tak boleh menyebut area owner lain.
        $areas = $component->call('openAreaPicker')->viewData('availableAreas')->pluck('name')->all();
        $this->assertSame(['Kota 1', 'Pancing'], $areas);
    }

    /**
     * B5a — 🔴 KIOS TANPA GPS TIDAK BOLEH HILANG saat "Urutkan Jarak".
     * Ini kritis: 88% kios belum ber-GPS. Dulu mereka diberi PHP_FLOAT_MAX lalu
     * tenggelam ke ekor dan terpotong batas batch — senyap.
     */
    public function test_kiosks_without_gps_stay_visible_in_distance_mode(): void
    {
        [$owner, $operator, $kota1] = $this->scaffold();
        $this->tripDi($owner, $operator, $kota1);

        // 1 kios ber-GPS dekat operator …
        Kiosk::factory()->create([
            'cluster_id' => $kota1->id, 'name' => 'Kedai Ber-GPS',
            'latitude' => 3.5960, 'longitude' => 98.6720, 'sort_order' => 99,
        ]);
        // … dan 60 kios TANPA koordinat (mewakili 88% kios nyata).
        for ($i = 1; $i <= 60; $i++) {
            Kiosk::factory()->create([
                'cluster_id' => $kota1->id, 'name' => sprintf('Tanpa GPS %02d', $i),
                'latitude' => null, 'longitude' => null, 'sort_order' => $i,
            ]);
        }

        $this->actingAs($operator);

        $component = Livewire::test(ActiveTrip::class)
            ->call('sortByDistance', 3.5952, 98.6722);

        $names = $component->viewData('kiosks')->pluck('name')->all();

        $this->assertSame('Kedai Ber-GPS', $names[0], 'Kios terdekat tetap di puncak.');
        $this->assertContains('Tanpa GPS 01', $names, 'Kios tanpa GPS WAJIB tetap terlihat.');
        $this->assertSame(61, $component->viewData('totalMatched'));

        // Grup terpisah dengan judul yang menyebutkan alasannya.
        $groups = $component->viewData('kioskGroups');
        $this->assertSame('geo', $groups[0]['key']);
        $this->assertSame('nogeo', $groups[1]['key']);
        $component->assertSee('Terdekat dari lokasimu')
            ->assertSee('Tanpa lokasi GPS');

        // Mode jarak membagi batch dua grup (masing-masing setengah DISPLAY_LIMIT)
        // supaya jumlah kartu di layar tetap ±50 seperti mode rute.
        $this->assertCount(25, $groups[1]['kiosks']);

        // Ekor tetap terjangkau lewat "Muat lebih banyak", bukan hilang.
        $component->call('loadMoreKiosks')->call('loadMoreKiosks');
        $component->assertSee('Tanpa GPS 60');
    }

    /**
     * B5b — PERFORMA: mode jarak tak lagi menarik SELURUH kios owner untuk dihitung
     * haversine di PHP. Bounding box SQL (index idx_kiosks_geo) memotong duluan.
     *
     * Yang diukur di sini bukan jumlah query (sengaja jadi 2 cabang: dekat + sisa),
     * melainkan BERAPA BARIS yang benar-benar ditarik untuk dihitung jaraknya —
     * itulah biaya yang dulu meledak (957 baris + 957 trigonometri tiap render).
     */
    public function test_distance_mode_only_loads_geocoded_kiosks_near_the_operator(): void
    {
        [$owner, $operator, $kota1] = $this->scaffold();
        $this->tripDi($owner, $operator, $kota1);

        // 2 kios ber-GPS DEKAT (Medan), 1 ber-GPS JAUH (Jakarta, > 25 km).
        Kiosk::factory()->create(['cluster_id' => $kota1->id, 'name' => 'Dekat A', 'latitude' => 3.5960, 'longitude' => 98.6720]);
        Kiosk::factory()->create(['cluster_id' => $kota1->id, 'name' => 'Dekat B', 'latitude' => 3.6000, 'longitude' => 98.6800]);
        Kiosk::factory()->create([
            'cluster_id' => $kota1->id, 'name' => 'Jauh Jakarta',
            'latitude' => -6.2000, 'longitude' => 106.8166, 'sort_order' => 1,
        ]);
        for ($i = 1; $i <= 40; $i++) {
            Kiosk::factory()->create([
                'cluster_id' => $kota1->id, 'name' => sprintf('Tanpa GPS %02d', $i),
                'latitude' => null, 'longitude' => null, 'sort_order' => $i + 1,
            ]);
        }

        $this->actingAs($operator);

        $component = Livewire::test(ActiveTrip::class);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $component->call('sortByDistance', 3.5952, 98.6722);
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        // Ada query bounding box yang memakai kedua kolom geo (bukan SELECT tanpa batas).
        $bbox = collect($log)->filter(fn ($q) => str_contains($q['query'], '`latitude` between')
            && str_contains($q['query'], '`longitude` between'));
        $this->assertGreaterThan(0, $bbox->count(), 'Prefilter bounding box harus dipakai sebelum haversine.');

        // Hasilnya: hanya kios DEKAT yang masuk grup jarak. Kios jauh & tanpa GPS
        // pindah ke grup bawah — terlihat, tapi tak ikut dihitung haversine.
        $groups = $component->viewData('kioskGroups');
        $this->assertSame(['Dekat A', 'Dekat B'], $groups[0]['kiosks']->pluck('name')->all());
        $this->assertContains('Jauh Jakarta', $groups[1]['kiosks']->pluck('name')->all());
        $this->assertSame(43, $component->viewData('totalMatched'));
    }

    /** Kios sentinel walk-in bukan kedai — jangan muncul sebagai kartu di "Semua Area". */
    public function test_walk_in_sentinel_kiosk_never_appears_in_the_list(): void
    {
        [$owner, $operator, $kota1] = $this->scaffold();
        $this->tripDi($owner, $operator, null); // Trip Bebas = paling rawan
        Kiosk::factory()->create(['cluster_id' => $kota1->id, 'name' => 'Kedai Kota Satu']);
        Kiosk::walkInSentinelFor($owner->id);

        $this->actingAs($operator);

        $component = Livewire::test(ActiveTrip::class);

        $this->assertSame(['Kedai Kota Satu'], $component->viewData('kiosks')->pluck('name')->all());
        $this->assertSame(1, $component->viewData('totalMatched'));
        $component->assertDontSee(Kiosk::WALKIN_SENTINEL_NAME);
    }
}
