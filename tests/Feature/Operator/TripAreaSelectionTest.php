<?php

namespace Tests\Feature\Operator;

use App\Livewire\Operator\ActiveTrip;
use App\Livewire\Operator\StartTrip;
use App\Models\Cluster;
use App\Models\Kiosk;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * BUG LAPANGAN (28 Juli 2026, dilaporkan owner dari produksi):
 *
 *   1. Operator pilih area "Kota 1" saat mulai trip → yang terbuka daftar kedai
 *      area "PANCING". Diulang berkali-kali, tetap sama.
 *   2. Daftar kedai Pancing berhenti PERSIS di kedai ber-sort_order 50 ("Bilal 3"),
 *      padahal area itu masih punya 51..54.
 *
 * DUA AKAR TERPISAH (bukan satu):
 *   (A) Trip basi yang belum di-end dari HARI SEBELUMNYA membajak setiap trip baru.
 *       StartTrip::startTrip() "Proteksi 1" mencari trip aktif TANPA filter tanggal
 *       → ketemu trip Pancing kemarin → redirect ke situ, trip "Kota 1" TIDAK PERNAH
 *       dibuat. ActiveTrip::mount() juga mengambil ->first() tanpa filter tanggal
 *       (id terkecil = trip basi) sehingga tetap mendarat di trip lama.
 *   (B) Daftar kedai dipotong keras di DISPLAY_LIMIT = 50 tanpa jalan keluar selain
 *       ketik pencarian → kedai ke-51+ dalam satu area tak pernah terlihat.
 *
 * Test di file ini MEREPRODUKSI kedua gejala itu.
 */
class TripAreaSelectionTest extends TestCase
{
    use RefreshDatabase;

    /** Owner + operator + dua area, siap pakai. */
    private function scaffold(): array
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $operator = User::factory()->create([
            'role' => 'operator', 'is_active' => true, 'owner_id' => $owner->id,
        ]);

        $kota1 = Cluster::create(['name' => 'Kota 1', 'is_active' => true, 'owner_id' => $owner->id]);
        $pancing = Cluster::create(['name' => 'Pancing', 'is_active' => true, 'owner_id' => $owner->id]);

