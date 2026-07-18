<?php

namespace App\Support;

use App\Models\Delivery;
use App\Models\Kiosk;
use App\Models\ProductVariant;
use App\Models\Trip;

/**
 * SATU jalur pembuatan "titipan konsinyasi PENDING" (delivery tanpa settlement) untuk
 * sebuah (kios, trip). Dipakai bersama oleh:
 *   - OpeningBalance (owner Filament): trip = TRIP MIGRASI sentinel → saldo awal kedai LAMA.
 *   - CreateKiosk operator DALAM trip: trip = TRIP AKTIF → dodol yang benar-benar dibawa
 *     & diletakkan operator hari ini. Karena delivery nempel ke trip aktif, ia OTOMATIS
 *     ikut getTotalDropReal() trip itu → STOK trip berkurang & KOMISI operator terhitung
 *     (Rp/mika × qty), persis seperti drop di kios lain — tanpa jalur komisi terpisah.
 *
 * Komisi TIDAK ditulis sebagai record; ia DITURUNKAN dari deliveries trip (Trip::mika_komisi
 * = getTotalDropReal). Jadi cukup delivery ini mendarat di trip yang benar → komisi ikut.
 */
class ConsignmentDrop
{
    /**
     * Buat 1 delivery konsinyasi PENDING sejumlah $qtyMika untuk $kiosk pada $trip.
     * Return null bila qty < 1 atau owner kios tak punya varian produk aktif (caller
     * memutuskan apakah itu fatal — untuk create-kiosk tidak fatal: kios tetap tersimpan).
     */
    public static function record(Kiosk $kiosk, Trip $trip, int $qtyMika, ?string $notes = null): ?Delivery
    {
        if ($qtyMika < 1) {
            return null;
        }

        $ownerId = $kiosk->cluster?->owner_id;

        $variant = ProductVariant::query()
            ->where('is_active', true)
            ->when($ownerId !== null, fn ($q) => $q->whereHas('product', fn ($p) => $p->where('owner_id', $ownerId)))
            ->first();

        if (! $variant) {
            return null; // tak ada varian aktif → tak bisa buat titipan
        }

        return Delivery::create([
            'kiosk_id' => $kiosk->id,
            'trip_id' => $trip->id,
            'product_variant_id' => $variant->id,
            'procurement_batch_id' => null,
            'source_type' => 'new_procurement',
            'delivery_type' => 'consignment',
            'qty_delivered' => $qtyMika,
            'unit_price' => $variant->sale_price_per_pack,
            'cost_snapshot' => null,
            'notes' => $notes,
        ]);
    }
}
