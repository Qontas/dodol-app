<?php

namespace Tests\Feature\Console;

use App\Models\Cluster;
use App\Models\Delivery;
use App\Models\Kiosk;
use App\Models\ProductVariant;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * TUGAS 4d (BRIEF 23 Okt 2026) — trips:backfill-app-fee-snapshot TIDAK BOLEH
 * mengubah satu rupiah pun dari angka Ismi yang sudah berjalan (fee aplikasi
 * tidak disentuh di mana pun di luar widget admin). BEDA dari
 * trips:backfill-komisi-snapshot: --rate WAJIB diberikan eksplisit (fee aplikasi
 * adalah kesepakatan BARU, bukan tarif "sudah berlaku diam-diam").
 */
class TripsBackfillAppFeeSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private const SNAPSHOT_PATH = 'storage/app/locked-numbers/owner-2.json';

    protected function tearDown(): void
    {
        File::delete(base_path(self::SNAPSHOT_PATH));
        parent::tearDown();
    }

    public function test_requires_owner_and_rate_options(): void
    {
        $this->assertSame(1, Artisan::call('trips:backfill-app-fee-snapshot', []));
        $this->assertSame(1, Artisan::call('trips:backfill-app-fee-snapshot', ['--owner' => 2]));
        $this->assertSame(1, Artisan::call('trips:backfill-app-fee-snapshot', ['--owner' => 2, '--rate' => 'abc']));
        $this->assertSame(1, Artisan::call('trips:backfill-app-fee-snapshot', ['--owner' => 2, '--rate' => -5]));
    }

    public function test_dry_run_by_default_then_force_commits_without_touching_locked_numbers(): void
    {
        [, $trip] = $this->seedLegacyIsmiScenario();

        $this->assertNull($trip->app_fee_per_pack_snapshot);

        $saveExit = Artisan::call('reports:verify-locked-numbers', ['owner' => 2, '--save' => true]);
        $this->assertSame(0, $saveExit);

        $dryRunExit = Artisan::call('trips:backfill-app-fee-snapshot', ['--owner' => 2, '--rate' => 300]);
        $this->assertSame(0, $dryRunExit);
        $this->assertNull($trip->fresh()->app_fee_per_pack_snapshot, 'Dry-run (default) tidak boleh menyimpan apa pun.');

        $forceExit = Artisan::call('trips:backfill-app-fee-snapshot', ['--owner' => 2, '--rate' => 300, '--force' => true]);
        $this->assertSame(0, $forceExit);

        $trip->refresh();
        $this->assertEquals(300, $trip->app_fee_per_pack_snapshot, 'Snapshot diisi dari --rate, bukan tarif owner saat ini.');

        $diffExit = Artisan::call('reports:verify-locked-numbers', [
            'owner' => 2,
            '--before' => base_path(self::SNAPSHOT_PATH),
        ]);
        $this->assertSame(0, $diffExit, Artisan::output());
    }

    public function test_dry_run_flag_wins_over_force(): void
    {
        [, $trip] = $this->seedLegacyIsmiScenario();

        Artisan::call('trips:backfill-app-fee-snapshot', ['--owner' => 2, '--rate' => 300, '--force' => true, '--dry-run' => true]);

        $this->assertNull($trip->fresh()->app_fee_per_pack_snapshot, '--dry-run eksplisit menang atas --force.');
    }

    public function test_never_overwrites_existing_snapshot(): void
    {
        [, $trip] = $this->seedLegacyIsmiScenario();

        // Trip ini SUDAH punya snapshot (mis. dibuat setelah StartTrip mulai menulis
        // kolom ini) dengan tarif LAMA berbeda dari --rate yang diberikan sekarang.
        $trip->forceFill(['app_fee_per_pack_snapshot' => 150])->save();

        Artisan::call('trips:backfill-app-fee-snapshot', ['--owner' => 2, '--rate' => 300, '--force' => true]);

        $this->assertEquals(150, $trip->fresh()->app_fee_per_pack_snapshot, 'Snapshot yang sudah ada TIDAK BOLEH ditimpa.');
    }

    public function test_only_touches_the_given_owners_trips(): void
    {
        [, $tripOwnerA] = $this->seedLegacyIsmiScenario();

        $ownerB = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $operatorB = User::factory()->create(['role' => 'operator', 'owner_id' => $ownerB->id]);
        $tripOwnerB = Trip::create([
            'owner_id' => $ownerB->id,
            'operator_id' => $operatorB->id,
            'trip_date' => '2025-02-01',
            'trip_number_of_day' => 1,
        ]);

        Artisan::call('trips:backfill-app-fee-snapshot', ['--owner' => $tripOwnerA->owner_id, '--rate' => 300, '--force' => true]);

        $this->assertEquals(300, $tripOwnerA->fresh()->app_fee_per_pack_snapshot);
        $this->assertNull($tripOwnerB->fresh()->app_fee_per_pack_snapshot, 'Owner lain tidak boleh ikut ter-backfill.');
    }

    /**
     * @return array{0: User, 1: Trip}
     */
    private function seedLegacyIsmiScenario(): array
    {
        $owner = User::factory()->make([
            'email' => 'owner@cemilanqontas.id',
            'hpp_per_mika' => 9500,
            'komisi_per_mika' => 1000,
        ]);
        $owner->id = 2;
        $owner->save();

        $operator = User::factory()->create(['role' => 'operator', 'owner_id' => $owner->id]);
        $cluster = Cluster::factory()->create(['owner_id' => $owner->id]);
        $kiosk = Kiosk::factory()->create(['cluster_id' => $cluster->id]);
        $variant = ProductVariant::factory()->create(['is_active' => true]);

        $trip = Trip::create([
            'owner_id' => $owner->id,
            'operator_id' => $operator->id,
            'trip_date' => '2025-02-01',
            'trip_number_of_day' => 1,
            'started_at' => '2025-02-01 08:00:00',
            'ended_at' => '2025-02-01 17:00:00',
        ]);

        Delivery::factory()->create([
            'kiosk_id' => $kiosk->id,
            'trip_id' => $trip->id,
            'product_variant_id' => $variant->id,
            'delivery_type' => 'consignment',
            'qty_delivered' => 5,
        ]);

        return [$owner, $trip];
    }
}
