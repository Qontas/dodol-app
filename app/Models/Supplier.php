<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    use BelongsToOwner, HasFactory;

    protected $fillable = [
        'owner_id',
        'name',
        'phone',
        'relationship_type',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'relationship_type' => 'string',
        'is_active' => 'boolean',
    ];

    public function procurementBatches(): HasMany
    {
        return $this->hasMany(ProcurementBatch::class);
    }
}