        return [$owner, $operator, $kota1, $pancing];
    }

    /**
     * GEJALA 1 — akar (A). Trip kemarin yang belum di-end tidak boleh membajak
     * pilihan area hari ini.
     */
    public function test_stale_unended_trip_from_yesterday_does_not_hijack_todays_area_choice(): void
    {
        [$owner, $operator, $kota1, $pancing] = $this->scaffold();

        Kiosk::factory()->create(['cluster_id' => $kota1->id, 'name' => 'Kedai Kota Satu', 'sort_order' => 1]);
        Kiosk::factory()->create(['cluster_id' => $pancing->id, 'name' => 'Bilal 3', 'sort_order' => 50]);

        // Trip PANCING kemarin, operator lupa "Akhiri Trip" → ended_at masih null.
        $stale = Trip::factory()->create([
            'owner_id' => $owner->id,
            'operator_id' => $operator->id,
            'trip_date' => today()->subDay(),
            'trip_number_of_day' => 1,
            'starting_cluster_id' => $pancing->id,
            'started_at' => now()->subDay(),
            'ended_at' => null,
        ]);

        $this->actingAs($operator);

        // Layar "Mulai Trip" TETAP tampil (guard mount() sudah per-hari) — owner memang
        // sampai ke form, memilih Kota 1, mengisi 75 mika.
        Livewire::test(StartTrip::class)
            ->set('selectedClusterId', $kota1->id)
            ->set('qtyCarried', 75)
            ->call('startTrip');

        $baru = Trip::where('operator_id', $operator->id)
            ->whereDate('trip_date', today())
            ->first();

        // Sebelum fix: null — Proteksi 1 nyangkut di trip basi, trip baru tak pernah dibuat.
        $this->assertNotNull($baru, 'Trip hari ini harus dibuat, bukan redirect ke trip basi kemarin.');
        $this->assertSame($kota1->id, $baru->starting_cluster_id, 'Area trip baru harus Kota 1, bukan Pancing.');
        $this->assertSame(75, $baru->qty_carried_total);
        $this->assertNotSame($stale->id, $baru->id);

        // Dan layar trip aktif harus mendarat di trip HARI INI (Kota 1), bukan trip basi.
        Livewire::test(ActiveTrip::class)
            ->assertSet('trip.id', $baru->id)
            ->assertSee('Kedai Kota Satu')
            ->assertDontSee('Bilal 3');
    }

    /**
     * GEJALA 1 (bentuk murni) — daftar kedai dalam trip hanya berisi area yang dipilih.
     */
    public function test_kiosk_list_only_contains_kiosks_of_the_selected_cluster(): void
    {
        [$owner, $operator, $kota1, $pancing] = $this->scaffold();

        Kiosk::factory()->create(['cluster_id' => $kota1->id, 'name' => 'Kedai Kota Satu', 'sort_order' => 1]);
        Kiosk::factory()->create(['cluster_id' => $pancing->id, 'name' => 'Sidorukun', 'sort_order' => 54]);

        $trip = Trip::factory()->create([
            'owner_id' => $owner->id,
            'operator_id' => $operator->id,
            'trip_date' => today(),
            'starting_cluster_id' => $kota1->id,
            'started_at' => now(),
            'ended_at' => null,
        ]);

        $this->actingAs($operator);

        $names = Livewire::test(ActiveTrip::class)
            ->viewData('kiosks')->pluck('name')->all();

        $this->assertSame(['Kedai Kota Satu'], $names);
        $this->assertSame($trip->id, Trip::first()->id);
    }

    /**
     * GEJALA 2 — akar (B). Area dengan 60 kedai: kedai ke-51..60 harus TERJANGKAU,
     * bukan diam-diam terpotong di 50.
     */
    public function test_all_kiosks_in_a_cluster_are_reachable_beyond_the_first_fifty(): void
    {
        [$owner, $operator, $kota1] = $this->scaffold();

        // sort_order 1..60 — meniru Pancing (49 Umsu, 50 Bilal 3, 51 Bilal 2, … 54 Sidorukun).
        for ($i = 1; $i <= 60; $i++) {
            Kiosk::factory()->create([
                'cluster_id' => $kota1->id,
                'name' => sprintf('Kedai %02d', $i),
                'sort_order' => $i,
            ]);
        }

        Trip::factory()->create([
            'owner_id' => $owner->id,
            'operator_id' => $operator->id,
            'trip_date' => today(),
            'starting_cluster_id' => $kota1->id,
            'started_at' => now(),
            'ended_at' => null,
        ]);

        $this->actingAs($operator);

        $component = Livewire::test(ActiveTrip::class);

        // Batch pertama tetap 50 kartu (DOM ringan di HP), tapi totalnya jujur dilaporkan.
        $this->assertCount(50, $component->viewData('kiosks'));
        $this->assertSame(60, $component->viewData('totalMatched'));
        $component->assertSee('Kedai 50')->assertDontSee('Kedai 60');

        // "Muat lebih banyak" → SEMUA kedai area itu terjangkau tanpa harus mengetik nama.
        $component->call('loadMoreKiosks');

        $this->assertCount(60, $component->viewData('kiosks'));
        $component->assertSee('Kedai 51')->assertSee('Kedai 60');

        // Urutan tetap sort_order DALAM area (bukan abjad, bukan acak).
        $this->assertSame(
            range(1, 60),
            $component->viewData('kiosks')->pluck('sort_order')->all()
        );
    }

    /** Pencarian mereset batch — hasil cari tidak boleh ikut membengkak seenaknya. */
    public function test_search_resets_the_load_more_batch(): void
    {
        [$owner, $operator, $kota1] = $this->scaffold();

        for ($i = 1; $i <= 60; $i++) {
            Kiosk::factory()->create([
                'cluster_id' => $kota1->id, 'name' => sprintf('Kedai %02d', $i), 'sort_order' => $i,
            ]);
        }

        Trip::factory()->create([
            'owner_id' => $owner->id, 'operator_id' => $operator->id,
            'trip_date' => today(), 'starting_cluster_id' => $kota1->id,
            'started_at' => now(), 'ended_at' => null,
        ]);

        $this->actingAs($operator);

        $component = Livewire::test(ActiveTrip::class)->call('loadMoreKiosks');
        $this->assertCount(60, $component->viewData('kiosks'));

        $component->set('search', 'Kedai 5');
        $this->assertSame(50, $component->get('kioskLimit'));
    }

    /** REGRESI — Trip Bebas tetap lintas area, dikelompokkan per area. */
    public function test_trip_bebas_still_shows_kiosks_across_all_clusters(): void
    {
        [$owner, $operator, $kota1, $pancing] = $this->scaffold();

        Kiosk::factory()->create(['cluster_id' => $kota1->id, 'name' => 'Kedai Kota Satu', 'sort_order' => 1]);
        Kiosk::factory()->create(['cluster_id' => $pancing->id, 'name' => 'Sidorukun', 'sort_order' => 54]);

        Trip::factory()->create([
            'owner_id' => $owner->id,
            'operator_id' => $operator->id,
            'trip_date' => today(),
            'starting_cluster_id' => null, // Trip Bebas
            'started_at' => now(),
            'ended_at' => null,
        ]);

        $this->actingAs($operator);

        $names = Livewire::test(ActiveTrip::class)
            ->viewData('kiosks')->pluck('name')->all();

        // Lintas area, dikelompokkan per nama area (Kota 1 sebelum Pancing).
        $this->assertSame(['Kedai Kota Satu', 'Sidorukun'], $names);
    }

    /** REGRESI — isolasi tenant: operator owner lain tidak melihat kedai owner ini. */
    public function test_tenant_isolation_holds_for_load_more(): void
    {
        [$owner, $operator, $kota1] = $this->scaffold();

        for ($i = 1; $i <= 60; $i++) {
            Kiosk::factory()->create([
                'cluster_id' => $kota1->id, 'name' => sprintf('Kedai %02d', $i), 'sort_order' => $i,
            ]);
        }

        $otherOwner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $otherOperator = User::factory()->create([
            'role' => 'operator', 'is_active' => true, 'owner_id' => $otherOwner->id,
        ]);
        $otherCluster = Cluster::create(['name' => 'Aaa Area Tetangga', 'is_active' => true, 'owner_id' => $otherOwner->id]);
        Kiosk::factory()->create(['cluster_id' => $otherCluster->id, 'name' => 'Kedai Tetangga', 'sort_order' => 1]);

        // Trip bebas milik operator tetangga → paling rawan bocor (tanpa filter cluster).
        Trip::factory()->create([
            'owner_id' => $otherOwner->id, 'operator_id' => $otherOperator->id,
            'trip_date' => today(), 'starting_cluster_id' => null,
            'started_at' => now(), 'ended_at' => null,
        ]);

        $this->actingAs($otherOperator);

        $component = Livewire::test(ActiveTrip::class)->call('loadMoreKiosks');

        $this->assertSame(['Kedai Tetangga'], $component->viewData('kiosks')->pluck('name')->all());
        $this->assertSame(1, $component->viewData('totalMatched'));
    }
}
