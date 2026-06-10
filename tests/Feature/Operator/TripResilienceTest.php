<?php

namespace Tests\Feature\Operator;

use App\Livewire\Operator\ActiveTrip;
use App\Livewire\Operator\StartTrip;
use App\Models\Cluster;
use App\Models\Commission;
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
 * Skenario resilience: operator tutup browser di tengah trip, koneksi lambat,
 * dan submit ganda. Baseline kekhawatiran owner sebelum deploy.
 */
class TripResilienceTest extends TestCase
{
    use RefreshDatabase;

    protected User $operator;
    protected Cluster $cluster;
    protected ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = User::factory()->create(['role' => 'operator', 'is_active' => true]);
        $this->cluster = Cluster::create(['name' => 'Cluster Resilience']);
        $this->variant = ProductVariant::factory()->create(['is_active' => true, 'sale_price_per_pack' => 12000]);
    }

    protected function makeActiveTrip(): Trip
    {
        return Trip::factory()->create([
            'operator_id' => $this->operator->id,
            'starting_cluster_id' => $this->cluster->id,
            'qty_carried_total' => 50,
            'started_at' => now(),
            'ended_at' => null,
            'trip_date' => today()->format('Y-m-d'),
        ]);
    }

    /**
     * Operator tutup browser saat trip aktif → buka /trip/start lagi →
     * harus diarahkan kembali ke trip aktif yang sama, bukan form trip baru.
     */
    public function test_start_trip_mount_redirects_to_existing_active_trip(): void
    {
        $trip = $this->makeActiveTrip();

        $this->actingAs($this->operator);

        Livewire::test(StartTrip::class)
            ->assertRedirect(route('operator.trip.active', $trip->id));
    }

    /**
     * Tanpa trip aktif, halaman mulai trip tampil normal (tidak redirect).
     */
    public function test_start_trip_mount_without_active_trip_shows_form(): void
    {
        $this->actingAs($this->operator);

        Livewire::test(StartTrip::class)
            ->assertNoRedirect()
            ->assertStatus(200);
    }

    /**
     * Trip yang sudah diakhiri tidak dianggap aktif: operator bisa mulai trip baru.
     */
    public function test_start_trip_mount_ignores_ended_trip(): void
    {
        Trip::factory()->create([
            'operator_id' => $this->operator->id,
            'started_at' => now()->subHours(3),
            'ended_at' => now()->subHour(),
            'trip_date' => today()->format('Y-m-d'),
        ]);

        $this->actingAs($this->operator);

        Livewire::test(StartTrip::class)->assertNoRedirect();
    }

    /**
     * Submit ganda dalam jeda singkat (request retry / klik tembus) tidak boleh
     * menciptakan KioskVisit + Delivery duplikat. Guard idempotensi server-side.
     */
    public function test_save_visit_double_submission_does_not_duplicate(): void
    {
        $trip = $this->makeActiveTrip();
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id]);

        $this->actingAs($this->operator);

        // Request pertama sudah tersimpan (respons hilang di jalan karena koneksi putus)
        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->set('dropBaru', 5)
            ->call('saveVisit')
            ->assertHasNoErrors();

        // Request kedua datang dengan state yang sama (retry)
        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->set('dropBaru', 5)
            ->call('saveVisit')
            ->assertHasNoErrors();

        $this->assertEquals(1, KioskVisit::where('trip_id', $trip->id)->where('kiosk_id', $kiosk->id)->count());
        $this->assertEquals(1, Delivery::where('trip_id', $trip->id)->where('kiosk_id', $kiosk->id)->count());
    }

    /**
     * Kunjungan ulang yang SAH ke kios yang sama (selang waktu lebih lama,
     * mis. balik lagi karena kios tutup tadi) tetap diperbolehkan.
     */
    public function test_save_visit_legit_revisit_after_interval_is_allowed(): void
    {
        $trip = $this->makeActiveTrip();
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id]);

        KioskVisit::create([
            'trip_id' => $trip->id,
            'kiosk_id' => $kiosk->id,
            'visited_at' => now()->subMinutes(30),
            'visit_action' => 'check_only',
            'extension_granted' => false,
        ]);

        $this->actingAs($this->operator);

        Livewire::test(ActiveTrip::class)
            ->call('openVisitModal', $kiosk->id)
            ->set('dropBaru', 3)
            ->call('saveVisit')
            ->assertHasNoErrors();

        $this->assertEquals(2, KioskVisit::where('trip_id', $trip->id)->where('kiosk_id', $kiosk->id)->count());
    }

    /**
     * Guard idempotensi juga melindungi kios cash only.
     */
    public function test_save_visit_cash_only_double_submission_does_not_duplicate(): void
    {
        $trip = $this->makeActiveTrip();
        $kiosk = Kiosk::factory()->create(['cluster_id' => $this->cluster->id, 'is_cash_only' => true]);

        $this->actingAs($this->operator);

        foreach ([1, 2] as $attempt) {
            Livewire::test(ActiveTrip::class)
                ->call('openVisitModal', $kiosk->id)
                ->set('dropBaru', 4)
                ->call('saveVisit')
                ->assertHasNoErrors();
        }

        $this->assertEquals(1, KioskVisit::where('trip_id', $trip->id)->where('kiosk_id', $kiosk->id)->count());
        $this->assertEquals(1, Delivery::where('trip_id', $trip->id)->where('kiosk_id', $kiosk->id)->count());
    }

    /**
     * Akhiri trip dipanggil 2x (double click / retry) → hanya 1 komisi tercatat,
     * panggilan kedua ditolak dengan pesan, bukan duplikat diam-diam.
     */
    public function test_confirm_end_trip_twice_creates_single_commission(): void
    {
        $trip = $this->makeActiveTrip();

        $this->actingAs($this->operator);

        Livewire::test(ActiveTrip::class)
            ->call('openEndTripModal')
            ->set('endReason', 'target_done')
            ->call('confirmEndTrip')
            ->assertHasNoErrors();

        // Request kedua (komponen segar, trip sudah berakhir di DB)
        $this->assertNotNull($trip->fresh()->ended_at);
        $this->assertEquals(1, Commission::where('trip_id', $trip->id)->count());

        // Komponen baru di-mount setelah trip berakhir → redirect ke dashboard,
        // tidak mungkin memanggil confirmEndTrip lagi.
        Livewire::test(ActiveTrip::class)
            ->assertRedirect(route('operator.dashboard'));

        $this->assertEquals(1, Commission::where('trip_id', $trip->id)->count());
    }
}
