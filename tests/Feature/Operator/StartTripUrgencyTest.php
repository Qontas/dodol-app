<?php

namespace Tests\Feature\Operator;

use App\Livewire\Operator\StartTrip;
use App\Models\Cluster;
use App\Models\Kiosk;
use App\Models\KioskVisit;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Urgency cluster di halaman mulai trip: deteksi kios overdue/warning harus
 * benar (regresi: diffInDays Carbon 3 bertanda → overdue tak pernah muncul)
 * dan tanpa N+1 query.
 */
class StartTripUrgencyTest extends TestCase
{
    use RefreshDatabase;

    protected User $operator;
    protected Cluster $cluster;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = User::factory()->create(['role' => 'operator', 'is_active' => true]);
        $this->cluster = Cluster::create(['name' => 'Cluster Urgency']);
    }

    protected function visitKioskDaysAgo(Kiosk $kiosk, int $daysAgo): void
    {
        $trip = Trip::factory()->create([
            'operator_id' => $this->operator->id,
            'trip_date' => today()->subDays($daysAgo)->format('Y-m-d'),
            'started_at' => now()->subDays($daysAgo),
            'ended_at' => now()->subDays($daysAgo),
        ]);

        KioskVisit::create([
            'trip_id' => $trip->id,
            'kiosk_id' => $kiosk->id,
            'visited_at' => now()->subDays($daysAgo),
            'visit_action' => 'check_only',
            'extension_granted' => false,
        ]);
    }

    protected function clustersProperty()
    {
        $this->actingAs($this->operator);

        return Livewire::test(StartTrip::class)->instance()->clusters;
    }

    public function test_kiosk_visited_past_target_interval_marks_cluster_high(): void
    {
        $kiosk = Kiosk::factory()->create([
            'cluster_id' => $this->cluster->id,
            'target_visit_interval_days' => 14,
            'warning_visit_interval_days' => 10,
        ]);
        $this->visitKioskDaysAgo($kiosk, 20);

        $urgency = $this->clustersProperty()->firstWhere('id', $this->cluster->id)->urgency_data;

        $this->assertEquals('high', $urgency['level']);
        $this->assertEquals(1, $urgency['overdue_count']);
    }

    public function test_kiosk_in_warning_window_marks_cluster_medium(): void
    {
        $kiosk = Kiosk::factory()->create([
            'cluster_id' => $this->cluster->id,
            'target_visit_interval_days' => 14,
            'warning_visit_interval_days' => 10,
        ]);
        $this->visitKioskDaysAgo($kiosk, 12);

        $urgency = $this->clustersProperty()->firstWhere('id', $this->cluster->id)->urgency_data;

        $this->assertEquals('medium', $urgency['level']);
        $this->assertEquals(1, $urgency['warning_count']);
    }

    public function test_recently_visited_kiosk_marks_cluster_low(): void
    {
        $kiosk = Kiosk::factory()->create([
            'cluster_id' => $this->cluster->id,
            'target_visit_interval_days' => 14,
            'warning_visit_interval_days' => 10,
        ]);
        $this->visitKioskDaysAgo($kiosk, 2);

        $urgency = $this->clustersProperty()->firstWhere('id', $this->cluster->id)->urgency_data;

        $this->assertEquals('low', $urgency['level']);
    }

    public function test_never_visited_kiosks_mark_cluster_unknown(): void
    {
        Kiosk::factory()->create(['cluster_id' => $this->cluster->id]);

        $urgency = $this->clustersProperty()->firstWhere('id', $this->cluster->id)->urgency_data;

        $this->assertEquals('unknown', $urgency['level']);
        $this->assertEquals(1, $urgency['never_count']);
    }

    /**
     * Anti N+1: berapapun jumlah kios, hitung urgency semua cluster
     * maksimal 3 query (clusters + kiosks + last visits).
     */
    public function test_clusters_urgency_uses_constant_query_count(): void
    {
        $clusterB = Cluster::create(['name' => 'Cluster B']);
        $kiosks = Kiosk::factory()->count(8)->create(['cluster_id' => $this->cluster->id]);
        Kiosk::factory()->count(7)->create(['cluster_id' => $clusterB->id]);
        $this->visitKioskDaysAgo($kiosks->first(), 5);

        $this->actingAs($this->operator);
        $component = Livewire::test(StartTrip::class)->instance();

        DB::enableQueryLog();
        $component->clusters;
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(3, $queryCount, "Urgency clusters memakai {$queryCount} query (harusnya ≤ 3, bebas N+1).");
    }
}
