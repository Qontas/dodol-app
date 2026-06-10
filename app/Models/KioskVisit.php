<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KioskVisit extends Model
{
    use HasFactory;

    protected $fillable = [
        'trip_id',
        'kiosk_id',
        'visited_at',
        'visit_action',
        'alasan_check',
        'sisa_biji',
        'new_delivery_id',
        'settled_delivery_id',
        'extension_granted',
        'notes',
    ];

    protected $casts = [
        'visited_at' => 'datetime',
        'visit_action' => 'string',
        'sisa_biji' => 'integer',
        'extension_granted' => 'boolean',
    ];

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function kiosk(): BelongsTo
    {
        return $this->belongsTo(Kiosk::class);
    }

    public function newDelivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class, 'new_delivery_id');
    }

    public function settledDelivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class, 'settled_delivery_id');
    }
}
