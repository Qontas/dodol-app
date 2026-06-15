<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cluster extends Model
{
    use BelongsToOwner, HasFactory;

    protected $fillable = [
        'owner_id',
        'name',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function kiosks(): HasMany
    {
        return $this->hasMany(Kiosk::class);
    }

    /**
     * Keluarkan cluster sentinel walk-in (induk kios sentinel) dari listing
     * & hitungan cluster milik owner.
     */
    public function scopeExcludeWalkInSentinel(Builder $query): Builder
    {
        return $query->where(
            $query->getModel()->getTable() . '.name',
            'not like',
            Kiosk::WALKIN_CLUSTER_PREFIX . '%'
        );
    }
}
