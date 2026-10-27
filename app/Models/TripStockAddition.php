<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BRIEF 27 Okt 2026 "PENGAMAN STOK DI MOTOR" — tambahan stok mika yang dicatat
 * operator di TENGAH trip (fitur "➕ Tambah Stok di Motor"). trips.qty_carried_total
 * tetap = stok awal; baris di sini ditambahkan di atasnya. Lihat Trip::getStokDiMotor()
 * (satu sumber kebenaran stok efektif di motor).
 */
class TripStockAddition extends Model
{
    use HasFactory;

    protected $table = 'trip_stock_additions';

    // Tabel hanya punya created_at (dicatat sekali, tidak pernah diubah) — tak ada updated_at.
    const UPDATED_AT = null;

    protected $fillable = [
        'trip_id',
        'qty',
        'added_by',
    ];

    protected $casts = [
        'qty' => 'integer',
    ];

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }
}
