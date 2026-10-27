<?php

namespace Tests\Feature\Support;

use App\Models\Cluster;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * BRIEF 27 Okt 2026 "TRIP LUPA DIAKHIRI" item 2a/2b — App\Http\Middleware\
 * AutoCloseStaleTrips, dipicu nyata lewat request HTTP (bukan Livewire::test(),
 * yang tidak lewat pipeline middleware).
 */
class AutoCloseStaleTripsMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_opening_any_page_auto_closes_only_their_own_stale_trip(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $owner->id]);
        $otherOperator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $owner->id]);
        $cluster = Cluster::create(['name' => 'C', 'owner_id' => $owner->id]);

        $staleOwn = Trip::factory()->create([
            'owner_id' => $owner->id, 'operator_id' => $operator->id, 'starting_cluster_id' => $cluster->id,
            'trip_date' => today()->subDay(), 'trip_number_of_day' => 1,
            'started_at' => now()->subDay(), 'ended_at' => null,
        ]);
        $staleOther = Trip::factory()->create([
            'owner_id' => $owner->id, 'operator_id' => $otherOperator->id, 'starting_cluster_id' => $cluster->id,
            'trip_date' => today()->subDay(), 'trip_number_of_day' => 2,
            'started_at' => now()->subDay(), 'ended_at' => null,
        ]);

        $this->actingAs($operator)->get(route('operator.trip.start'))->assertOk();

        $this->assertNotNull($staleOwn->refresh()->ended_at, 'Trip stale milik operator yang login harus ditutup otomatis saat membuka halaman operator.');
        $this->assertSame('auto_closed', $staleOwn->ended_reason);
        $this->assertNull($staleOther->refresh()->ended_at, 'Trip stale operator LAIN tidak boleh ikut ditutup.');
    }

    public function test_owner_opening_dashboard_auto_closes_their_own_stale_trips(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $otherOwner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $owner->id]);
        $otherOperator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $otherOwner->id]);
        $cluster = Cluster::create(['name' => 'C', 'owner_id' => $owner->id]);
        $otherCluster = Cluster::create(['name' => 'D', 'owner_id' => $otherOwner->id]);

        $stale = Trip::factory()->create([
            'owner_id' => $owner->id, 'operator_id' => $operator->id, 'starting_cluster_id' => $cluster->id,
            'trip_date' => today()->subDay(), 'trip_number_of_day' => 1,
            'started_at' => now()->subDay(), 'ended_at' => null,
        ]);
        $staleOther = Trip::factory()->create([
            'owner_id' => $otherOwner->id, 'operator_id' => $otherOperator->id, 'starting_cluster_id' => $otherCluster->id,
            'trip_date' => today()->subDay(), 'trip_number_of_day' => 1,
            'started_at' => now()->subDay(), 'ended_at' => null,
        ]);

        $this->actingAs($owner)->get(route('owner.dashboard'))->assertOk();

        $this->assertNotNull($stale->refresh()->ended_at, 'Trip stale owner yang login harus ditutup saat membuka dashboard.');
        $this->assertNull($staleOther->refresh()->ended_at, 'Trip stale owner LAIN tidak boleh ikut ditutup.');
    }

    /**
     * Kasus umum (tidak ada trip stale) — middleware menambah query TETAP (bukan
     * nol, tapi kecil & konstan), supaya tidak memperlambat halaman.
     */
    public function test_middleware_adds_a_bounded_number_of_queries_when_nothing_is_stale(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $owner->id]);
        $cluster = Cluster::create(['name' => 'C', 'owner_id' => $owner->id]);

        // Trip hari ini saja — tidak stale.
        Trip::factory()->create([
            'owner_id' => $owner->id, 'operator_id' => $operator->id, 'starting_cluster_id' => $cluster->id,
            'trip_date' => today(), 'trip_number_of_day' => 1,
            'started_at' => now(), 'ended_at' => null,
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($operator)->get(route('operator.trip.start'))->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $staleCheckQueries = array_filter(
            $queries,
            fn ($q) => str_contains($q['query'], 'date(`trip_date`) <')
        );

        $this->assertCount(1, $staleCheckQueries, 'Cek stale harus TEPAT 1 query per request halaman, berapa pun kompleksnya halaman itu sendiri.');
    }
}
