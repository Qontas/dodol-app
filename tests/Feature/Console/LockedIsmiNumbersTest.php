<?php

namespace Tests\Feature\Console;

use App\Models\Cluster;
use App\Models\Delivery;
use App\Models\Kiosk;
use App\Models\KioskVisit;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Settlement;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 🔴 KUNCI: products:bootstrap-ismi + deliveries:backfill-snapshot TIDAK BOLEH
 * mengubah satu rupiah/mika pun dari angka Ismi yang sudah berjalan. Skenario ini
 * meniru keadaan nyata Ismi SEBELUM refactor: Product+ProductVariant sudah ada
 * (units=15, harga=12000) tapi cost_per_pack masih NULL, dan delivery lama punya
 * units_per_pack_snapshot/cost_snapshot NULL (kolom itu belum ada saat delivery
 * dibuat). Jalankan kedua command, lalu pastikan seluruh accessor Trip (dan
 * reports:verify-locked-numbers sendiri) melaporkan delta NOL.
 *
 * Test ini akan tetap relevan setelah Commit 4 (switch kalkulasi Trip/TripAggregator
 * membaca snapshot) — pada saat itu barulah test ini benar-benar menguji jalur baru;
 * untuk saat ini (Trip.php belum diubah) ia mengonfirmasi backfill tidak mengganggu
 * apa pun yang sudah berjalan.
 */
class LockedIsmiNumbersTest extends TestCase
{
    use RefreshDatabase;

    private const SNAPSHOT_PATH = 'storage/app/locked-numbers/owner-2.json';

    protected function tearDown(): void
    {
        File::delete(base_path(self::SNAPSHOT_PATH));
        parent::tearDown();
    }

    public function test_bootstrap_and_backfill_leave_trip_numbers_unchanged(): void
    {
        [$owner, $trip] = $this->seedLegacyIsmiScenario();

        // --- SEBELUM: simpan snapshot via command yang sama yang akan dipakai di produksi.
        $saveExit = Artisan::call('reports:verify-locked-numbers', ['owner' => 2, '--save' => true]);
        $this->assertSame(0, $saveExit);
        $this->assertFileExists(base_path(self::SNAPSHOT_PATH));

        $before = [
            'mika_terjual' => $trip->fresh()->mika_terjual,
            'omset_val' => $trip->fresh()->omset_val,
            'hpp_estimasi' => $trip->fresh()->hpp_estimasi,
            'untung_kotor' => $trip->fresh()->untung_kotor,
            'mika_komisi' => $trip->fresh()->mika_komisi,
            'komisi_rian' => $trip->fresh()->komisi_rian,
            'untung_bersih_owner' => $trip->fresh()->untung_bersih_owner,
        ];

        // --- JALANKAN migrasi data: bootstrap produk Ismi, lalu backfill snapshot delivery.
        $bootstrapExit = Artisan::call('products:bootstrap-ismi', ['--force' => true]);
        $this->assertSame(0, $bootstrapExit);

        $backfillExit = Artisan::call('deliveries:backfill-snapshot', ['--owner' => 2, '--force' => true]);
        $this->assertSame(0, $backfillExit);

        // --- SESUDAH: snapshot kolom baru benar-benar terisi (bukan no-op diam-diam).
        $variant = ProductVariant::withoutGlobalScopes()->whereHas('product', fn ($q) => $q->where('owner_id', 2))->first();
        $this->assertNotNull($variant->cost_per_pack);
        $this->assertEquals(9500, $variant->cost_per_pack);

        $delivery = $trip->deliveries()->first()->fresh();
        $this->assertSame(15, $delivery->units_per_pack_snapshot);
        $this->assertEquals(9500, $delivery->cost_snapshot);
        $this->assertEquals(12000, $delivery->unit_price); // tidak tertimpa — sudah terisi sebelumnya

        // --- KUNCI UTAMA: angka Trip yang sama, delta 0.
        $trip->refresh();
        $after = [
            'mika_terjual' => $trip->mika_terjual,
            'omset_val' => $trip->omset_val,
            'hpp_estimasi' => $trip->hpp_estimasi,
            'untung_kotor' => $trip->untung_kotor,
            'mika_komisi' => $trip->mika_komisi,
            'komisi_rian' => $trip->komisi_rian,
            'untung_bersih_owner' => $trip->untung_bersih_owner,
        ];

        foreach ($before as $metric => $value) {
            $this->assertEqualsWithDelta($value, $after[$metric], 0.0001, "Metrik {$metric} bergeser setelah backfill — TIDAK BOLEH.");
        }

        // --- Verifikasi lewat alat resminya sendiri juga melaporkan IDENTIK (exit 0).
        $diffExit = Artisan::call('reports:verify-locked-numbers', [
            'owner' => 2,
            '--before' => base_path(self::SNAPSHOT_PATH),
        ]);
        $this->assertSame(0, $diffExit, Artisan::output());
    }

