<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Satu sumber kebenaran untuk `owner:reset-data` — dipakai DRY-RUN
 * (summarize) DAN EKSEKUSI (execute) supaya angka "akan dihapus" tak
 * pernah bisa menyimpang dari angka "benar-benar terhapus".
 *
 * Sengaja pakai `DB::table(...)` mentah di seluruh kelas ini, BUKAN Eloquent:
 * - Tidak bergantung `OwnerScope`/konteks auth (command CLI biasanya tanpa
 *   auth()->check(), yang membuat OwnerScope bypass total — resolveScope()
 *   harus selalu benar terlepas dari siapa yang menjalankannya).
 * - `trips` otomatis ikut trip TERARSIP (SoftDeletes Eloquent hanya scope di
 *   level model; query `DB::table('trips')` tidak pernah menyaring
 *   `deleted_at`) — sengaja, karena reset ini harus menghapus trip terarsip
 *   milik owner juga, bukan hanya yang aktif.
 *
 * Urutan hapus di execute() adalah anak → induk eksplisit (lihat FK map &
 * urutan di plan) — TIDAK mengandalkan ON DELETE CASCADE dari `users`,
 * karena baris `users` milik owner (Aidil/Arnis) sengaja TIDAK pernah
 * disentuh (satu-satunya baris users yang dihapus: akun sistem
 * `operator.migrasi.owner{id}@...`, dan itu pun harus setelah trip & komisi
 * miliknya dihapus karena RESTRICT ke operator_id).
 */
class OwnerDataReset
{
    /**
     * Hitung seluruh kumpulan ID milik $ownerId — read-only, tidak menulis apa pun.
     *
     * @return array{
     *   cluster_ids: array<int>, kiosk_ids: array<int>, trip_ids: array<int>,
     *   product_ids: array<int>, variant_ids: array<int>, supplier_ids: array<int>,
     *   batch_ids: array<int>, delivery_ids: array<int>, settlement_ids: array<int>,
     *   operator_ids: array<int>, migrasi_user_id: int|null, photo_paths: array<string>,
     * }
     */
    public static function resolveScope(int $ownerId): array
    {
        $clusterIds = DB::table('clusters')->where('owner_id', $ownerId)->pluck('id')->all();
        $kioskIds = DB::table('kiosks')->whereIn('cluster_id', $clusterIds)->pluck('id')->all();

        // Query mentah → otomatis ikut trip TERARSIP (soft delete murni kolom Eloquent).
        $tripIds = DB::table('trips')->where('owner_id', $ownerId)->pluck('id')->all();

        $productIds = DB::table('products')->where('owner_id', $ownerId)->pluck('id')->all();
        $variantIds = DB::table('product_variants')->whereIn('product_id', $productIds)->pluck('id')->all();
        $supplierIds = DB::table('suppliers')->where('owner_id', $ownerId)->pluck('id')->all();
        $batchIds = DB::table('procurement_batches')->where('owner_id', $ownerId)->pluck('id')->all();

        $deliveryIds = DB::table('deliveries')
            ->where(function ($q) use ($tripIds, $kioskIds) {
                $q->whereIn('trip_id', $tripIds)->orWhereIn('kiosk_id', $kioskIds);
            })
            ->pluck('id')->all();

        $settlementIds = DB::table('settlements')->whereIn('delivery_id', $deliveryIds)->pluck('id')->all();

        // Semua user milik owner ini: operator biasa (mis. Arnis) + akun sistem
        // operator.migrasi — dipakai untuk scope fuel_logs.operator_id.
        $operatorIds = DB::table('users')->where('owner_id', $ownerId)->pluck('id')->all();

        $migrasiUserId = DB::table('users')
            ->where('email', "operator.migrasi.owner{$ownerId}@cemilanqontas.id")
            ->value('id');

        // REPORT ONLY — tidak ada file R2 yang dihapus oleh kelas ini.
        $photoPaths = DB::table('kiosks')
            ->whereIn('id', $kioskIds)
            ->whereNotNull('photo_path')
            ->pluck('photo_path')->all();

        return [
            'cluster_ids' => $clusterIds,
            'kiosk_ids' => $kioskIds,
            'trip_ids' => $tripIds,
            'product_ids' => $productIds,
            'variant_ids' => $variantIds,
            'supplier_ids' => $supplierIds,
            'batch_ids' => $batchIds,
            'delivery_ids' => $deliveryIds,
            'settlement_ids' => $settlementIds,
            'operator_ids' => $operatorIds,
            'migrasi_user_id' => $migrasiUserId,
            'photo_paths' => $photoPaths,
        ];
    }

    /**
     * Ringkasan jumlah baris yang AKAN terhapus per tabel — dipakai dry-run
     * DAN ditampilkan lagi sebelum prompt konfirmasi eksekusi nyata.
     *
     * @return array<string, int|bool>
     */
    public static function summarize(int $ownerId): array
    {
        $scope = static::resolveScope($ownerId);

        $deliveryOriginsCount = DB::table('delivery_origins')
            ->where(function ($q) use ($scope) {
                $q->whereIn('delivery_id', $scope['delivery_ids'])
                    ->orWhereIn('settlement_id', $scope['settlement_ids']);
            })
            ->count();

        $kioskVisitsCount = DB::table('kiosk_visits')
            ->where(function ($q) use ($scope) {
                $q->whereIn('trip_id', $scope['trip_ids'])
                    ->orWhereIn('kiosk_id', $scope['kiosk_ids']);
            })
            ->count();

        return [
            'clusters' => count($scope['cluster_ids']),
            'kiosks' => count($scope['kiosk_ids']),
            'suppliers' => count($scope['supplier_ids']),
            'products' => count($scope['product_ids']),
            'product_variants' => count($scope['variant_ids']),
            'procurement_batches' => count($scope['batch_ids']),
            'trips' => count($scope['trip_ids']),
            'deliveries' => count($scope['delivery_ids']),
            'delivery_origins' => $deliveryOriginsCount,
            'settlements' => count($scope['settlement_ids']),
            'kiosk_visits' => $kioskVisitsCount,
            'commissions' => DB::table('commissions')->whereIn('trip_id', $scope['trip_ids'])->count(),
            'fuel_logs' => DB::table('fuel_logs')->whereIn('operator_id', $scope['operator_ids'])->count(),
            'migrasi_user_found' => $scope['migrasi_user_id'] !== null,
            'photo_paths_count' => count($scope['photo_paths']),
        ];
    }

