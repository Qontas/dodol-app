<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Trip extends Model
{
    use HasFactory;

    protected $fillable = [
        'trip_date',
        'trip_number_of_day',
        'operator_id',
        'started_at',
        'ended_at',
        'qty_carried_total',
        'notes',
    ];

    protected $casts = [
        'trip_date' => 'date',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(KioskVisit::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(Commission::class);
    }
}