    public function test_bootstrap_is_idempotent_and_never_overwrites_existing_price(): void
    {
        [$owner] = $this->seedLegacyIsmiScenario();

        Artisan::call('products:bootstrap-ismi', ['--force' => true]);
        $variant = ProductVariant::withoutGlobalScopes()->whereHas('product', fn ($q) => $q->where('owner_id', 2))->first();
        $this->assertEquals(9500, $variant->cost_per_pack);
        $this->assertEquals(15, $variant->units_per_pack);
        $this->assertEquals(12000, $variant->sale_price_per_pack);

        // Ubah hpp_per_mika owner SETELAH bootstrap pertama — re-run tidak boleh
        // menimpa cost_per_pack yang sudah terisi (hanya mengisi bila NULL).
        $owner->update(['hpp_per_mika' => 7000]);
        Artisan::call('products:bootstrap-ismi', ['--force' => true]);

        $this->assertEquals(9500, $variant->fresh()->cost_per_pack);
    }

    /**
     * @return array{0: User, 1: Trip}
     */
    private function seedLegacyIsmiScenario(): array
    {
        // Owner HARUS id=2 — products:bootstrap-ismi hardcode OWNER_ID ke nilai ini
        // (sengaja, command ini spesifik untuk Ismi, bukan generik).
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

        // Keadaan PRA-REFACTOR nyata: product+variant SUDAH ada (units=15/harga=12000),
        // tapi cost_per_pack (kolom baru) masih NULL — persis temuan investigasi live.
        $product = Product::factory()->create(['owner_id' => $owner->id, 'name' => 'Dodol Coklat Susu']);
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'name' => 'Mika 15 biji',
            'units_per_pack' => 15,
            'sale_price_per_pack' => 12000,
            'cost_per_pack' => null,
        ]);

        $trip = Trip::create([
            'owner_id' => $owner->id,
            'operator_id' => $operator->id,
            'trip_date' => '2025-02-01',
            'trip_number_of_day' => 1,
            'started_at' => '2025-02-01 08:00:00',
            'ended_at' => '2025-02-01 17:00:00',
        ]);

        // Delivery LEGACY: units_per_pack_snapshot & cost_snapshot NULL (kolom belum
        // ada saat delivery ini dibuat); unit_price sudah terisi (selalu wajib sejak
        // migrasi awal — tidak pernah NULL secara skema).
        $delivery = Delivery::factory()->create([
            'kiosk_id' => $kiosk->id,
            'trip_id' => $trip->id,
            'product_variant_id' => $variant->id,
            'source_type' => 'new_procurement',
            'delivery_type' => 'consignment',
            'qty_delivered' => 5,
            'unit_price' => 12000,
            'cost_snapshot' => null,
            'units_per_pack_snapshot' => null,
        ]);

        KioskVisit::create([
            'trip_id' => $trip->id,
            'kiosk_id' => $kiosk->id,
            'visited_at' => '2025-02-01 10:00:00',
            'visit_action' => 'settle_only',
            'settled_delivery_id' => $delivery->id,
        ]);

        // 3 mika (45 biji) terjual, 2 mika (30 biji) kembali segar.
        Settlement::factory()->create([
            'delivery_id' => $delivery->id,
            'visit_date' => '2025-02-01',
            'qty_sold' => 45,
            'qty_returned_fresh' => 30,
            'qty_returned_expired' => 0,
            'amount_due' => 36000,
            'amount_paid' => 36000,
        ]);

        return [$owner, $trip];
    }
}
