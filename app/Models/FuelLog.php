<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FuelLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'week_start',
        'week_end',
        'total_fuel_cost',
        'trip_count_snapshot',
        'operator_id',
        'notes',
    ];

    protected $casts = [
        'week_start' => 'date',
        'week_end' => 'date',
        'total_fuel_cost' => 'decimal:2',
        'cost_per_trip' => 'decimal:2',
    ];

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }
}
