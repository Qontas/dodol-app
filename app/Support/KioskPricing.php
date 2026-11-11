<?php

namespace App\Support;

use App\Models\Kiosk;
use App\Models\PriceTier;
use App\Models\ProductVariant;
use Illuminate\Validation\ValidationException;

/** Live pricing for NEW deliveries only; historical delivery snapshots stay authoritative. */
class KioskPricing
{
    public static function pricePerPack(Kiosk $kiosk, ProductVariant $variant): float
    {
        $ownerId = $variant->product?->owner_id;
        $kioskOwnerId = $kiosk->cluster?->owner_id;
        $tier = $kiosk->price_tier_id === null ? null : $kiosk->priceTier;
        // Inactive tiers already assigned remain valid; only NEW selections are blocked.
        if ($tier && (int) $tier->product_variant_id === (int) $variant->id
            && $tier->owner_id === $ownerId && $kioskOwnerId === $ownerId) {
            return (float) $tier->sale_price_per_pack;
        }

        $default = $variant->defaultPriceTier;
        if ($default && $default->owner_id === $ownerId && $default->is_active) {
            return (float) $default->sale_price_per_pack;
        }

        // Legacy/unsaved fixtures without tiers only. Persisted variants get Standar automatically.
        return (float) $variant->sale_price_per_pack;
    }

    public static function activeVariantForOwner(?int $ownerId): ?ProductVariant
    {
        return ProductVariant::query()->where('is_active', true)
            ->whereHas('product', fn ($query) => $query->where('owner_id', $ownerId))
            ->with(['product', 'defaultPriceTier'])->orderBy('id')->first();
    }

    public static function validateTier(?int $tierId, ?int $ownerId, ?int $variantId = null): void
    {
        if ($tierId === null) {
            return;
        }
        $valid = PriceTier::query()->whereKey($tierId)->where('owner_id', $ownerId)
            ->where('is_active', true)
            ->when($variantId !== null, fn ($query) => $query->where('product_variant_id', $variantId))
            ->whereHas('productVariant.product', fn ($query) => $query->where('owner_id', $ownerId))
            ->exists();
        if (! $valid) {
            throw ValidationException::withMessages(['price_tier_id' => 'Pilih tingkatan harga aktif milik usaha dan varian yang sesuai.']);
        }
    }

    public static function options(?int $ownerId, ?int $variantId = null): array
    {
        $variantId ??= static::activeVariantForOwner($ownerId)?->id;
        if ($variantId === null) {
            return [];
        }

        return PriceTier::query()->where('owner_id', $ownerId)->where('product_variant_id', $variantId)
            ->where('is_active', true)
            ->whereHas('productVariant.product', fn ($query) => $query->where('owner_id', $ownerId))
            ->with('productVariant')->orderByDesc('is_default')->orderBy('name')->get()
            ->mapWithKeys(fn (PriceTier $tier) => [$tier->id => static::label($tier)])->all();
    }

    public static function label(PriceTier $tier): string
    {
        $price = (float) $tier->sale_price_per_pack;
        $units = max(1, (int) $tier->productVariant?->units_per_pack);
        $rupiah = fn (float $amount): string => 'Rp '.number_format($amount, $amount == floor($amount) ? 0 : 2, ',', '.');

        return $tier->name.' — '.$rupiah($price).'/mika · '.$rupiah($price / $units).'/biji';
    }
}
