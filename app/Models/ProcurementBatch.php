<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProcurementBatch extends Model
{
    use BelongsToOwner, HasFactory;

    protected $fillable = [
        'owner_id',
        'product_variant_id',
        'supplier_id',
        'purchase_date',
        'qty_kg_raw',
        'qty_packs',
        'cost_raw_material',
        'cost_packing',
        'cost_packaging',
        'cost_other',
        'expiry_date',
        'notes',
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'expiry_date' => 'date',
        'qty_kg_raw' => 'decimal:2',
        'cost_raw_material' => 'decimal:2',
        'cost_packing' => 'decimal:2',
        'cost_packaging' => 'decimal:2',
        'cost_other' => 'decimal:2',
        'total_cost' => 'decimal:2',
        'cost_per_pack' => 'decimal:2',
        'price_per_kg_raw' => 'decimal:2',
    ];

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }

    public function getBatchNumberAttribute(): string
    {
        if (! $this->created_at || ! $this->id) {
            return '—';
        }

        $counter = self::whereDate('created_at', $this->created_at->toDateString())
            ->where('id', '<=', $this->id)
            ->count();

        return sprintf('BATCH-%s-%03d', $this->created_at->format('Y-m-d'), $counter);
    }

    public function getRemainingPacksAttribute(): int
    {
        // Kalau relasi deliveries SUDAH di-eager-load (dashboard: ->with('deliveries')),
        // pakai koleksi itu → 0 query (cegah N+1: dulu deliveries()->sum() nembak query tiap
        // dipanggil, 3x/batch lewat stok_tersisa/is_habis/is_hampir_habis). Kalau BELUM
        // di-load, query fresh via builder — JANGAN lazy-load-and-cache, supaya baca ulang
        // setelah nambah delivery di instance yang sama tetap akurat (perilaku lama utuh).
        $delivered = $this->relationLoaded('deliveries')
            ? (int) $this->deliveries->sum('qty_delivered')
            : (int) $this->deliveries()->sum('qty_delivered');

        return max(0, (int) $this->qty_packs - $delivered);
    }

    /**
     * Stok tersisa = qty_packs - total mika yang sudah di-drop dari batch ini.
     * Alias eksplisit untuk fitur batch stok tracking (sama dengan remaining_packs).
     */
    public function getStokTersisaAttribute(): int
    {
        return $this->remaining_packs;
    }

    public function getIsHabisAttribute(): bool
    {
        return $this->stok_tersisa <= 0;
    }

    public function getIsHampisHabisAttribute(): bool
    {
        return $this->stok_tersisa > 0 && $this->stok_tersisa <= 10;
    }
}
