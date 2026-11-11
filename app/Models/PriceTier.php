<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use App\Models\Scopes\OwnerScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PriceTier extends Model
{
    use BelongsToOwner;

    protected $fillable = ['owner_id', 'product_variant_id', 'name', 'sale_price_per_pack', 'is_default', 'is_active'];

    protected $casts = [
        'sale_price_per_pack' => 'decimal:2',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    /** Serialize all tier writes on the parent variant, including default swaps. */
    public function save(array $options = [])
    {
        return DB::transaction(function () use ($options) {
            $variant = ProductVariant::withoutGlobalScope(OwnerScope::class)
                ->with('product')->lockForUpdate()->find($this->product_variant_id);
            $ownerId = $variant?->product?->owner_id;
            $activeOwner = OwnerScope::activeOwnerId();
            if (! $variant || ! $variant->product || ($activeOwner !== null && (int) $ownerId !== $activeOwner)) {
                throw ValidationException::withMessages(['product_variant_id' => 'Pilih varian milik usaha sendiri.']);
            }
            if ($this->exists && $this->isDirty(['product_variant_id', 'owner_id'])) {
                throw ValidationException::withMessages(['product_variant_id' => 'Varian dan pemilik tingkatan harga tidak dapat diubah.']);
            }
            if ($this->owner_id !== null && (int) $this->owner_id !== (int) $ownerId) {
                throw ValidationException::withMessages(['product_variant_id' => 'Pemilik tingkatan harga harus sama dengan pemilik varian.']);
            }
            if ($this->exists) {
                $fresh = static::withoutGlobalScope(OwnerScope::class)->lockForUpdate()->findOrFail($this->id);
                foreach (['is_default', 'is_active'] as $attribute) {
                    if (! $this->isDirty($attribute)) {
                        $this->setAttribute($attribute, $fresh->getAttribute($attribute));
                    }
                }
            }
            $this->owner_id = $ownerId;
            // Database defaults are not hydrated on a new Eloquent instance.
            $this->is_active = $this->is_active ?? true;
            $this->is_default = $this->is_default ?? false;
            if (! is_numeric($this->sale_price_per_pack) || (float) $this->sale_price_per_pack < 0) {
                throw ValidationException::withMessages(['sale_price_per_pack' => 'Harga harus berupa angka minimal 0.']);
            }
            if (trim((string) $this->name) === '') {
                throw ValidationException::withMessages(['name' => 'Nama tingkatan harga wajib diisi.']);
            }
            $tiers = static::withoutGlobalScope(OwnerScope::class)->where('product_variant_id', $variant->id);
            $currentDefault = (clone $tiers)->where('is_default', true)->lockForUpdate()->first();
            if ($this->is_default && ! $this->is_active) {
                throw ValidationException::withMessages(['is_active' => 'Harga default harus tetap aktif.']);
            }
            if (! $this->is_default && (! $currentDefault || ($this->exists && $currentDefault->is($this)))) {
                throw ValidationException::withMessages(['is_default' => 'Setiap varian wajib memiliki satu harga default. Jadikan harga lain sebagai default terlebih dahulu.']);
            }
            if ($this->is_default) {
                // Inside this transaction only. On failure the old default is restored.
                (clone $tiers)->when($this->exists, fn ($q) => $q->whereKeyNot($this->id))
                    ->where('is_default', true)->update(['is_default' => false, 'updated_at' => now()]);
            }

            if (! parent::save($options)) {
                throw ValidationException::withMessages(['is_default' => 'Perubahan harga dibatalkan; default sebelumnya tetap dipertahankan.']);
            }

            return true;
        });
    }

    public function delete()
    {
        return DB::transaction(function () {
            ProductVariant::withoutGlobalScope(OwnerScope::class)->whereKey($this->product_variant_id)->lockForUpdate()->first();
            $fresh = static::withoutGlobalScope(OwnerScope::class)->lockForUpdate()->find($this->id);
            $activeOwner = OwnerScope::activeOwnerId();
            if ($fresh && $activeOwner !== null && (int) $fresh->owner_id !== $activeOwner) {
                throw ValidationException::withMessages(['price_tier_id' => 'Tingkatan harga bukan milik usaha ini.']);
            }
            if ($fresh?->is_default) {
                throw ValidationException::withMessages(['is_default' => 'Harga default tidak dapat dihapus. Jadikan harga lain sebagai default terlebih dahulu.']);
            }

            return parent::delete();
        });
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function kiosks(): HasMany
    {
        return $this->hasMany(Kiosk::class);
    }
}
