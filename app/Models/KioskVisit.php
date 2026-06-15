<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'corrected_at',
        'correction_of_visit_id',
        'changed_default',
        'notes',
    ];

    protected $casts = [
        'visited_at' => 'datetime',
        'visit_action' => 'string',
        'sisa_biji' => 'integer',
        'extension_granted' => 'boolean',
        'corrected_at' => 'datetime',
        'changed_default' => 'boolean',
    ];

    /**
     * Hanya kunjungan yang BELUM dikoreksi. Dipakai semua agregat atas kiosk_visits
     * agar baris koreksi (audit trail) tidak double-count.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull($query->getModel()->getTable() . '.corrected_at');
    }

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

    /** Visit asli yang dikoreksi oleh visit ini (audit trail). */
    public function correctionOf(): BelongsTo
    {
        return $this->belongsTo(KioskVisit::class, 'correction_of_visit_id');
    }

    /** Semua delivery yang dibuat dalam visit ini (linkage deterministik). */
    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class, 'kiosk_visit_id');
    }
}
