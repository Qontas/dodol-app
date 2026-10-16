<?php

namespace Tests\Feature\Console;

use App\Models\Cluster;
use App\Models\Commission;
use App\Models\Delivery;
use App\Models\FuelLog;
use App\Models\Kiosk;
use App\Models\KioskVisit;
use App\Models\ProcurementBatch;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Settlement;
use App\Models\Supplier;
use App\Models\Trip;
use App\Models\User;
use App\Support\OpeningBalance;
use App\Support\OwnerDataReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `owner:reset-data` — hapus permanen SEMUA data operasional satu owner.
 *
 * Wajib dibuktikan: dry-run tanpa efek, konfirmasi email yang SALAH membatalkan,
 * konfirmasi benar menghapus 12 tabel (+ delivery_origins + akun sistem migrasi)
 * milik owner itu TUNTAS, owner LAIN delta 0 di semua tabel, akun owner/operator
 * & kolom tarifnya tak tersentuh, sentinel walk-in & migrasi ikut hilang, dan
 * summarize() == execute() (satu sumber kebenaran, tak boleh menyimpang).
 */
class OwnerResetDataCommandTest extends TestCase
{
    use RefreshDatabase;

    /** Satu "dunia" tenant lengkap milik satu owner — dipakai utuh di 14 tabel. */
    private function world(string $tag): array
    {
        $owner = User::factory()->create([
            'role' => 'owner', 'is_active' => true, 'name' => "Owner $tag",
            'hpp_per_mika' => 9500, 'komisi_per_mika' => 1000,
        ]);
        $operator = User::factory()->create([
            'role' => 'operator', 'is_active' => true, 'name' => "Operator $tag", 'owner_id' => $owner->id,
        ]);
        $cluster = Cluster::create(['name' => "Area $tag", 'owner_id' => $owner->id, 'is_active' => true]);
        $kiosk = Kiosk::factory()->create([
            'cluster_id' => $cluster->id, 'name' => "Kios $tag", 'photo_path' => "kiosks/$tag/foto.jpg",
        ]);
        $supplier = Supplier::factory()->create(['owner_id' => $owner->id]);
        $product = Product::factory()->create(['owner_id' => $owner->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $batch = ProcurementBatch::factory()->create([
            'owner_id' => $owner->id, 'supplier_id' => $supplier->id, 'product_variant_id' => $variant->id,
        ]);
        $trip = Trip::factory()->create([
            'operator_id' => $operator->id, 'owner_id' => $owner->id, 'starting_cluster_id' => $cluster->id,
            'trip_date' => '2026-01-10', 'trip_number_of_day' => 1,
        ]);
        $delivery = Delivery::factory()->create([
            'kiosk_id' => $kiosk->id, 'trip_id' => $trip->id, 'product_variant_id' => $variant->id,
            'procurement_batch_id' => $batch->id, 'qty_delivered' => 1, // 1 mika = 15 biji, cocok dgn settlement qty_sold di bawah
        ]);
        $settlement = Settlement::create([
            'delivery_id' => $delivery->id, 'visit_date' => now()->toDateString(),
            'qty_sold' => 15, 'qty_returned_fresh' => 0, 'qty_returned_expired' => 0,
            'amount_due' => 60000, 'amount_paid' => 60000, 'status' => 'paid',
        ]);
        $visit = KioskVisit::create([
            'trip_id' => $trip->id, 'kiosk_id' => $kiosk->id, 'visited_at' => now(),
            'visit_action' => 'drop_and_settle', 'new_delivery_id' => $delivery->id,
            'settled_delivery_id' => $delivery->id, 'extension_granted' => false,
        ]);
        $commission = Commission::create([
            'trip_id' => $trip->id, 'operator_id' => $operator->id,
            'cash_collected_reported' => 60000.00, 'margin_rate_assumed' => 0.2500,
            'commission_rate' => 0.2000, 'status' => 'paid', 'paid_at' => now(),
        ]);
        // fuel_logs.unique(week_start, week_end) TIDAK per-operator (1 entri BBM/minggu
        // lintas tenant, lihat migration) — offset minggu per tag supaya world('A')/world('B')
        // tak bentrok unique index saat dipakai bersamaan dalam satu test.
        $weekOffset = ord($tag[0]) - ord('A');
        $fuelLog = FuelLog::create([
            'week_start' => now()->subWeeks($weekOffset)->startOfWeek()->toDateString(),
            'week_end' => now()->subWeeks($weekOffset)->endOfWeek()->toDateString(),
            'total_fuel_cost' => 50000, 'trip_count_snapshot' => 1, 'operator_id' => $operator->id,
        ]);
        DB::table('delivery_origins')->insert([
            'delivery_id' => $delivery->id, 'settlement_id' => $settlement->id, 'biji_count' => 15,
        ]);

        // Trip terarsip (soft-deleted) — dry-run & reset tetap harus menghitungnya.
        $archivedTrip = Trip::factory()->create([
            'operator_id' => $operator->id, 'owner_id' => $owner->id,
            'trip_date' => '2026-01-10', 'trip_number_of_day' => 2,
        ]);
        $archivedTrip->delete();

        // Sentinel walk-in (cluster + kiosk sistem) & sentinel migrasi (user + trip).
        $walkinKiosk = Kiosk::walkInSentinelFor($owner->id);
        $oldKiosk = Kiosk::factory()->create(['cluster_id' => $cluster->id, 'name' => "Kios Lama $tag"]);
        OpeningBalance::create($oldKiosk, 5);
        $migrasiUser = User::where('email', "operator.migrasi.owner{$owner->id}@cemilanqontas.id")->first();

        return compact(
            'owner', 'operator', 'cluster', 'kiosk', 'supplier', 'product', 'variant', 'batch',
            'trip', 'delivery', 'settlement', 'visit', 'commission', 'fuelLog', 'archivedTrip',
            'walkinKiosk', 'oldKiosk', 'migrasiUser',
        );
    }

    /** Hitungan raw-DB di 13 tabel operasional + akun migrasi — dibandingkan sebelum/sesudah. */
    private function rawCounts(int $ownerId): array
    {
        $clusterIds = DB::table('clusters')->where('owner_id', $ownerId)->pluck('id')->all();
        $kioskIds = DB::table('kiosks')->whereIn('cluster_id', $clusterIds)->pluck('id')->all();
        $tripIds = DB::table('trips')->where('owner_id', $ownerId)->pluck('id')->all();
        $productIds = DB::table('products')->where('owner_id', $ownerId)->pluck('id')->all();
        $variantIds = DB::table('product_variants')->whereIn('product_id', $productIds)->pluck('id')->all();
        $deliveryIds = DB::table('deliveries')
            ->where(fn ($q) => $q->whereIn('trip_id', $tripIds)->orWhereIn('kiosk_id', $kioskIds))
            ->pluck('id')->all();
        $settlementIds = DB::table('settlements')->whereIn('delivery_id', $deliveryIds)->pluck('id')->all();

        return [
            'clusters' => count($clusterIds),
            'kiosks' => count($kioskIds),
            'suppliers' => DB::table('suppliers')->where('owner_id', $ownerId)->count(),
            'products' => count($productIds),
            'product_variants' => count($variantIds),
            'procurement_batches' => DB::table('procurement_batches')->where('owner_id', $ownerId)->count(),
            'trips' => count($tripIds),
            'deliveries' => count($deliveryIds),
            'delivery_origins' => DB::table('delivery_origins')
                ->where(fn ($q) => $q->whereIn('delivery_id', $deliveryIds)->orWhereIn('settlement_id', $settlementIds))
                ->count(),
            'settlements' => count($settlementIds),
            'kiosk_visits' => DB::table('kiosk_visits')
                ->where(fn ($q) => $q->whereIn('trip_id', $tripIds)->orWhereIn('kiosk_id', $kioskIds))
                ->count(),
            'commissions' => DB::table('commissions')->whereIn('trip_id', $tripIds)->count(),
            'fuel_logs' => DB::table('fuel_logs')
                ->whereIn('operator_id', DB::table('users')->where('owner_id', $ownerId)->pluck('id'))
                ->count(),
            'migrasi_user' => DB::table('users')->where('email', "operator.migrasi.owner{$ownerId}@cemilanqontas.id")->count(),
        ];
    }

    public function test_dry_run_tanpa_force_tidak_mengubah_apa_apa(): void
    {
        $a = $this->world('A');
        $before = $this->rawCounts($a['owner']->id);

        $this->artisan('owner:reset-data', ['ownerId' => $a['owner']->id])
            ->assertSuccessful();

        $after = $this->rawCounts($a['owner']->id);

        foreach ($before as $table => $count) {
            $this->assertSame($count, $after[$table], "Dry-run mengubah tabel {$table}");
            $this->assertGreaterThan(0, $count, "Fixture '{$table}' kosong — test tidak menguji apa-apa");
        }
    }

    public function test_force_dengan_konfirmasi_salah_dibatalkan_tanpa_mutasi(): void
    {
        $a = $this->world('A');
        $before = $this->rawCounts($a['owner']->id);

        $this->artisan('owner:reset-data', ['ownerId' => $a['owner']->id, '--force' => true])
            ->expectsQuestion("Ketik ulang email owner ({$a['owner']->email}) untuk konfirmasi penghapusan PERMANEN", 'bukan-email-ini@salah.com')
            ->assertFailed();

        $this->assertSame($before, $this->rawCounts($a['owner']->id));
    }

    public function test_force_dengan_konfirmasi_benar_menghapus_tuntas_owner_a_tanpa_sentuh_owner_b(): void
    {
        $a = $this->world('A');
        $b = $this->world('B');
        $beforeB = $this->rawCounts($b['owner']->id);

        $this->artisan('owner:reset-data', ['ownerId' => $a['owner']->id, '--force' => true])
            ->expectsQuestion("Ketik ulang email owner ({$a['owner']->email}) untuk konfirmasi penghapusan PERMANEN", $a['owner']->email)
            ->assertSuccessful();

        // Owner A: NOL di seluruh tabel bisnis + akun migrasi terhapus.
        $afterA = $this->rawCounts($a['owner']->id);
        foreach ($afterA as $table => $count) {
            $this->assertSame(0, $count, "Owner A masih punya sisa di tabel {$table}");
        }

        // Owner B: delta NOL di semua tabel (tidak tersentuh sama sekali).
        $this->assertSame($beforeB, $this->rawCounts($b['owner']->id));

        // Akun owner A & operatornya (BUKAN migrasi) tetap ada, kolom tarif tak berubah.
        $this->assertDatabaseHas('users', ['id' => $a['owner']->id, 'email' => $a['owner']->email]);
        $this->assertDatabaseHas('users', [
            'id' => $a['owner']->id, 'hpp_per_mika' => 9500, 'komisi_per_mika' => 1000, 'is_active' => 1,
        ]);
        $this->assertDatabaseHas('users', ['id' => $a['operator']->id, 'email' => $a['operator']->email]);

        // Sentinel walk-in & migrasi ikut hilang (bukan cuma diasumsikan).
        $this->assertDatabaseMissing('clusters', ['id' => $a['walkinKiosk']->cluster_id]);
        $this->assertDatabaseMissing('kiosks', ['id' => $a['walkinKiosk']->id]);
        $this->assertDatabaseMissing('users', ['id' => $a['migrasiUser']->id]);
    }

    public function test_owner_id_bukan_role_owner_ditolak(): void
    {
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true]);

        $this->artisan('owner:reset-data', ['ownerId' => $operator->id, '--force' => true])
            ->assertFailed();

        $this->assertDatabaseHas('users', ['id' => $operator->id]);
    }

