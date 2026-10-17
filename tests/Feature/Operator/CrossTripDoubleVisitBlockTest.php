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
 * 🔴 KUNJUNGAN DOBEL LINTAS OPERATOR (18 Okt 2026).
 *
 * Skenario nyata yang memicu ini: Adri jalan trip pagi, titip 5 mika di Kedai X.
 * Siang itu juga Madan (operator lain, trip TERPISAH) kebetulan lewat Kedai X dan
 * membuka modalnya — titipan Adri yang ditaruh beberapa jam lalu BELUM TENTU laku,
 * tapi kalau Madan boleh langsung "Tagih + Titip Ulang" atasnya, kedai itu kena
 * tagihan fiktif DAN bisa dititip dobel (stok + komisi dobel, basis komisi = DROP).
 *
 * Aturan: titipan yang BARU ditaruh HARI INI oleh TRIP LAIN (operator lain, atau
 * operator yang sama di trip kedua hari itu) TIDAK bisa ditagih (tagih_titip /
 * ganti_cash) oleh trip yang sedang berjalan sekarang. Titip Cash & Lewati tetap
 * selalu boleh. Titipan dari HARI SEBELUMNYA tidak terpengaruh.
 */
class CrossTripDoubleVisitBlockTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Cluster $cluster;
    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $this->cluster = Cluster::create(['name' => 'Kota 1', 'is_active' => true, 'owner_id' => $this->owner->id]);

        $product = \App\Models\Product::factory()->create(['owner_id' => $this->owner->id]);
        $this->variant = ProductVariant::factory()->create([
            'product_id' => $product->id, 'is_active' => true, 'sale_price_per_pack' => 12000,
        ]);
    }

    private function operator(string $name): User
    {
        return User::factory()->create([
            'role' => 'operator', 'is_active' => true, 'owner_id' => $this->owner->id, 'name' => $name,
        ]);
    }

    /**
     * $numberOfDay harus unik per (owner, trip_date) — index idx_trip_owner_date_number
     * dihitung per OWNER, bukan per operator (dua operator owner yang sama di hari yang
     * sama = trip #1 dan #2 milik owner itu, bukan masing-masing #1).
     */
    private function trip(User $operator, int $numberOfDay = 1, ?\Carbon\Carbon $endedAt = null): Trip
    {
        return Trip::factory()->create([
            'owner_id' => $this->owner->id,
            'operator_id' => $operator->id,
            'trip_date' => today(),
            'trip_number_of_day' => $numberOfDay,
            'starting_cluster_id' => $this->cluster->id,
            'qty_carried_total' => 75,
            'started_at' => now(),
            'ended_at' => $endedAt,
        ]);
    }

    /** Titipan konsinyasi berjalan di sebuah kiosk, dibuat oleh $trip, created_at bisa dibekukan. */
    /**
     * Titipan konsinyasi berjalan — Delivery + KioskVisit berpasangan (sama seperti
     * jalur nyata persistVisitFromState()). KioskVisit WAJIB ada agar lastOperatorFor()
     * (badge "Sudah diantar ... hari ini") bisa menemukannya — ia baca kiosk_visits,
     * bukan deliveries langsung.
     */
    private function titipan(Kiosk $kiosk, Trip $trip, int $mika = 5, ?\Carbon\Carbon $createdAt = null): Delivery
    {
        $delivery = Delivery::factory()->create([
            'kiosk_id' => $kiosk->id,
            'trip_id' => $trip->id,
            'product_variant_id' => $this->variant->id,
            'delivery_type' => 'consignment',
            'qty_delivered' => $mika,
        ]);

        $visit = KioskVisit::create([
            'trip_id' => $trip->id,
            'kiosk_id' => $kiosk->id,
            'visited_at' => $createdAt ?? now(),
            'visit_action' => 'drop_only',
            'new_delivery_id' => $delivery->id,
            'extension_granted' => false,
        ]);
        $delivery->update(['kiosk_visit_id' => $visit->id]);

        if ($createdAt !== null) {
            $delivery->created_at = $createdAt;
            $delivery->save();
        }

        return $delivery->fresh();
    }

    // === REPRODUKSI SKENARIO ADRI/MADAN (operator berbeda) ===

    public function test_different_operator_cannot_tagih_titip_pending_delivery_from_other_trip_today(): void
    {
        $adri = $this->operator('Adri');
        $madan = $this->operator('Madan');

        $tripAdri = $this->trip($adri, 1);
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id, 'name' => 'Kedai X', 'default_qty_mika' => 5]);
        $this->titipan($kiosk, $tripAdri, 5);

        $tripMadan = $this->trip($madan, 2);

        $this->actingAs($madan);
        $component = Livewire::test(ActiveTrip::class)->call('openVisitModal', $kiosk->id);

        // Layer UI: chooseAction() mencoret tagih_titip/ganti_cash dari pilihan valid —
        // memanggilnya tidak membuat chosenAction berubah dari null.
        $component->call('chooseAction', 'tagih_titip');
        $this->assertNull($component->get('chosenAction'), 'tagih_titip harus dicoret dari pilihan saat diblokir.');

        $component->call('chooseAction', 'ganti_cash');
        $this->assertNull($component->get('chosenAction'), 'ganti_cash harus dicoret dari pilihan saat diblokir.');

        // Layer SERVER (anti-tamper): walau chosenAction dipaksa lewat request Livewire
        // yang diubah (bypass UI), persistVisitFromState() tetap menolak.
        $component->set('chosenAction', 'tagih_titip')
            ->set('dropBaru', 5)
            ->call('saveVisit')
            ->assertHasErrors('general');

        $pesan = $component->errors()->first('general');
        $this->assertStringContainsString('Adri', $pesan);
        $this->assertStringContainsString('belum bisa ditagih', $pesan);

        // Tidak ada settlement/tagihan yang tercipta dari percobaan yang diblokir.
        $this->assertSame(0, Settlement::count());
        $this->assertSame(1, Delivery::count(), 'Tidak boleh ada titipan baru dobel dari percobaan yang diblokir.');
    }

    public function test_different_operator_cannot_ganti_cash_pending_delivery_from_other_trip_today(): void
    {
        $adri = $this->operator('Adri');
        $madan = $this->operator('Madan');

        $tripAdri = $this->trip($adri, 1);
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id, 'name' => 'Kedai X', 'default_qty_mika' => 5]);
        $this->titipan($kiosk, $tripAdri, 5);

        $this->trip($madan, 2);

        $this->actingAs($madan);
        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->set('chosenAction', 'ganti_cash')
            ->call('saveVisit')
            ->assertHasErrors('general');

        $this->assertSame(0, Settlement::count());
    }

    /** Titip Cash & Lewati TETAP boleh walau diblokir dari menagih. */
    public function test_titip_cash_and_lewati_still_allowed_when_tagih_is_blocked(): void
    {
        $adri = $this->operator('Adri');
        $madan = $this->operator('Madan');

        $tripAdri = $this->trip($adri, 1);
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id, 'name' => 'Kedai X', 'default_qty_mika' => 5]);
        $this->titipan($kiosk, $tripAdri, 5);

        $this->trip($madan, 2);

        $this->actingAs($madan);

        // Lewati (cek) — tidak ada transaksi, cuma catat sisa.
        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->call('chooseAction', 'cek')
            ->call('saveVisit')
            ->assertHasNoErrors('general');

        // Titip Cash — naruh ekstra dibayar tunai, tak menagih $pendingDelivery.
        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->call('chooseAction', 'titip_cash')
            ->set('dropBaru', 2)
            ->set('uangDiterima', 2 * 800 * 15)
            ->call('saveVisit')
            ->assertHasNoErrors('general');
    }

    // === OPERATOR SAMA, TRIP KEDUA HARI ITU ===

    public function test_same_operator_second_trip_same_day_also_blocks_tagih_titip(): void
    {
        $adri = $this->operator('Adri');

        $tripPagi = $this->trip($adri, numberOfDay: 1, endedAt: now());
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id, 'name' => 'Kedai Y', 'default_qty_mika' => 5]);
        $this->titipan($kiosk, $tripPagi, 5);

        // Trip pagi sudah ditutup; trip siang (kedua hari itu) jadi trip aktif Adri.
        $tripSiang = $this->trip($adri, numberOfDay: 2);

        $this->actingAs($adri);
        $component = Livewire::test(ActiveTrip::class);
        $this->assertSame($tripSiang->id, $component->get('trip')->id, 'mount() harus memilih trip kedua (aktif), bukan trip pagi (sudah ended).');

        $component->call('openVisitModal', $kiosk->id)
            ->set('chosenAction', 'tagih_titip')
            ->set('dropBaru', 5)
            ->call('saveVisit')
            ->assertHasErrors('general');

        $this->assertSame(0, Settlement::count());
    }

    // === HARI SEBELUMNYA TIDAK TERPENGARUH ===

    public function test_previous_day_pending_delivery_is_not_blocked(): void
    {
        $adri = $this->operator('Adri');
        $madan = $this->operator('Madan');

        // Trip Adri KEMARIN, titipan dibekukan created_at-nya ke kemarin juga.
        $tripKemarin = Trip::factory()->create([
            'owner_id' => $this->owner->id, 'operator_id' => $adri->id,
            'trip_date' => today()->subDay(), 'trip_number_of_day' => 1,
            'starting_cluster_id' => $this->cluster->id, 'qty_carried_total' => 75,
            'started_at' => now()->subDay(), 'ended_at' => now()->subDay(),
        ]);
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id, 'name' => 'Kedai Z', 'default_qty_mika' => 5]);
        $this->titipan($kiosk, $tripKemarin, 5, createdAt: now()->subDay());

        $tripMadan = $this->trip($madan);

        $this->actingAs($madan);
        $component = Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id);

        $this->assertFalse($component->instance()->pendingDeliveryBlocksSettleToday());

        $component->call('chooseAction', 'tagih_titip');
        $this->assertSame('tagih_titip', $component->get('chosenAction'), 'Siklus normal tetap jalan untuk titipan hari sebelumnya.');

        $component->set('dropBaru', 5)
            ->call('saveVisit')
            ->assertHasNoErrors('general');

        $this->assertSame(1, Settlement::count(), 'Tagih atas titipan kemarin harus berhasil (tidak diblokir).');
    }

    // === KARTU & BADGE (item a+b, regresi cepat lewat guard yang sama) ===

    public function test_card_shows_warning_badge_for_kiosk_visited_by_other_trip_today(): void
    {
        $adri = $this->operator('Adri');
        $madan = $this->operator('Madan');

        $tripAdri = $this->trip($adri, 1);
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id, 'name' => 'Kedai Badge', 'default_qty_mika' => 5]);
        $this->titipan($kiosk, $tripAdri, 5);

        $this->trip($madan, 2);
        $this->actingAs($madan);

        Livewire::test(ActiveTrip::class)
            ->assertSee('Sudah diantar Adri hari ini');

        // Kedai dgn titipan trip lain hari ini didorong ke bawah daftar (sortToBottomIds).
        $kiosks = Livewire::test(ActiveTrip::class)->viewData('kiosks');
        $this->assertSame('Kedai Badge', $kiosks->last()->name);
    }
}
