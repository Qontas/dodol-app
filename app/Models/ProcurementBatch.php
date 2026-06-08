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
        $delivered = (int) $this->deliveries()->sum('qty_delivered');
        return max(0, (int) $this->qty_packs - $delivered);
    }
}
