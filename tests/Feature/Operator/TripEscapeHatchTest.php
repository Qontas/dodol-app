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
     * A2 — "Batalkan Trip Ini" untuk trip KOSONG: mengarsipkan (soft delete), bukan
     * menghapus permanen, lalu operator bisa mulai trip yang benar.
     */
    public function test_cancel_archives_an_empty_trip_and_frees_the_operator(): void
    {
        [$owner, $operator, $kota1] = $this->scaffold();
        $trip = $this->tripBebas($owner, $operator);

        $this->actingAs($operator);

        Livewire::test(StartTrip::class)
            ->call('cancelActiveTrip')
            ->assertSee('dibatalkan dan diarsipkan')
            // Kartu hilang, form kembali — bukan sisa memo basi.
            ->assertDontSee('Kamu masih punya trip berjalan')
            ->assertSee('Berapa mika yang kamu bawa hari ini?');

        // ARSIP, bukan hapus permanen: baris masih ada, cuma deleted_at terisi.
        $this->assertSoftDeleted('trips', ['id' => $trip->id]);
        $this->assertSame(1, Trip::withTrashed()->count());
        $this->assertSame(0, Trip::count()); // global scope SoftDeletes menyembunyikannya
    }

    /**
     * A (end-to-end, verifikasi yang diminta owner): batalkan trip KOSONG → mulai trip
     * "Kota 1" → daftar berisi kedai KOTA 1, bukan area lain.
     */
    public function test_end_to_end_cancel_then_start_kota1_opens_kota1_kiosks(): void
    {
        [$owner, $operator, $kota1, $pancing] = $this->scaffold();
        // 🔴 nomor 1 DISENGAJA (ketahuan dari verifikasi browser): trip yang diarsipkan
        // TETAP memegang nomornya di index unik idx_trip_owner_date_number. Kalau
        // penomoran trip baru tak menghitung trip terarsip, nomor 1 dipakai ulang →
        // duplicate key → operator terlempar ke dashboard tanpa trip. Dengan nomor 2
        // (default helper) tabrakan itu tak pernah terjadi dan bug lolos.
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

        // Nomor trip baru MELEWATI nomor yang masih dipegang trip terarsip.
        $this->assertSame(2, $baru->trip_number_of_day);
    }

    /**
     * Bentuk murni bug penomoran di atas: trip yang DIARSIPKAN tetap memegang
     * nomornya di index unik `idx_trip_owner_date_number` (soft delete tidak
     * melepaskan baris). Penomoran trip berikutnya WAJIB menghitung trip terarsip,
     * kalau tidak INSERT-nya kena duplicate key dan trip tak pernah terbentuk.
     *
     * Ini juga menutup jalur yang sudah ada sejak fitur arsip trip: owner
     * mengarsipkan trip dari panel, lalu operator mulai trip di hari yang sama.
     */
    public function test_archived_trip_still_reserves_its_number(): void
    {
        [$owner, $operator, $kota1] = $this->scaffold();

        $lama = Trip::factory()->create([
            'owner_id' => $owner->id, 'operator_id' => $operator->id,
            'trip_date' => today(), 'trip_number_of_day' => 1,
            'starting_cluster_id' => $kota1->id,
            'started_at' => now()->subHours(2), 'ended_at' => now()->subHour(),
        ]);
        $lama->delete(); // diarsipkan (mis. dari panel owner)

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
