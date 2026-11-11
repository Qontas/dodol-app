<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * BRIEF 11 Nov 2026 "PERFORMA MELAMBAT" item 2 — dataset seukuran produksi untuk AUDIT
 * PERFORMA lokal (scripts/perf-audit.php). BUKAN seeder produksi dan BUKAN bagian dari
 * DatabaseSeeder.
 *
 * PENGAMAN: menolak jalan kecuali nama database berakhiran `_perf_db`, supaya tidak
 * pernah menimpa database dev (`cemilan_qontas_db`) atau test.
 *
 *   mysql: CREATE DATABASE cemilan_qontas_perf_db;
 *   DB_DATABASE=cemilan_qontas_perf_db php artisan migrate:fresh --force
 *   DB_DATABASE=cemilan_qontas_perf_db php artisan db:seed --class=PerfDatasetSeeder --force
 *
 * Isi (acak tapi deterministik, mt_srand tetap):
 *   - 3 owner: A = 300 kios / 6 area / 3 operator / 2 trip per hari,
 *              B = 80 kios / 2 area / 1 operator, C = 20 kios / 1 operator.
 *   - 60 hari trip selesai; tiap kunjungan = 1 delivery baru + pelunasan titipan
 *     sebelumnya di kios itu (settlement). Titipan terakhir tiap kios dibiarkan pending.
 *   - Trip BERJALAN hari ini (op A1), trip kemarin BELUM idle 6 jam (op A2),
 *     trip lupa diakhiri sudah idle lama (op A3 & op B1) — memicu auto-close.
 *   - ±100 kios owner A punya titipan saldo-awal (trip sentinel migrasi).
 *
 * Semua akun password: "password". Email: perf.{owner|op}.* @perf.test
 */
class PerfDatasetSeeder extends Seeder
{
    private const PRICE = 12000;

    private const COST = 9500;

    private const PER_PACK = 15;

    public function run(): void
    {
        $db = DB::connection()->getDatabaseName();
        if (! str_ends_with((string) $db, '_perf_db')) {
            throw new \RuntimeException("PerfDatasetSeeder menolak jalan di database '{$db}' (harus berakhiran _perf_db).");
        }

        mt_srand(20261111);
        $now = Carbon::now();
        $hash = bcrypt('password');

        $summary = [];

        DB::transaction(function () use ($now, $hash, &$summary) {
            // super admin
            User::create([
                'name' => 'Perf Admin', 'email' => 'perf.admin@perf.test', 'password' => $hash,
                'role' => 'super_admin', 'is_active' => true, 'email_verified_at' => $now,
            ]);

            $specs = [
                'A' => ['kios' => 300, 'areas' => 6, 'ops' => 3, 'tripsPerDay' => 2, 'visits' => 25],
                'B' => ['kios' => 80, 'areas' => 2, 'ops' => 1, 'tripsPerDay' => 1, 'visits' => 18],
                'C' => ['kios' => 20, 'areas' => 1, 'ops' => 1, 'tripsPerDay' => 1, 'visits' => 8],
            ];

            foreach ($specs as $key => $spec) {
                $summary[$key] = $this->seedOwner($key, $spec, $now, $hash);
            }
        });

        $this->command?->info('Perf dataset: '.json_encode($summary));
    }

