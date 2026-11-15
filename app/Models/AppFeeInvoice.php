<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Invoice fee aplikasi TERBIT (snapshot beku). SENGAJA tanpa BelongsToOwner/OwnerScope:
 * model ini hanya boleh disentuh super admin (route/resource/aksi semuanya digerbang
 * super admin), owner & operator tak punya jalur apa pun ke sini.
 */
class AppFeeInvoice extends Model
{
    public const STATUS_ISSUED = 'issued';

    public const STATUS_PAID = 'paid';

    public const STATUS_VOID = 'void';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'due_date' => 'date',
            'paid_at' => 'date',
            'voided_at' => 'datetime',
            'total_mika' => 'integer',
            'total_fee' => 'float',
            'lines' => 'array',
            'issuer' => 'array',
            'billed_to' => 'array',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Aktif = belum dibatalkan (issued/paid). */
    public function scopeActive($query)
    {
        return $query->where('status', '<>', self::STATUS_VOID);
    }

    /** "Lewat jatuh tempo" = tampilan, BUKAN status DB: issued & due_date < hari ini. */
    public function isOverdue(): bool
    {
        return $this->status === self::STATUS_ISSUED && $this->due_date->lt(today());
    }
}
