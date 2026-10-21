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
 * BRIEF 2 Section 2 — trips:backfill-komisi-snapshot TIDAK BOLEH mengubah satu rupiah
 * pun dari angka Ismi yang sudah berjalan (kode kalkulasi Trip BELUM diubah di commit
 * ini — Trip::getKomisiPerMika() masih baca owner->komisi_per_mika live). Test ini
 * mengonfirmasi backfill murni menulis kolom baru tanpa mengganggu angka tampil.
 */
class TripsBackfillKomisiSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private const SNAPSHOT_PATH = 'storage/app/locked-numbers/owner-2.json';

    protected function tearDown(): void
    {
        File::delete(base_path(self::SNAPSHOT_PATH));
        parent::tearDown();
    }

    public function test_backfill_fills_null_snapshot_with_owner_current_rate_and_leaves_numbers_unchanged(): void
    {
        [$owner, $trip] = $this->seedLegacyIsmiScenario();

        $this->assertNull($trip->komisi_per_mika_snapshot);

        $saveExit = Artisan::call('reports:verify-locked-numbers', ['owner' => 2, '--save' => true]);
        $this->assertSame(0, $saveExit);

        $dryRunExit = Artisan::call('trips:backfill-komisi-snapshot', ['--owner' => 2, '--dry-run' => true]);
        $this->assertSame(0, $dryRunExit);
        $this->assertNull($trip->fresh()->komisi_per_mika_snapshot, 'Dry-run tidak boleh menyimpan apa pun.');

        $forceExit = Artisan::call('trips:backfill-komisi-snapshot', ['--owner' => 2, '--force' => true]);
        $this->assertSame(0, $forceExit);

        $trip->refresh();
        $this->assertEquals(1000, $trip->komisi_per_mika_snapshot, 'Snapshot diisi dgn tarif owner SAAT INI.');

        $diffExit = Artisan::call('reports:verify-locked-numbers', [
            'owner' => 2,
            '--before' => base_path(self::SNAPSHOT_PATH),
        ]);
        $this->assertSame(0, $diffExit, Artisan::output());
    }

    public function test_backfill_never_overwrites_existing_snapshot(): void
    {
        [, $trip] = $this->seedLegacyIsmiScenario();

        // Trip ini SUDAH punya snapshot (mis. dibuat setelah fase 2 StartTrip menulis
        // kolom ini) dengan tarif LAMA yang berbeda dari tarif owner saat ini (1000).
        // forceFill: komisi_per_mika_snapshot belum masuk $fillable Trip di commit ini
        // (ditambah di commit "kode hitung" fase 2) — set langsung spt yg command lakukan.
        $trip->forceFill(['komisi_per_mika_snapshot' => 500])->save();

        Artisan::call('trips:backfill-komisi-snapshot', ['--owner' => 2, '--force' => true]);

        $this->assertEquals(500, $trip->fresh()->komisi_per_mika_snapshot, 'Snapshot yang sudah ada TIDAK BOLEH ditimpa.');
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
