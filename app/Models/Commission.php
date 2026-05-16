<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Commission extends Model
{
    use HasFactory;

    protected $fillable = [
        'trip_id',
        'operator_id',
        'cash_collected_reported',
        'margin_rate_assumed',
        'commission_rate',
        'status',
        'paid_at',
        'notes',
    ];

    protected $casts = [
        'cash_collected_reported' => 'decimal:2',
        'margin_rate_assumed' => 'decimal:4',
        'commission_rate' => 'decimal:4',
        'commission_amount' => 'decimal:2',
        'status' => 'string',
        'paid_at' => 'datetime',
    ];

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }
}
