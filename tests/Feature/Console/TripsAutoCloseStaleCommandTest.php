<?php

namespace Tests\Feature\Console;

use App\Models\Cluster;
use App\Models\Kiosk;
use App\Models\KioskVisit;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * BRIEF 27 Okt 2026 "TRIP LUPA DIAKHIRI" item 2c — `php artisan trips:auto-close-stale`.
 * Default DRY-RUN (tidak mengubah data), `--force` benar-benar menutup, `--owner`
 * membatasi ke satu owner.
 *
 * BRIEF lanjutan "SYARAT IDLE MINIMAL" — trip kandidat (trip_date < hari ini) yang
 * BELUM idle cukup lama (config('trips.auto_close_idle_hours'), default 6 jam)
 * TIDAK ikut ditampilkan/ditutup. `makeStaleTrip()` di bawah sengaja start 2 hari
 * lalu (jauh di atas ambang) supaya test-test LAMA tetap lolos tanpa berubah.
 */
class TripsAutoCloseStaleCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeStaleTrip(User $owner, User $operator, Cluster $cluster, int $number = 1): Trip
    {
        return Trip::factory()->create([
            'owner_id' => $owner->id, 'operator_id' => $operator->id,
            'starting_cluster_id' => $cluster->id,
            'trip_date' => today()->subDays(2), 'trip_number_of_day' => $number,
            'started_at' => now()->subDays(2), 'ended_at' => null,
        ]);
    }

    public function test_dry_run_lists_stale_trips_without_changing_data(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $owner->id]);
        $cluster = Cluster::create(['name' => 'C', 'owner_id' => $owner->id]);
        $trip = $this->makeStaleTrip($owner, $operator, $cluster);

        $this->artisan('trips:auto-close-stale')
            ->expectsOutputToContain('[DRY-RUN]')
            ->expectsOutputToContain('Dry-run saja')
            ->assertSuccessful();

        $this->assertNull($trip->refresh()->ended_at, 'Dry-run tidak boleh mengubah data.');
    }

    public function test_force_actually_closes_stale_trips(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $owner->id]);
        $cluster = Cluster::create(['name' => 'C', 'owner_id' => $owner->id]);
        $trip = $this->makeStaleTrip($owner, $operator, $cluster);

        $this->artisan('trips:auto-close-stale', ['--force' => true])
            ->expectsOutputToContain('[FORCE]')
            ->assertSuccessful();

        $trip->refresh();
        $this->assertNotNull($trip->ended_at);
        $this->assertSame('auto_closed', $trip->ended_reason);
    }

    public function test_owner_option_scopes_to_a_single_owner(): void
    {
        $ownerA = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $operatorA = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $ownerA->id]);
        $clusterA = Cluster::create(['name' => 'A', 'owner_id' => $ownerA->id]);
        $tripA = $this->makeStaleTrip($ownerA, $operatorA, $clusterA);

        $ownerB = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $operatorB = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $ownerB->id]);
        $clusterB = Cluster::create(['name' => 'B', 'owner_id' => $ownerB->id]);
        $tripB = $this->makeStaleTrip($ownerB, $operatorB, $clusterB);

        $this->artisan('trips:auto-close-stale', ['--owner' => $ownerA->id, '--force' => true])
            ->assertSuccessful();

        $this->assertNotNull($tripA->refresh()->ended_at, 'Trip owner A harus ditutup.');
        $this->assertNull($tripB->refresh()->ended_at, 'Trip owner B tidak boleh ikut ditutup saat --owner dibatasi ke A.');
    }

    public function test_reports_no_stale_trips_found_when_everything_is_current(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $owner->id]);
        $cluster = Cluster::create(['name' => 'C', 'owner_id' => $owner->id]);

        Trip::factory()->create([
            'owner_id' => $owner->id, 'operator_id' => $operator->id, 'starting_cluster_id' => $cluster->id,
            'trip_date' => today(), 'trip_number_of_day' => 1,
            'started_at' => now(), 'ended_at' => null,
        ]);

        $this->artisan('trips:auto-close-stale')
            ->expectsOutputToContain('Tidak ada trip stale')
            ->assertSuccessful();
    }

    /**
     * BRIEF lanjutan item 4 — trip kandidat (trip_date < hari ini) yang aktivitas
     * terakhirnya BELUM lewat ambang idle (operator masih mengantar lewat tengah
     * malam) tidak ikut ditampilkan di dry-run, dan --force tidak menutupnya.
     */
    public function test_dry_run_excludes_a_stale_trip_that_has_not_been_idle_long_enough(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-28 00:20:00'));

        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $owner->id]);
        $cluster = Cluster::create(['name' => 'C', 'owner_id' => $owner->id]);
        $kiosk = Kiosk::factory()->create(['cluster_id' => $cluster->id]);

        $trip = Trip::factory()->create([
            'owner_id' => $owner->id, 'operator_id' => $operator->id,
            'starting_cluster_id' => $cluster->id,
            'trip_date' => '2026-10-27', 'trip_number_of_day' => 1,
            'started_at' => Carbon::parse('2026-10-27 18:00:00'), 'ended_at' => null,
        ]);
        KioskVisit::create([
            'trip_id' => $trip->id, 'kiosk_id' => $kiosk->id,
            'visited_at' => Carbon::parse('2026-10-27 23:50:00'),
            'visit_action' => 'drop_only', 'extension_granted' => false,
        ]);

        $this->artisan('trips:auto-close-stale')
            ->expectsOutputToContain('Tidak ada trip stale')
            ->assertSuccessful();
        $this->assertNull($trip->refresh()->ended_at);

        $this->artisan('trips:auto-close-stale', ['--force' => true])
            ->expectsOutputToContain('Tidak ada trip stale')
            ->assertSuccessful();
        $this->assertNull($trip->refresh()->ended_at, '--force tidak boleh menutup trip yang belum idle cukup lama.');
    }

    /** Kolom "Aktivitas Terakhir" & "Tidak Aktif (Jam)" muncul di tabel dry-run. */
    public function test_dry_run_table_shows_last_activity_and_idle_hours_columns(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-29 10:00:00'));

        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $owner->id]);
        $cluster = Cluster::create(['name' => 'C', 'owner_id' => $owner->id]);
        $this->makeStaleTrip($owner, $operator, $cluster);

        // "Aktivitas Terakhir" dan "Tidak Aktif (Jam)" ada di baris HEADER tabel
        // yang SAMA — diperiksa sebagai SATU substring gabungan (bukan dua
        // expectsOutputToContain() terpisah), karena mock output test Laravel
        // hanya mencocokkan SATU ekspektasi per baris tertulis; dua ekspektasi
        // berbeda yang keduanya cocok pada baris yang sama membuat salah satu
        // dilaporkan "tidak ditemukan" walau isinya benar ada.
        $this->artisan('trips:auto-close-stale')
            ->expectsOutputToContain('Aktivitas Terakhir | Tidak Aktif (Jam)')
            ->expectsOutputToContain('tidak aktif > 6 jam')
            ->assertSuccessful();
    }

    /** Ambang idle dibaca dari config('trips.auto_close_idle_hours'), bisa diubah. */
    public function test_idle_threshold_in_dry_run_header_follows_config(): void
    {
        Config::set('trips.auto_close_idle_hours', 2);

        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $owner->id]);
        $cluster = Cluster::create(['name' => 'C', 'owner_id' => $owner->id]);
        $this->makeStaleTrip($owner, $operator, $cluster);

        $this->artisan('trips:auto-close-stale')
            ->expectsOutputToContain('tidak aktif > 2 jam')
            ->assertSuccessful();
    }
}
