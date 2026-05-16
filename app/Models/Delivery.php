<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Delivery extends Model
{
    use HasFactory;

    protected $table = 'deliveries';

    protected $fillable = [
        'kiosk_id',
        'trip_id',
        'product_variant_id',
        'procurement_batch_id',
        'source_type',
        'origin_settlement_id',
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

    public function originSettlement(): BelongsTo
    {
        return $this->belongsTo(Settlement::class, 'origin_settlement_id');
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
