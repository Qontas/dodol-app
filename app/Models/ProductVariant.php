<?php

namespace App\Models;

use App\Models\Scopes\OwnerScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductVariant extends Model
{
    use HasFactory;

    /**
     * Global read scope multi-tenant: ProductVariant owner lewat rantai product
     * (whereHas product.owner_id). Secure-by-default — lihat OwnerScope. Menutup
     * temuan residual audit isolasi 6 Juli (ProductVariantScopeGapTest).
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new OwnerScope);

        static::created(function (self $variant): void {
            PriceTier::create([
                'product_variant_id' => $variant->id,
                'name' => 'Standar',
                'sale_price_per_pack' => $variant->sale_price_per_pack,
                'is_default' => true,
                'is_active' => true,
            ]);
        });
    }

    // A new variant and its initial default tier must commit (or roll back) together.
    public function save(array $options = [])
    {
        if (! $this->exists) {
            return \Illuminate\Support\Facades\DB::transaction(fn () => parent::save($options));
        }

        return parent::save($options);
    }

    protected $fillable = [
        'product_id',
        'name',
        'units_per_pack',
        'sale_price_per_pack',
        'cost_per_pack',
        'sku',
        'is_active',
    ];

    protected $casts = [
        'sale_price_per_pack' => 'decimal:2',
        'cost_per_pack' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    /**
     * Harga jual per BIJI, diturunkan dari sale_price_per_pack (harga per mika) ÷
     * units_per_pack — simetris dengan Delivery::getHargaPerBijiAttribute(), tapi
     * dibaca dari KONFIGURASI VARIAN SAAT INI (bukan snapshot), jadi hanya untuk
     * preview SEBELUM delivery dibuat (mis. modal Titip Cash BS). Delivery yang
     * SUDAH tersimpan harus selalu baca accessor Delivery sendiri, bukan ini.
     */
    public function getHargaPerBijiAttribute(): float
    {
        $units = max(1, (int) $this->units_per_pack);

        return (float) $this->sale_price_per_pack / $units;
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function priceTiers(): HasMany
    {
        return $this->hasMany(PriceTier::class);
    }

    public function defaultPriceTier(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(PriceTier::class)->where('is_default', true)->where('is_active', true);
    }

    public function procurementBatches(): HasMany
    {
        return $this->hasMany(ProcurementBatch::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }
}
