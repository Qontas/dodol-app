<?php

namespace Tests\Feature\Console;

use App\Models\Cluster;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BRIEF 27 Okt 2026 "TRIP LUPA DIAKHIRI" item 2c — `php artisan trips:auto-close-stale`.
 * Default DRY-RUN (tidak mengubah data), `--force` benar-benar menutup, `--owner`
 * membatasi ke satu owner.
 */
class TripsAutoCloseStaleCommandTest extends TestCase
{
    use RefreshDatabase;

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
}
