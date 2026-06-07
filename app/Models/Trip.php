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
        'ended_reason',
        'qty_carried_total',
        'starting_cluster_id',
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

    public function startingCluster(): BelongsTo
    {
        return $this->belongsTo(Cluster::class, 'starting_cluster_id');
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

    public function getMikaTerjualAttribute(): float
    {
        $settledDeliveryIds = $this->visits()
            ->whereNotNull('settled_delivery_id')
            ->pluck('settled_delivery_id');

        $qtySoldBiji = (float) Settlement::whereIn('delivery_id', $settledDeliveryIds)
            ->sum('qty_sold');

        return $qtySoldBiji / 15;
    }

    public function getMikaKiosBaruAttribute(): float
    {
        $newKioskVisits = $this->visits()
            ->where('visit_action', 'drop_only')
            ->whereHas('kiosk', function ($q) {
                $q->whereDate('created_at', $this->trip_date);
            })
            ->get();

        $mikaKiosBaru = 0;
        foreach ($newKioskVisits as $visit) {
            if ($visit->newDelivery) {
                $mikaKiosBaru += (float) $visit->newDelivery->qty_delivered;
            }
        }

        return (float) $mikaKiosBaru;
    }

    public function getOmsetValAttribute(): float
    {
        $settledDeliveryIds = $this->visits()
            ->whereNotNull('settled_delivery_id')
            ->pluck('settled_delivery_id');

        return (float) Settlement::whereIn('delivery_id', $settledDeliveryIds)
            ->sum('amount_paid');
    }

    public function getHppEstimasiAttribute(): float
    {
        return $this->mika_terjual * 9500;
    }

    public function getUntungKotorAttribute(): float
    {
        return $this->mika_terjual * 2500;
    }

    public function getKomisiRegulerAttribute(): float
    {
        return $this->mika_terjual * 500;
    }

    public function getKomisiKiosBaruAttribute(): float
    {
        return $this->mika_kios_baru * 1000;
    }

    public function getKomisiRianAttribute(): float
    {
        return $this->komisi_reguler + $this->komisi_kios_baru;
    }

    public function getUntungBersihOwnerAttribute(): float
    {
        return $this->untung_kotor - $this->komisi_rian;
    }

    public function getKiosBaruCountAttribute(): int
    {
        return $this->visits()
            ->where('visit_action', 'drop_only')
            ->whereHas('kiosk', function ($q) {
                $q->whereDate('created_at', $this->trip_date);
            })
            ->count();
    }

    public function getKiosLamaCountAttribute(): int
    {
        $totalVisited = $this->visits()->count();
        return max(0, $totalVisited - $this->kios_baru_count);
    }
}