    public function test_setelah_reset_owner_bisa_buat_data_baru_tanpa_bentrok_unique(): void
    {
        $a = $this->world('A');
        $ownerId = $a['owner']->id;

        $this->artisan('owner:reset-data', ['ownerId' => $ownerId, '--force' => true])
            ->expectsQuestion("Ketik ulang email owner ({$a['owner']->email}) untuk konfirmasi penghapusan PERMANEN", $a['owner']->email)
            ->assertSuccessful();

        $newCluster = Cluster::create(['name' => 'Area Baru', 'owner_id' => $ownerId, 'is_active' => true]);
        $newKiosk = Kiosk::create(['name' => 'Kios Baru', 'cluster_id' => $newCluster->id, 'is_active' => true]);
        $this->assertNotNull($newKiosk->id);

        $owner = User::withoutGlobalScopes()->find($ownerId);
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $ownerId]);
        $newTrip = Trip::factory()->create([
            'owner_id' => $ownerId, 'operator_id' => $operator->id,
            'trip_date' => today()->toDateString(), 'trip_number_of_day' => 1,
        ]);
        $this->assertNotNull($newTrip->id);
        $this->assertNotNull($owner);
    }

    public function test_summarize_sama_dengan_execute_satu_sumber_kebenaran(): void
    {
        $a = $this->world('A');
        $ownerId = $a['owner']->id;

        $before = OwnerDataReset::summarize($ownerId);
        $after = OwnerDataReset::execute($ownerId);

        foreach ([
            'clusters', 'kiosks', 'suppliers', 'products', 'product_variants',
            'procurement_batches', 'trips', 'deliveries', 'delivery_origins',
            'settlements', 'kiosk_visits', 'commissions', 'fuel_logs',
        ] as $table) {
            $this->assertSame($before[$table], $after[$table], "summarize() != execute() pada {$table}");
            $this->assertGreaterThan(0, $before[$table], "Fixture '{$table}' kosong — test tidak menguji apa-apa");
        }

        $this->assertSame($before['migrasi_user_found'], $after['migrasi_user_found']);
        $this->assertSame($before['photo_paths_count'], $after['photo_paths_count']);
    }

    public function test_regresi_penuh_tetap_hijau(): void
    {
        // Sanity check command ini tidak merusak pola lain (jalankan satu skenario
        // penuh sebagai asap; suite lengkap dijalankan terpisah via `php artisan test`).
        $a = $this->world('A');

        $this->artisan('owner:reset-data', ['ownerId' => $a['owner']->id])->assertSuccessful();
        $this->assertDatabaseHas('trips', ['id' => $a['trip']->id]);
    }
}
