<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
        'fast_mover_threshold_days',
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
        'fast_mover_threshold_days' => 'integer',
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

    /**
     * Kunjungan check_only terakhir yang mencatat sisa biji di kios.
     */
    public function latestCheckVisit(): HasOne
    {
        return $this->hasOne(KioskVisit::class)
            ->where('visit_action', 'check_only')
            ->whereNotNull('sisa_biji')
            ->latestOfMany('visited_at');
    }

    /**
     * Prediksi kapan dodol di kios habis berdasarkan sisa_biji terakhir
     * dibagi rata-rata penjualan harian (historis settlement).
     * Butuh minimal 3 settlement; kalau kurang → "Data belum cukup".
     */
    public function getPrediksiHabisAttribute(): ?string
    {
        $check = $this->latestCheckVisit;

        if (! $check || ! $check->sisa_biji) {
            return null;
        }

        $base = Settlement::query()
            ->join('deliveries', 'settlements.delivery_id', '=', 'deliveries.id')
            ->where('deliveries.kiosk_id', $this->id)
            ->where('settlements.qty_sold', '>', 0);

        if ((clone $base)->count() < 3) {
            return 'Data belum cukup';
        }

        $avgPerHari = (clone $base)
            ->selectRaw('AVG(settlements.qty_sold / GREATEST(DATEDIFF(settlements.visit_date, deliveries.created_at), 1)) as avg_per_hari')
            ->value('avg_per_hari');

        if (! $avgPerHari || $avgPerHari <= 0) {
            return 'Data belum cukup';
        }

        $hariLagi = (int) ceil($check->sisa_biji / $avgPerHari);

        return "{$hariLagi} hari lagi";
    }

    public function getMapsUrlAttribute(): ?string
    {
        if (is_null($this->latitude) || is_null($this->longitude)) {
            return null;
        }

        return "https://www.google.com/maps?q={$this->latitude},{$this->longitude}";
    }
}
