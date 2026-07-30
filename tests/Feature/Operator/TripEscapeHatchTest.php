<?php

namespace Tests\Feature\Operator;

use App\Livewire\Operator\ActiveTrip;
use App\Livewire\Operator\StartTrip;
use App\Models\Cluster;
use App\Models\Delivery;
use App\Models\Kiosk;
use App\Models\KioskVisit;
use App\Models\ProductVariant;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * BAGIAN A — JALAN KELUAR DARI TRIP YANG SALAH (bug berulang, 29 Juli 2026).
 *
 * GEJALA OWNER: mulai Trip Bebas 75 mika → tekan BACK → layar Mulai Trip muncul lagi
 * → pilih "Kota 1" → tekan Mulai → yang terbuka daftar kedai yang dia kira Pancing.
 *
 * DUGAAN yang DIVERIFIKASI (bukan diterima mentah):
 *   - BACK menyajikan halaman Mulai Trip dari cache → guard mount() tak jalan. BENAR,
 *     tapi mekanismenya BUKAN bfcache browser: `wire:navigate` punya `snapshotCache`
 *     JavaScript sendiri (vendor/livewire/livewire/dist/livewire.js:8127) yang pada
 *     `popstate` menempelkan HTML lama ke DOM TANPA request ke server. Header
 *     `Cache-Control: no-store` dari middleware `no-store` (routes/web.php:110) sudah
 *     terpasang dan TIDAK berpengaruh sama sekali — tak ada HTTP untuk difilter.
 *   - Saat tekan Mulai, "Proteksi 1" menemukan trip bebas aktif → redirect DIAM-DIAM
 *     ke trip itu. BENAR — dan itu bagian yang paling menyesatkan, karena kartu kedai
 *     tak berlabel area sehingga owner mengira "kebuka Pancing".
 *
 * Test di file ini mengunci perilaku BARU: kartu, bukan form; pesan, bukan diam.
 */
class TripEscapeHatchTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: User, 2: Cluster, 3: Cluster} */
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

    private function tripBebas(User $owner, User $operator, int $number = 2): Trip
    {
        return Trip::factory()->create([
            'owner_id' => $owner->id,
            'operator_id' => $operator->id,
            'trip_date' => today(),
            'trip_number_of_day' => $number,
            'starting_cluster_id' => null, // Trip Bebas — "Semua Kios"
            'started_at' => now(),
            'ended_at' => null,
            'qty_carried_total' => 75,
        ]);
    }

    /**
     * A1 — ada trip aktif → layar Mulai Trip menampilkan KARTU, bukan form,
     * dan menyebutkan nomor + AREA + jam + mika yang dibawa.
     */
    public function test_running_trip_is_shown_as_a_card_with_area_and_details(): void
    {
        [$owner, $operator] = $this->scaffold();
        $trip = $this->tripBebas($owner, $operator);

        $this->actingAs($operator);

        Livewire::test(StartTrip::class)
            ->assertNoRedirect()
            ->assertSee('Kamu masih punya trip berjalan')
            ->assertSee('Trip #2')
            ->assertSee('Semua Kios')      // ← AREA disebutkan; inilah yang dulu tak terlihat
            ->assertSee('75 mika')
            ->assertSee('Lanjutkan Trip')
            ->assertDontSee('Berapa mika yang kamu bawa hari ini?');

        $this->assertSame($trip->id, Trip::whereNull('ended_at')->first()->id);
    }

    /**
     * A4 — jalur "tekan Mulai dari halaman BASI" (persis skenario BACK). Tidak boleh
     * redirect diam-diam; harus ada kalimat tegas bahwa trip baru TIDAK dibuat.
     */
    public function test_pressing_start_while_a_trip_runs_says_so_instead_of_redirecting_silently(): void
    {
        [$owner, $operator, $kota1] = $this->scaffold();
        $this->tripBebas($owner, $operator);

        $this->actingAs($operator);

        $component = Livewire::test(StartTrip::class)
            // Halaman basi hasil BACK masih memegang form: operator memilih Kota 1 + 75 mika.
            ->set('selectedClusterId', $kota1->id)
            ->set('qtyCarried', 75)
            ->call('startTrip');

        $component->assertNoRedirect()
            ->assertSee('Kamu sudah punya trip berjalan')
            ->assertSee('Semua Kios')
            ->assertSee('Trip baru TIDAK dibuat');

        // Dan memang tidak ada trip kedua yang terbentuk.
        $this->assertSame(1, Trip::count());
        $this->assertNull(Trip::first()->starting_cluster_id);
    }

    /**
     * A2 — "Batalkan Trip Ini" untuk trip KOSONG: **HAPUS PERMANEN**, bukan arsip.
     *
     * 🔴 KONTRAK BERUBAH 30 Juli 2026 (keputusan owner). Dulu test ini meng-assert
     * `assertSoftDeleted`. Arsip tak melindungi apa pun untuk trip kosong (tak ada
     * data untuk dijaga) TAPI barisnya tetap memegang nomor trip di index unik →
     * nomor melompat & laporan owner berisi trip hantu tanpa isi.
     */
    public function test_cancel_permanently_deletes_an_empty_trip_and_frees_the_operator(): void
    {
        [$owner, $operator, $kota1] = $this->scaffold();
        $trip = $this->tripBebas($owner, $operator);

        $this->actingAs($operator);

        Livewire::test(StartTrip::class)
            ->call('cancelActiveTrip')
            ->assertSee('dibatalkan')
            // Tak boleh lagi menjanjikan arsip/pemulihan — itu jadi bohong.
            ->assertDontSee('diarsipkan')
            // Kartu hilang, form kembali — bukan sisa memo basi.
            ->assertDontSee('Kamu masih punya trip berjalan')
            ->assertSee('Berapa mika yang kamu bawa hari ini?');

        // BENAR-BENAR hilang dari DB — bukan soft delete.
        $this->assertNull(Trip::withTrashed()->find($trip->id));
        $this->assertSame(0, Trip::withTrashed()->count());
        $this->assertDatabaseMissing('trips', ['id' => $trip->id]);
    }

    /**
     * 🔴 INTI PERMINTAAN OWNER: salah pencet DUA KALI lalu mulai trip beneran →
     * trip itu dapat nomor **1**, bukan 3. Dulu dua baris arsip menahan nomor 1 & 2.
     */
    public function test_two_cancelled_empty_trips_do_not_burn_trip_numbers(): void
    {
        [$owner, $operator, $kota1] = $this->scaffold();

        $this->actingAs($operator);

        // Salah pencet #1 — Trip Bebas, langsung dibatalkan.
        Livewire::test(StartTrip::class)
            ->set('tripBebas', true)
            ->set('qtyCarried', 75)
            ->call('startTrip');
        $this->assertSame(1, Trip::withTrashed()->first()->trip_number_of_day);
        Livewire::test(StartTrip::class)->call('cancelActiveTrip');

        // Salah pencet #2 — sekali lagi.
        Livewire::test(StartTrip::class)
            ->set('tripBebas', true)
            ->set('qtyCarried', 75)
            ->call('startTrip');
        Livewire::test(StartTrip::class)->call('cancelActiveTrip');

        $this->assertSame(0, Trip::withTrashed()->count(), 'Tak boleh ada sisa trip hantu.');

        // Trip BENERAN, area Kota 1.
        Livewire::test(StartTrip::class)
            ->set('selectedClusterId', $kota1->id)
            ->set('qtyCarried', 75)
            ->call('startTrip')
            ->assertRedirect();

        $beneran = Trip::whereNull('ended_at')->first();

        $this->assertNotNull($beneran);
        $this->assertSame(
            1,
            $beneran->trip_number_of_day,
            'Trip pertama beneran hari itu HARUS "Trip #1", bukan #3 — nomor tak boleh dibakar trip kosong.'
        );
        $this->assertSame($kota1->id, $beneran->starting_cluster_id);
        $this->assertSame(1, Trip::withTrashed()->count());
    }

    /**
     * forceDelete tak boleh menyeret data milik trip/kios LAIN. Diperiksa di RAW DB
     * (bukan lewat model) karena yang diuji adalah perilaku FK cascade.
     *
     * Set FK yang cascade dari `trips` = commissions, deliveries, kiosk_visits —
     * sama persis dengan yang diperiksa tripHasActivity(). Test ini mengunci bahwa
     * trip TETANGGA yang berisi data tetap utuh sesudahnya.
     */
    public function test_force_delete_leaves_other_trips_data_untouched(): void
    {
        [$owner, $operator, $kota1] = $this->scaffold();

        // Trip TETANGGA yang BERISI (hari lalu, sudah selesai) — tak boleh tersentuh.
        $tetangga = Trip::factory()->create([
            'owner_id' => $owner->id, 'operator_id' => $operator->id,
            'trip_date' => today()->subDay(), 'trip_number_of_day' => 1,
            'starting_cluster_id' => $kota1->id,
            'started_at' => now()->subDay(), 'ended_at' => now()->subDay()->addHours(3),
        ]);
        $kiosk = Kiosk::factory()->create(['cluster_id' => $kota1->id]);
        $variant = ProductVariant::factory()->create(['is_active' => true]);

        $delivery = Delivery::create([
            'kiosk_id' => $kiosk->id, 'trip_id' => $tetangga->id,
            'product_variant_id' => $variant->id,
            'source_type' => 'new_procurement', 'delivery_type' => 'consignment',
            'qty_delivered' => 4, 'unit_price' => 12000,
        ]);
        $visit = KioskVisit::create([
            'trip_id' => $tetangga->id, 'kiosk_id' => $kiosk->id,
            'visited_at' => now()->subDay(), 'visit_action' => 'drop_only',
            'new_delivery_id' => $delivery->id,
        ]);

        // Trip KOSONG hari ini yang akan dibatalkan.
        $kosong = $this->tripBebas($owner, $operator, number: 1);

        $sebelum = [
            'trips' => DB::table('trips')->count(),
            'deliveries' => DB::table('deliveries')->count(),
            'kiosk_visits' => DB::table('kiosk_visits')->count(),
            'commissions' => DB::table('commissions')->count(),
            'settlements' => DB::table('settlements')->count(),
            'delivery_origins' => DB::table('delivery_origins')->count(),
        ];

        $this->actingAs($operator);
        Livewire::test(StartTrip::class)->call('cancelActiveTrip');

        // Hanya SATU baris yang hilang, dan itu barisnya sendiri.
        $this->assertSame($sebelum['trips'] - 1, DB::table('trips')->count());
        foreach (['deliveries', 'kiosk_visits', 'commissions', 'settlements', 'delivery_origins'] as $tabel) {
            $this->assertSame(
                $sebelum[$tabel],
                DB::table($tabel)->count(),
                "Tabel '{$tabel}' berubah akibat forceDelete trip KOSONG — tak boleh."
            );
        }

        // Data trip tetangga utuh, termasuk pointer SET NULL yang rawan ternulkan.
        $this->assertDatabaseMissing('trips', ['id' => $kosong->id]);
        $this->assertDatabaseHas('trips', ['id' => $tetangga->id]);
        $this->assertSame($delivery->id, DB::table('kiosk_visits')->where('id', $visit->id)->value('new_delivery_id'));
        $this->assertSame($tetangga->id, (int) DB::table('deliveries')->where('id', $delivery->id)->value('trip_id'));
    }

    /**
     * A (end-to-end, verifikasi yang diminta owner): batalkan trip KOSONG → mulai trip
     * "Kota 1" → daftar berisi kedai KOTA 1, bukan area lain.
     */
    public function test_end_to_end_cancel_then_start_kota1_opens_kota1_kiosks(): void
    {
        [$owner, $operator, $kota1, $pancing] = $this->scaffold();
        // nomor 1 DISENGAJA: inilah nomor yang akan dipakai ulang trip berikutnya
        // setelah trip kosong ini DIHAPUS PERMANEN.
        $this->tripBebas($owner, $operator, number: 1);

        Kiosk::factory()->create(['cluster_id' => $kota1->id, 'name' => 'Kedai Kota Satu', 'sort_order' => 1]);
        Kiosk::factory()->create(['cluster_id' => $pancing->id, 'name' => 'Bilal 3', 'sort_order' => 50]);

        $this->actingAs($operator);

        Livewire::test(StartTrip::class)
            ->call('cancelActiveTrip')
            ->set('selectedClusterId', $kota1->id)
            ->set('qtyCarried', 75)
            ->call('startTrip')
            ->assertRedirect(); // kali ini redirect memang benar: trip BARU sudah dibuat

        $baru = Trip::whereNull('ended_at')->first();
        $this->assertNotNull($baru);
        $this->assertSame($kota1->id, $baru->starting_cluster_id);
        $this->assertSame(75, $baru->qty_carried_total);

        Livewire::test(ActiveTrip::class)
            ->assertSet('trip.id', $baru->id)
            ->assertSee('Kedai Kota Satu')
            ->assertDontSee('Bilal 3');

        // Nomor 1 DIPAKAI ULANG: trip kosong tadi dihapus permanen, jadi tak menahan
        // nomor apa pun. Operator melihat "Trip #1" — memang trip pertamanya hari itu.
        $this->assertSame(1, $baru->trip_number_of_day);
        $this->assertSame(1, Trip::withTrashed()->count());
    }

    /**
     * 🔴 DUA JALUR YANG SENGAJA BERBEDA — jangan disatukan.
     *
     * Trip BERISI yang diarsipkan owner dari panel (Ronde 1, soft delete) TETAP
     * memegang nomornya di index unik `idx_trip_owner_date_number`, jadi penomoran
     * trip berikutnya WAJIB `Trip::withTrashed()` — kalau tidak, INSERT kena
     * duplicate key dan trip tak pernah terbentuk (fix c54f978).
     *
     * Bandingkan dengan `test_two_cancelled_empty_trips_do_not_burn_trip_numbers`:
     * trip KOSONG yang dibatalkan operator DIHAPUS PERMANEN, jadi nomornya justru
     * HARUS dipakai ulang. Perbedaannya: di sini ada DATA yang harus dijaga.
     */
    public function test_archived_trip_from_owner_panel_still_reserves_its_number(): void
    {
        [$owner, $operator, $kota1] = $this->scaffold();

        $lama = Trip::factory()->create([
            'owner_id' => $owner->id, 'operator_id' => $operator->id,
            'trip_date' => today(), 'trip_number_of_day' => 1,
            'starting_cluster_id' => $kota1->id,
            'started_at' => now()->subHours(2), 'ended_at' => now()->subHour(),
        ]);

        // Trip ini BERISI — persis alasan kenapa jalur owner tetap arsip.
        $kiosk = Kiosk::factory()->create(['cluster_id' => $kota1->id]);
        KioskVisit::create([
            'trip_id' => $lama->id, 'kiosk_id' => $kiosk->id,
            'visited_at' => now()->subHours(2), 'visit_action' => 'check_only',
        ]);

        $lama->delete(); // diarsipkan dari panel owner = SOFT delete

        // Masih ada barisnya, cuma disembunyikan global scope.
        $this->assertSoftDeleted('trips', ['id' => $lama->id]);
        $this->assertNotNull(Trip::withTrashed()->find($lama->id));
        $this->assertDatabaseHas('kiosk_visits', ['trip_id' => $lama->id]);

        $this->actingAs($operator);

        Livewire::test(StartTrip::class)
            ->set('selectedClusterId', $kota1->id)
            ->set('qtyCarried', 50)
            ->call('startTrip')
            ->assertRedirect();

        $baru = Trip::whereNull('ended_at')->first();

        $this->assertNotNull($baru, 'Trip baru harus terbentuk, bukan gagal diam-diam.');
        $this->assertSame(2, $baru->trip_number_of_day, 'Nomor 1 masih dipegang trip terarsip.');
        $this->assertSame($kota1->id, $baru->starting_cluster_id);

        // Dan trip terarsip itu masih BISA DIPULIHKAN — arsip tetap arsip.
        Trip::withTrashed()->find($lama->id)->restore();
        $this->assertNotNull(Trip::find($lama->id));
        $this->assertDatabaseHas('kiosk_visits', ['trip_id' => $lama->id]);
    }

    /**
     * A2 — trip BERISI AKTIVITAS ditolak. Dua lapis: tombol tak muncul di UI DAN
     * server menolak walau aksi dipanggil paksa (jangan andalkan UI).
     */
    public function test_cancel_is_refused_for_a_trip_that_has_activity(): void
    {
        [$owner, $operator, $kota1] = $this->scaffold();
        $trip = $this->tripBebas($owner, $operator);

        $kiosk = Kiosk::factory()->create(['cluster_id' => $kota1->id, 'name' => 'Kedai Kota Satu']);
        KioskVisit::create([
            'trip_id' => $trip->id,
            'kiosk_id' => $kiosk->id,
            'visited_at' => now(),
            'visit_action' => 'check_only',
        ]);

        $this->actingAs($operator);

        $component = Livewire::test(StartTrip::class);

        // (a) UI: tombol batal tidak muncul, arahan "Akhiri Trip" muncul.
        $component->assertSee('Kamu masih punya trip berjalan')
            ->assertDontSee('Batalkan Trip Ini')
            ->assertSee('Akhiri Trip');
        $this->assertFalse($component->viewData('canCancelActiveTrip'));

        // (b) SERVER: aksi dipanggil paksa (klien nakal / tombol basi) → DITOLAK.
        $component->call('cancelActiveTrip')
            ->assertSee('tidak bisa dibatalkan');

        $this->assertNotSoftDeleted('trips', ['id' => $trip->id]);
        $this->assertSame(1, Trip::count());
    }

    /** Trip dengan DELIVERY (tanpa kunjungan) juga terhitung "ada aktivitas". */
    public function test_cancel_is_refused_when_the_trip_has_a_delivery(): void
    {
        [$owner, $operator, $kota1] = $this->scaffold();
        $trip = $this->tripBebas($owner, $operator);

        $kiosk = Kiosk::factory()->create(['cluster_id' => $kota1->id]);
        $variant = ProductVariant::factory()->create(['is_active' => true]);

        Delivery::create([
            'kiosk_id' => $kiosk->id,
            'trip_id' => $trip->id,
            'product_variant_id' => $variant->id,
            'source_type' => 'new_procurement',
            'delivery_type' => 'consignment',
            'qty_delivered' => 4,
            'unit_price' => 12000,
        ]);

        $this->actingAs($operator);

        $component = Livewire::test(StartTrip::class);
        $this->assertFalse($component->viewData('canCancelActiveTrip'));

        $component->call('cancelActiveTrip');
        $this->assertNotSoftDeleted('trips', ['id' => $trip->id]);
    }

    /**
     * 🔒 Isolasi tenant: operator TIDAK bisa membatalkan trip operator lain, walau
     * trip itu kosong. Query di-scope operator_id — aksi jadi no-op, bukan bocor.
     */
    public function test_operator_cannot_cancel_another_operators_trip(): void
    {
        [$ownerA, $operatorA] = $this->scaffold();
        $tripA = $this->tripBebas($ownerA, $operatorA);

        $ownerB = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $operatorB = User::factory()->create([
            'role' => 'operator', 'is_active' => true, 'owner_id' => $ownerB->id,
        ]);

        $this->actingAs($operatorB);

        Livewire::test(StartTrip::class)
            // Operator B tak punya trip → form biasa, bukan kartu trip milik A.
            ->assertDontSee('Kamu masih punya trip berjalan')
            ->call('cancelActiveTrip');

        $this->assertNotSoftDeleted('trips', ['id' => $tripA->id]);
    }

    /**
     * Trip basi HARI LALU tetap tidak menghalangi trip hari ini (regresi fix 28 Juli
     * 2026 — kartu di-scope hari ini, sama seperti Proteksi 1).
     */
    public function test_stale_trip_from_yesterday_does_not_block_todays_form(): void
    {
        [$owner, $operator, $kota1] = $this->scaffold();

        Trip::factory()->create([
            'owner_id' => $owner->id, 'operator_id' => $operator->id,
            'trip_date' => today()->subDay(), 'trip_number_of_day' => 1,
            'starting_cluster_id' => $kota1->id,
            'started_at' => now()->subDay(), 'ended_at' => null,
        ]);

        $this->actingAs($operator);

        Livewire::test(StartTrip::class)
            ->assertDontSee('Kamu masih punya trip berjalan')
            ->assertSee('Berapa mika yang kamu bawa hari ini?');
    }

    /**
     * Kartu harus menyebut trip yang SAMA dengan yang dibuka ActiveTrip. Dulu
     * StartTrip pakai ->first() (id TERKECIL) sedangkan ActiveTrip pakai latest('id')
     * — dua trip aktif di hari yang sama membuat kartunya sendiri berbohong.
     */
    public function test_card_names_the_same_trip_that_active_trip_will_open(): void
    {
        [$owner, $operator, $kota1, $pancing] = $this->scaffold();

        Trip::factory()->create([
            'owner_id' => $owner->id, 'operator_id' => $operator->id,
            'trip_date' => today(), 'trip_number_of_day' => 1,
            'starting_cluster_id' => $pancing->id,
            'started_at' => now()->subHour(), 'ended_at' => null,
        ]);
        $terbaru = Trip::factory()->create([
            'owner_id' => $owner->id, 'operator_id' => $operator->id,
            'trip_date' => today(), 'trip_number_of_day' => 2,
            'starting_cluster_id' => $kota1->id,
            'started_at' => now(), 'ended_at' => null,
        ]);

        $this->actingAs($operator);

        Livewire::test(StartTrip::class)
            ->assertSee('Trip #2')
            ->assertSee('Kota 1');

        Livewire::test(ActiveTrip::class)->assertSet('trip.id', $terbaru->id);
    }

    /**
     * Cluster sentinel walk-in (__walkin_owner_N) BUKAN area — jangan pernah muncul
     * sebagai pilihan di layar Mulai Trip. is_active-nya default true, jadi tanpa
     * excludeWalkInSentinel() ia nongol sebagai area berisi 1 kios.
     */
    public function test_walk_in_sentinel_cluster_is_not_offered_as_an_area(): void
    {
        [$owner, $operator, $kota1] = $this->scaffold();
        Kiosk::walkInSentinelFor($owner->id); // buat sentinel + cluster sentinelnya

        $this->actingAs($operator);

        $names = Livewire::test(StartTrip::class)->viewData('clusters')->pluck('name')->all();

        $this->assertSame(['Kota 1', 'Pancing'], $names);
        $this->assertNotContains(Kiosk::WALKIN_CLUSTER_PREFIX.$owner->id, $names);
    }
}
