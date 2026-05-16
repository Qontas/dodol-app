<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Settlement extends Model
{
    use HasFactory;

    protected $fillable = [
        'delivery_id',
        'visit_date',
        'qty_sold',
        'qty_returned_fresh',
        'qty_returned_expired',
        'amount_due',
        'amount_paid',
        'paid_at',
        'status',
        'notes',
    ];

    protected $casts = [
        'visit_date' => 'date',
        'paid_at' => 'datetime',
        'amount_due' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'status' => 'string',
    ];

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function redeployedDeliveries(): HasMany
    {
        return $this->hasMany(Delivery::class, 'origin_settlement_id');
    }
}
