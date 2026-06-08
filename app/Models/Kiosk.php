<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Kiosk extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'owner_name',
        'phone',
        'cluster_id',
        'target_visit_interval_days',
        'warning_visit_interval_days',
        'location_description',
        'latitude',
        'longitude',
        'photo_path',
        'default_qty_mika',
        'first_titip_date',
        'is_active',
        'is_cash_only',
        'notes',
    ];

    protected $casts = [
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'first_titip_date' => 'date',
        'is_active' => 'boolean',
        'is_cash_only' => 'boolean',
    ];

    public function cluster(): BelongsTo
    {
        return $this->belongsTo(Cluster::class);
    }

    /**
     * Owner kios diketahui lewat cluster (Level 2 — tidak punya kolom owner_id).
     */
    public function getOwnerIdAttribute(): ?int
    {
        return $this->cluster?->owner_id;
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(KioskVisit::class);
    }

    public function getMapsUrlAttribute(): ?string
    {
        if (is_null($this->latitude) || is_null($this->longitude)) {
            return null;
        }

        return "https://www.google.com/maps?q={$this->latitude},{$this->longitude}";
    }
}