    private function seedOwner(string $key, array $spec, Carbon $now, string $hash): array
    {
        $lower = strtolower($key);

        $owner = User::create([
            'name' => "Perf Owner {$key}", 'email' => "perf.owner.{$lower}@perf.test", 'password' => $hash,
            'role' => 'owner', 'hpp_per_mika' => self::COST, 'harga_mika' => self::PRICE,
            'komisi_per_mika' => 1000, 'app_fee_per_pack' => 500, 'is_active' => true,
            'email_verified_at' => $now,
        ]);

        $ops = [];
        for ($i = 1; $i <= $spec['ops']; $i++) {
            $ops[$i] = User::create([
                'name' => "Operator {$key}{$i}", 'email' => "perf.op.{$lower}{$i}@perf.test", 'password' => $hash,
                'role' => 'operator', 'owner_id' => $owner->id, 'is_active' => true,
                'email_verified_at' => $now,
            ]);
        }
        $sentinelOp = User::create([
            'name' => "Operator Migrasi {$key}", 'email' => User::SYSTEM_OPERATOR_EMAIL_PREFIX."{$lower}@perf.test",
            'password' => $hash, 'role' => 'operator', 'owner_id' => $owner->id, 'is_active' => true,
            'email_verified_at' => $now,
        ]);

        $product = Product::create(['name' => 'Dodol', 'owner_id' => $owner->id]);
        // created-hook ProductVariant membuat tier "Standar" otomatis.
        $variant = ProductVariant::create([
            'product_id' => $product->id, 'name' => 'Mika 15', 'units_per_pack' => self::PER_PACK,
            'sale_price_per_pack' => self::PRICE, 'cost_per_pack' => self::COST, 'is_active' => true,
        ]);
        $supplierId = DB::table('suppliers')->insertGetId([
            'name' => "Supplier {$key}", 'owner_id' => $owner->id, 'created_at' => $now, 'updated_at' => $now,
        ]);

        // Batch stok: satu per 10 hari (6 batch).
        $batchIds = [];
        for ($b = 0; $b < 6; $b++) {
            $batchIds[] = DB::table('procurement_batches')->insertGetId([
                'owner_id' => $owner->id, 'product_variant_id' => $variant->id, 'supplier_id' => $supplierId,
                'purchase_date' => $now->copy()->subDays(60 - $b * 10)->toDateString(),
                'qty_kg_raw' => 100, 'qty_packs' => 1500, 'cost_raw_material' => 9500 * 1500,
                'expiry_date' => $now->copy()->addDays(90)->toDateString(),
                'created_at' => $now->copy()->subDays(60 - $b * 10), 'updated_at' => $now,
            ]);
        }

        // Area + kios.
        $clusterIds = [];
        for ($a = 1; $a <= $spec['areas']; $a++) {
            $clusterIds[] = DB::table('clusters')->insertGetId([
                'owner_id' => $owner->id, 'name' => "Area {$key}{$a}", 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $kiosks = []; // id => cluster_id
        $kioskRows = [];
        for ($k = 1; $k <= $spec['kios']; $k++) {
            $cluster = $clusterIds[($k - 1) % count($clusterIds)];
            $kioskRows[] = [
                'name' => "Kedai {$key}{$k}", 'owner_name' => "Pemilik {$k}", 'cluster_id' => $cluster,
                'sort_order' => intdiv($k - 1, count($clusterIds)) + 1,
                'latitude' => -6.9 + mt_rand(0, 9999) / 100000, 'longitude' => 107.6 + mt_rand(0, 9999) / 100000,
                'default_qty_mika' => 5, 'first_titip_date' => $now->copy()->subDays(mt_rand(0, 120))->toDateString(),
                'is_active' => mt_rand(1, 100) <= 92, 'created_at' => $now, 'updated_at' => $now,
            ];
        }
        foreach (array_chunk($kioskRows, 100) as $chunk) {
            DB::table('kiosks')->insert($chunk);
        }
        $ids = DB::table('kiosks')->whereIn('cluster_id', $clusterIds)->orderBy('id')->get(['id', 'cluster_id', 'is_active']);
        $activeKiosks = [];
        foreach ($ids as $row) {
            $kiosks[$row->id] = $row->cluster_id;
            if ($row->is_active) {
                $activeKiosks[] = $row->id;
            }
        }

        // pendingDelivery per kios (id delivery terakhir yang belum dilunasi).
        $pending = [];
        $counts = ['trips' => 0, 'visits' => 0, 'deliveries' => 0, 'settlements' => 0];

        // Saldo-awal sentinel untuk ±1/3 kios (owner A saja yang besar).
        if ($key === 'A') {
            $sentTripId = DB::table('trips')->insertGetId([
                'owner_id' => $owner->id, 'trip_date' => '2000-01-01', 'trip_number_of_day' => 1,
                'operator_id' => $sentinelOp->id, 'started_at' => '2000-01-01 00:00:00',
                'ended_at' => '2000-01-01 00:00:00', 'ended_reason' => 'migrasi',
                'qty_carried_total' => 0, 'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach (array_slice($activeKiosks, 0, 100) as $kid) {
                $pending[$kid] = DB::table('deliveries')->insertGetId([
                    'kiosk_id' => $kid, 'trip_id' => $sentTripId, 'product_variant_id' => $variant->id,
                    'source_type' => 'migration', 'delivery_type' => 'consignment', 'qty_delivered' => 5,
                    'units_per_pack_snapshot' => self::PER_PACK, 'unit_price' => self::PRICE,
                    'cost_snapshot' => self::COST, 'created_at' => $now->copy()->subDays(61), 'updated_at' => $now,
                ]);
                $counts['deliveries']++;
            }
        }

        // 60 hari trip selesai (hari -60 .. -1), plus hari ini ditangani terpisah.
        for ($d = 60; $d >= 1; $d--) {
            $date = $now->copy()->subDays($d)->startOfDay();
            for ($t = 1; $t <= $spec['tripsPerDay']; $t++) {
                $op = $ops[(($t - 1 + $d) % $spec['ops']) + 1];
                $this->seedTrip(
                    $owner, $op, $variant->id, $batchIds[intdiv(60 - $d, 10) % 6], $date, $t,
                    $date->copy()->setTime(7 + ($t - 1) * 3, 0), true, $spec['visits'],
                    $activeKiosks, $pending, $counts
                );
            }
        }

        if ($key === 'A') {
            // Trip BERJALAN hari ini — op A1 (belum selesai, 12 kunjungan).
            $this->seedTrip($owner, $ops[1], $variant->id, $batchIds[5], $now->copy()->startOfDay(), 1,
                $now->copy()->subHours(3), false, 12, $activeKiosks, $pending, $counts);

            // Trip KEMARIN belum idle 6 jam — op A2 (aktivitas terakhir ±1 jam lalu).
            $this->seedTrip($owner, $ops[2], $variant->id, $batchIds[5], $now->copy()->subDay()->startOfDay(), 9,
                $now->copy()->subHours(4), false, 10, $activeKiosks, $pending, $counts, activityEnd: $now->copy()->subHour());

            // Trip LUPA DIAKHIRI sudah idle lama — op A3 (3 hari lalu) → auto-close saat dibuka.
            $this->seedTrip($owner, $ops[3], $variant->id, $batchIds[5], $now->copy()->subDays(3)->startOfDay(), 9,
                $now->copy()->subDays(3)->setTime(8, 0), false, 10, $activeKiosks, $pending, $counts);
        }
        if ($key === 'B') {
            // Trip lupa diakhiri milik owner B (2 hari lalu).
            $this->seedTrip($owner, $ops[1], $variant->id, $batchIds[5], $now->copy()->subDays(2)->startOfDay(), 9,
                $now->copy()->subDays(2)->setTime(8, 0), false, 8, $activeKiosks, $pending, $counts);
        }

        return ['owner' => $owner->email, 'kios' => count($kiosks)] + $counts;
    }

    private function seedTrip(
        User $owner, User $op, int $variantId, int $batchId, Carbon $date, int $number,
        Carbon $startedAt, bool $ended, int $visits, array $activeKiosks, array &$pending, array &$counts,
        ?Carbon $activityEnd = null,
    ): void {
        $endedAt = $ended ? $startedAt->copy()->addHours(5) : null;
        $tripId = DB::table('trips')->insertGetId([
            'owner_id' => $owner->id, 'trip_date' => $date->toDateString(), 'trip_number_of_day' => $number,
            'operator_id' => $op->id, 'started_at' => $startedAt, 'ended_at' => $endedAt,
            'ended_reason' => $ended ? 'stock_habis' : null, 'qty_carried_total' => $visits * 6,
            'komisi_per_mika_snapshot' => 1000, 'app_fee_per_pack_snapshot' => 500,
            'created_at' => $startedAt, 'updated_at' => $startedAt,
        ]);
        $counts['trips']++;

        $span = $activityEnd ? max(1, $startedAt->diffInMinutes($activityEnd)) : 280;
        $totalDrop = 0;

        for ($v = 0; $v < $visits; $v++) {
            $kid = $activeKiosks[mt_rand(0, count($activeKiosks) - 1)];
            $at = $startedAt->copy()->addMinutes((int) floor($v * $span / max(1, $visits)));

            $settledId = $pending[$kid] ?? null;
            $visitId = DB::table('kiosk_visits')->insertGetId([
                'trip_id' => $tripId, 'kiosk_id' => $kid, 'visited_at' => $at,
                'visit_action' => $settledId ? 'drop_and_settle' : 'drop_only',
                'settled_delivery_id' => $settledId, 'created_at' => $at, 'updated_at' => $at,
            ]);
            $counts['visits']++;

            if ($settledId) {
                $sold = mt_rand(50, 75);
                $due = (int) round($sold / self::PER_PACK * self::PRICE);
                DB::table('settlements')->insert([
                    'delivery_id' => $settledId, 'visit_date' => $at->toDateString(),
                    'qty_sold' => $sold, 'qty_returned_fresh' => 75 - $sold, 'amount_due' => $due,
                    'amount_paid' => $due, 'paid_at' => $at, 'status' => 'paid',
                    'created_at' => $at, 'updated_at' => $at,
                ]);
                $counts['settlements']++;
            }

            $deliveryId = DB::table('deliveries')->insertGetId([
                'kiosk_id' => $kid, 'trip_id' => $tripId, 'kiosk_visit_id' => $visitId,
                'product_variant_id' => $variantId, 'procurement_batch_id' => $batchId,
                'source_type' => 'new_procurement', 'delivery_type' => 'consignment', 'qty_delivered' => 5,
                'units_per_pack_snapshot' => self::PER_PACK, 'unit_price' => self::PRICE,
                'cost_snapshot' => self::COST, 'app_fee_per_pack_snapshot' => 500,
                'created_at' => $at, 'updated_at' => $at,
            ]);
            DB::table('kiosk_visits')->where('id', $visitId)->update(['new_delivery_id' => $deliveryId]);
            $pending[$kid] = $deliveryId;
            $counts['deliveries']++;
            $totalDrop += 5;
        }

        if ($ended) {
            DB::table('commissions')->insert([
                'trip_id' => $tripId, 'operator_id' => $op->id, 'cash_collected_reported' => $totalDrop * 9000,
                'margin_rate_assumed' => 1, 'commission_rate' => 0.2, 'status' => 'paid',
                'paid_at' => $endedAt, 'created_at' => $endedAt, 'updated_at' => $endedAt,
            ]);
        }
    }
}
