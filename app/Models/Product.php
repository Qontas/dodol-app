<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'category',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'category' => 'string',
        'is_active' => 'boolean',
    ];

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }
}
