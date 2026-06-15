<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use InvalidArgumentException;

class Delivery extends Model
{
    use HasFactory;

    protected $table = 'deliveries';

    protected $fillable = [
        'kiosk_id',
        'trip_id',
        'kiosk_visit_id',
        'product_variant_id',
        'procurement_batch_id',
        'source_type',
        'delivery_type',
        'qty_delivered',
        'unit_price',
        'cost_snapshot',
        'notes',
    ];

    protected $casts = [
        'source_type' => 'string',
        'delivery_type' => 'string',
        'unit_price' => 'decimal:2',
        'cost_snapshot' => 'decimal:2',
    ];

    public function kiosk(): BelongsTo
    {
        return $this->belongsTo(Kiosk::class);
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function procurementBatch(): BelongsTo
    {
        return $this->belongsTo(ProcurementBatch::class);
    }

    public function originSettlements(): BelongsToMany
    {
        return $this->belongsToMany(Settlement::class, 'delivery_origins')
            ->withPivot('biji_count');
    }

    public function validateOrigins(): void
    {
        $origins = $this->originSettlements()->get();
        $sum = (int) $origins->sum(fn ($s) => $s->pivot->biji_count);
        $expected = (int) $this->qty_delivered * 15;
        $id = $this->id ?? 'new';

        if ($this->source_type === 'fresh_return_redeploy') {
            if ($origins->isEmpty()) {
                throw new InvalidArgumentException(
                    "Delivery #{$id} (fresh_return_redeploy) requires at least 1 delivery_origins row"
                );
            }
            if ($sum !== $expected) {
                throw new InvalidArgumentException(
                    "Delivery #{$id} (fresh_return_redeploy) sum(biji_count)={$sum} must equal qty_delivered*15={$expected}"
                );
            }
        }

        if ($this->source_type === 'new_procurement' && $origins->isNotEmpty()) {
            throw new InvalidArgumentException(
                "Delivery #{$id} (new_procurement) must not have delivery_origins rows"
            );
        }
    }

    public function settlement(): HasOne
    {
        return $this->hasOne(Settlement::class);
    }

    public function createdInVisit(): HasOne
    {
        return $this->hasOne(KioskVisit::class, 'new_delivery_id');
    }

    public function settledInVisit(): HasOne
    {
        return $this->hasOne(KioskVisit::class, 'settled_delivery_id');
    }
}
