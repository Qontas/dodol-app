<?php

namespace Tests\Feature\Operator;

use App\Livewire\Operator\ActiveTrip;
use App\Livewire\Operator\Dashboard;
use App\Livewire\Operator\StartTrip;
use App\Models\Cluster;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Audit kekhawatiran owner: operator ke-logout di lapangan (sesi habis/HP di-lock
 * berjam-jam) → apakah trip yang sedang berjalan HILANG?
 *
 * Trip tersimpan MURNI di tabel `trips` (started_at/ended_at) — TIDAK PERNAH di
 * session/cache. Test ini membuktikan: logout (invalidate sesi asli, bukan cuma
 * lupa auth di memori) lalu login ulang tidak mempengaruhi trip aktif sama sekali —
 * dashboard & StartTrip auto-resume ke trip yang sama, bukan bikin trip baru.
 */
class TripPersistenceAcrossLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_trip_survives_a_real_logout_and_login_cycle(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $owner->id]);
        $cluster = Cluster::create(['name' => 'Area Trip', 'is_active' => true, 'owner_id' => $owner->id]);

        $trip = Trip::factory()->create([
            'owner_id' => $owner->id,
            'operator_id' => $operator->id,
            'trip_date' => today(),
            'started_at' => now()->subHours(2),
            'ended_at' => null,
            'starting_cluster_id' => $cluster->id,
        ]);

        $this->actingAs($operator);

        // Sesi "asli" (bukan simulasi) — session driver = database, invalidate()
        // menghapus baris sesi & regenerate token, persis efek logout produksi.
        $this->app['session']->invalidate();
        Auth::guard('web')->logout();

        $this->assertGuest('web');

        // Login ulang — persis skenario "operator buka app lagi setelah sesi habis".
        $this->actingAs($operator);
        $this->assertAuthenticatedAs($operator);

        // 1) Dashboard: trip aktif TERBACA, bukan null — dan itu trip YANG SAMA.
        Livewire::test(Dashboard::class)
            ->assertSet('activeTrip.id', $trip->id);

        // 2) Buka /operator/trip/start (mis. operator lupa & coba mulai trip baru)
        // → layar menampilkan KARTU trip berjalan, BUKAN form trip baru.
        // (Kontrak berubah 29 Juli 2026: dulu redirect diam-diam — lihat catatan di
        // TripResilienceTest::test_start_trip_shows_running_trip_card_instead_of_form.)
        Livewire::test(StartTrip::class)
            ->assertNoRedirect()
            ->assertSee('Kamu masih punya trip berjalan')
            ->assertSee('Trip #'.$trip->trip_number_of_day)
            ->assertDontSee('Berapa mika yang kamu bawa hari ini?');

        // 3) Buka halaman trip aktif langsung → resume dengan trip yang sama, bukan 404/kosong.
        // (ActiveTrip::mount() sengaja tak memakai {tripId} dari URL — lihat test kedua di
        // bawah — jadi tak perlu dioper ke Livewire::test di sini.)
        Livewire::test(ActiveTrip::class)
            ->assertSet('trip.id', $trip->id);

        // 4) Tidak ada trip kedua yang kebentuk akibat siklus logout/login ini.
        $this->assertSame(1, Trip::where('operator_id', $operator->id)->count());
    }

    public function test_active_trip_resolves_via_get_ignoring_url_trip_id(): void
    {
        // ActiveTrip::mount() sengaja TIDAK memakai {tripId} dari URL untuk query —
        // ia selalu cari trip aktif operator dari DB. Buktikan lewat request GET
        // sungguhan ke URL dengan ID SEMBARANG/basi: operator tetap mendarat di
        // trip aktif yang benar, bukan 404/kosong.
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $owner->id]);

        $trip = Trip::factory()->create([
            'owner_id' => $owner->id,
            'operator_id' => $operator->id,
            'trip_date' => today(),
            'started_at' => now()->subMinutes(30),
            'ended_at' => null,
        ]);

        $this->actingAs($operator);

        $this->get(route('operator.trip.active', 999999))
            ->assertOk()
            ->assertSeeText('Trip #'.$trip->trip_number_of_day);
    }
}