    /**
     * Hapus permanen SEMUA data operasional milik $ownerId, dalam satu transaksi,
     * urutan anak → induk eksplisit (TIDAK mengandalkan cascade dari `users`).
     * Mengulang resolveScope() sendiri (bukan menerima hasil summarize() dari
     * pemanggil) supaya eksekusi selalu dihitung ulang tepat sebelum dihapus.
     *
     * Baris `users` milik owner & operator biasa (mis. Arnis) TIDAK PERNAH
     * disentuh — satu-satunya baris users yang dihapus: akun sistem
     * `operator.migrasi.owner{id}@...` (jika ada).
     *
     * @return array<string, int|bool> count baris yang BENAR-BENAR terhapus per tabel
     *                                 (key sama dengan summarize() untuk tabel bisnis).
     */
    public static function execute(int $ownerId): array
    {
        return DB::transaction(function () use ($ownerId) {
            $scope = static::resolveScope($ownerId);

            // 1. delivery_origins (anak settlements & deliveries)
            $deliveryOrigins = DB::table('delivery_origins')
                ->where(function ($q) use ($scope) {
                    $q->whereIn('delivery_id', $scope['delivery_ids'])
                        ->orWhereIn('settlement_id', $scope['settlement_ids']);
                })
                ->delete();

            // 2. commissions (RESTRICT operator_id → users; harus sebelum hapus migrasi user)
            $commissions = DB::table('commissions')->whereIn('trip_id', $scope['trip_ids'])->delete();

            // 3. settlements (anak deliveries)
            $settlements = DB::table('settlements')->whereIn('id', $scope['settlement_ids'])->delete();

            // 4. kiosk_visits (anak trips & kiosks)
            $kioskVisits = DB::table('kiosk_visits')
                ->where(function ($q) use ($scope) {
                    $q->whereIn('trip_id', $scope['trip_ids'])
                        ->orWhereIn('kiosk_id', $scope['kiosk_ids']);
                })
                ->delete();

            // 5. deliveries (setelah 1,3,4 — anaknya sudah bersih)
            $deliveries = DB::table('deliveries')->whereIn('id', $scope['delivery_ids'])->delete();

            // 6. procurement_batches (setelah 5 — deliveries RESTRICT ke procurement_batch_id)
            $procurementBatches = DB::table('procurement_batches')->where('owner_id', $ownerId)->delete();

            // 7. trips (mentah → termasuk yang terarsip; sebelum 14 — RESTRICT operator_id)
            $trips = DB::table('trips')->where('owner_id', $ownerId)->delete();

            // 8. kiosks (setelah 4 & 5, supaya count eksplisit benar; setelah 7 untuk keamanan)
            $kiosks = DB::table('kiosks')->whereIn('id', $scope['kiosk_ids'])->delete();

            // 9. product_variants (setelah 5 & 6 — RESTRICT dari deliveries & procurement_batches)
            $variants = DB::table('product_variants')->whereIn('id', $scope['variant_ids'])->delete();

            // 10. products (setelah 9 — RESTRICT product_variants.product_id)
            $products = DB::table('products')->where('owner_id', $ownerId)->delete();

            // 11. suppliers (setelah 6 — RESTRICT procurement_batches.supplier_id)
            $suppliers = DB::table('suppliers')->where('owner_id', $ownerId)->delete();

            // 12. clusters (setelah 8 — RESTRICT kiosks.cluster_id)
            $clusters = DB::table('clusters')->where('owner_id', $ownerId)->delete();

            // 13. fuel_logs (SET NULL nullable, urutan bebas — sekalian dibersihkan)
            $fuelLogs = DB::table('fuel_logs')->whereIn('operator_id', $scope['operator_ids'])->delete();

            // 14. users — HANYA akun sistem operator.migrasi.owner{id}@... (setelah 2 & 7)
            $migrasiUserDeleted = $scope['migrasi_user_id']
                ? (bool) DB::table('users')->where('id', $scope['migrasi_user_id'])->delete()
                : false;

            return [
                'clusters' => $clusters,
                'kiosks' => $kiosks,
                'suppliers' => $suppliers,
                'products' => $products,
                'product_variants' => $variants,
                'procurement_batches' => $procurementBatches,
                'trips' => $trips,
                'deliveries' => $deliveries,
                'delivery_origins' => $deliveryOrigins,
                'settlements' => $settlements,
                'kiosk_visits' => $kioskVisits,
                'commissions' => $commissions,
                'fuel_logs' => $fuelLogs,
                'migrasi_user_found' => $migrasiUserDeleted,
                'photo_paths_count' => count($scope['photo_paths']),
            ];
        });
    }
}
