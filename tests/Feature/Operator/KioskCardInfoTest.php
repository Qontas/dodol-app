<?php

namespace Tests\Feature\Operator;

use App\Livewire\Operator\ActiveTrip;
use App\Models\Cluster;
use App\Models\Delivery;
use App\Models\Kiosk;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Settlement;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * BAGIAN D — KARTU KEDAI: AREA + TITIPAN.
 *
 * Keluhan owner: kartu kedai tidak menampilkan nama AREA (jadi di Trip Bebas /
 * lintas area dia tak tahu kedai ini dari mana) dan tidak menampilkan BERAPA mika
 * titipannya — cuma badge "Ada Titipan" yang tak memberi tahu apa-apa.
 *
 * ⚠️ Daftar bisa RATUSAN kios, jadi semua tambahan ini WAJIB lewat subquery
 * berkorelasi / eager load, bukan per baris. Ada test query-count di bawah.
 */
class KioskCardInfoTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $operator;
    private Cluster $kota1;
    private Cluster $pancing;
    private ProductVariant $variant;
    private ?Trip $tripAktif = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
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

    private function trip(?Cluster $start): Trip
    {
        return $this->tripAktif = Trip::factory()->create([
            'owner_id' => $this->owner->id, 'operator_id' => $this->operator->id,
            'trip_date' => today(), 'trip_number_of_day' => 1,
            'starting_cluster_id' => $start?->id,
            'qty_carried_total' => 75, 'started_at' => now(), 'ended_at' => null,
        ]);
    }

    private function titipan(Kiosk $kiosk, int $mika, bool $lunas = false, ?Trip $trip = null): Delivery
    {
        $delivery = Delivery::factory()->create([
            'kiosk_id' => $kiosk->id,
            'trip_id' => ($trip ?? $this->tripAktif)->id,
            'product_variant_id' => $this->variant->id,
            'delivery_type' => 'consignment',
            'qty_delivered' => $mika,
        ]);

        if ($lunas) {
            Settlement::create([
                'delivery_id' => $delivery->id, 'visit_date' => today(),
                'qty_sold' => $mika * 15, 'qty_returned_fresh' => 0, 'qty_returned_expired' => 0,
                'amount_due' => $mika * 15 * 800, 'amount_paid' => $mika * 15 * 800,
            ]);
        }

        return $delivery;
    }

    /** D3 — TIGA kondisi titipan, satu badge, angka bukan sekadar "ada". */
    public function test_card_shows_the_three_titipan_conditions(): void
    {
        $this->trip($this->kota1);

        $konsinyasi = Kiosk::factory()->create([
            'cluster_id' => $this->kota1->id, 'name' => 'Kedai Konsinyasi',
            'is_cash_only' => false, 'default_qty_mika' => 4, 'sort_order' => 1,
        ]);
        $this->titipan($konsinyasi, 4);

        Kiosk::factory()->create([
            'cluster_id' => $this->kota1->id, 'name' => 'Kedai Cash',
            'is_cash_only' => true, 'default_qty_mika' => null, 'sort_order' => 2,
        ]);

        Kiosk::factory()->create([
            'cluster_id' => $this->kota1->id, 'name' => 'Kedai Booking',
            'is_cash_only' => false, 'default_qty_mika' => null, 'sort_order' => 3,
        ]);

        $this->actingAs($this->operator);

        $component = Livewire::test(ActiveTrip::class);

        $component->assertSee('4 mika')                    // konsinyasi bertitipan
            ->assertSee('Cash Only')                       // cash-only
            ->assertSee('Belum pernah dititip')            // booking (badge yang SUDAH ADA)
            ->assertDontSee('Ada Titipan');                // badge lama digantikan angkanya

        // Angka datang dari subquery, bukan dihitung ulang di blade.
        $kartu = $component->viewData('kiosks')->keyBy('name');
        $this->assertSame(4, (int) $kartu['Kedai Konsinyasi']->pending_titipan_mika);
        $this->assertSame(1, (int) $kartu['Kedai Konsinyasi']->pending_titipan_count);
        $this->assertSame(0, (int) $kartu['Kedai Cash']->pending_titipan_count);
        $this->assertSame(0, (int) $kartu['Kedai Booking']->pending_titipan_count);
    }

    /** Titipan yang SUDAH LUNAS tak boleh ikut dihitung sebagai titipan berjalan. */
    public function test_settled_titipan_is_not_counted(): void
    {
        $this->trip($this->kota1);

        $kiosk = Kiosk::factory()->create([
            'cluster_id' => $this->kota1->id, 'name' => 'Kedai Lunas',
            'is_cash_only' => false, 'default_qty_mika' => 4,
        ]);
        $this->titipan($kiosk, 6, lunas: true); // sudah di-settle
        $this->titipan($kiosk, 3);              // masih berjalan

        $this->actingAs($this->operator);

        $kartu = Livewire::test(ActiveTrip::class)->viewData('kiosks')->firstWhere('name', 'Kedai Lunas');

        $this->assertSame(3, (int) $kartu->pending_titipan_mika, 'Hanya titipan BERJALAN yang dihitung.');
    }

    /** Badge booking hilang begitu kedai punya titipan (tak boleh dobel). */
    public function test_booking_badge_and_titipan_never_show_together(): void
    {
        $this->trip($this->kota1);

        $kiosk = Kiosk::factory()->create([
            'cluster_id' => $this->kota1->id, 'name' => 'Kedai Berubah',
            'is_cash_only' => false, 'default_qty_mika' => null,
        ]);

        $this->actingAs($this->operator);
        Livewire::test(ActiveTrip::class)->assertSee('Belum pernah dititip');

        $kiosk->update(['default_qty_mika' => 5]);
        $this->titipan($kiosk, 5);

        Livewire::test(ActiveTrip::class)
            ->assertSee('5 mika')
            ->assertDontSee('Belum pernah dititip');
    }

    /** D1 — label area muncul di kartu saat daftar menjangkau lebih dari satu area. */
    public function test_area_label_appears_on_cards_when_the_list_spans_areas(): void
    {
        $this->trip($this->kota1);
        Kiosk::factory()->create(['cluster_id' => $this->kota1->id, 'name' => 'Kedai Kota', 'owner_name' => 'Bu Ani']);
        Kiosk::factory()->create(['cluster_id' => $this->pancing->id, 'name' => 'Kedai Pancing', 'owner_name' => 'Pak Budi']);

        $this->actingAs($this->operator);

        $component = Livewire::test(ActiveTrip::class)->call('switchArea', null);

        $this->assertTrue($component->viewData('showAreaOnCard'));
        $component->assertSee('Bu Ani')->assertSee('Kota 1')
            ->assertSee('Pak Budi')->assertSee('Pancing');
    }

    /**
     * D1 (rekomendasi yang dijalankan) — di trip SATU AREA label itu DISEMBUNYIKAN:
     * ia cuma mengulang header "Area awal: Kota 1" dan memakan tempat di layar HP.
     */
    public function test_area_label_hidden_in_a_single_area_trip(): void
    {
        $this->trip($this->kota1);
        Kiosk::factory()->create(['cluster_id' => $this->kota1->id, 'name' => 'Kedai Kota']);

        $this->actingAs($this->operator);

        $this->assertFalse(Livewire::test(ActiveTrip::class)->viewData('showAreaOnCard'));
    }

    /** D2 — judul pemisah per area saat multi-area, dengan jumlah kios per area. */
    public function test_area_separators_show_counts(): void
    {
        $this->trip($this->kota1);

        foreach (range(1, 3) as $i) {
            Kiosk::factory()->create(['cluster_id' => $this->kota1->id, 'name' => "Kota {$i}", 'sort_order' => $i]);
        }
        foreach (range(1, 12) as $i) {
            Kiosk::factory()->create(['cluster_id' => $this->pancing->id, 'name' => "Pancing {$i}", 'sort_order' => $i]);
        }

        $this->actingAs($this->operator);

        $component = Livewire::test(ActiveTrip::class)->call('switchArea', null);
        $groups = $component->viewData('kioskGroups');

        $this->assertSame('Kota 1 · area awal', $groups[0]['label']);
        $this->assertSame('3 kios', $groups[0]['note']);
        $this->assertSame('Pancing', $groups[1]['label']);
        $this->assertSame('12 kios', $groups[1]['note']);

        $component->assertSee('Pancing')->assertSee('12 kios');
    }

    /**
     * 🔴 ANTI N+1 — jumlah query TIDAK boleh naik per BARIS. Daftar kios bisa
     * ratusan; area + titipan dipasang sebagai subquery berkorelasi / eager load,
     * bukan satu query per kartu.
     */
    public function test_kiosk_list_query_count_does_not_grow_per_row(): void
    {
        $this->trip($this->kota1);
        $this->actingAs($this->operator);

        $ukur = function (): int {
            DB::enableQueryLog();
            DB::flushQueryLog();
            Livewire::test(ActiveTrip::class)->viewData('kiosks');
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        // 3 kios, masing-masing punya titipan berjalan.
        foreach (range(1, 3) as $i) {
            $k = Kiosk::factory()->create([
                'cluster_id' => $this->kota1->id, 'name' => "Kedai {$i}",
                'sort_order' => $i, 'default_qty_mika' => 4,
            ]);
            $this->titipan($k, 4);
        }
        $sedikit = $ukur();

        // 40 kios lagi (total 43), semuanya bertitipan → kasus TERBURUK untuk N+1.
        foreach (range(4, 43) as $i) {
            $k = Kiosk::factory()->create([
                'cluster_id' => $this->kota1->id, 'name' => "Kedai {$i}",
                'sort_order' => $i, 'default_qty_mika' => 4,
            ]);
            $this->titipan($k, 4);
        }
        $banyak = $ukur();

        $this->assertSame(
            $sedikit,
            $banyak,
            "Query harus KONSTAN. 3 kios={$sedikit}, 43 kios={$banyak}. "
                .'Selisih = agregat kartu jatuh ke per-baris (N+1).'
        );

        // Dan angkanya memang benar untuk semua baris, bukan cuma tak-N+1.
        $kartu = Livewire::test(ActiveTrip::class)->viewData('kiosks');
        $this->assertCount(43, $kartu);
        $this->assertTrue($kartu->every(fn ($k) => (int) $k->pending_titipan_mika === 4));
    }

    /** 🔒 Titipan kios owner lain tak boleh terhitung / tampil. */
    public function test_other_owners_titipan_never_leaks(): void
    {
        $this->trip($this->kota1);
        Kiosk::factory()->create(['cluster_id' => $this->kota1->id, 'name' => 'Kedai Kota']);

        $ownerLain = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $areaLain = Cluster::create(['name' => 'Tetangga', 'is_active' => true, 'owner_id' => $ownerLain->id]);
        $kiosLain = Kiosk::factory()->create(['cluster_id' => $areaLain->id, 'name' => 'Kedai Tetangga']);
        $tripLain = Trip::factory()->create([
            'owner_id' => $ownerLain->id, 'operator_id' => $ownerLain->id,
            'trip_date' => today(), 'trip_number_of_day' => 1,
            'starting_cluster_id' => $areaLain->id,
            'started_at' => now(), 'ended_at' => now(),
        ]);
        $this->titipan($kiosLain, 99, trip: $tripLain);

        $this->actingAs($this->operator);

        Livewire::test(ActiveTrip::class)
            ->call('switchArea', null)
            ->assertDontSee('Kedai Tetangga')
            ->assertDontSee('99 mika');
    }
}
