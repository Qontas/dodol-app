<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Multi-tenant: model Level 1 yang punya kolom owner_id langsung.
 *
 * Auto-set owner_id saat create berdasarkan user yang login:
 * - owner    → owner_id = id-nya sendiri
 * - operator → owner_id = owner_id operator tsb
 * Super admin / tanpa auth (seeder, factory) tidak di-set otomatis.
 */
trait BelongsToOwner
{
    public static function bootBelongsToOwner(): void
    {
        static::creating(function ($model) {
            if ($model->owner_id !== null || ! auth()->check()) {
                return;
            }

            $user = auth()->user();

            if ($user->isOwner()) {
                $model->owner_id = $user->id;
            } elseif ($user->isOperator()) {
                $model->owner_id = $user->owner_id;
            }
        });
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
