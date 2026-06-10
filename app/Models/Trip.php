<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Trip extends Model
{
    use BelongsToOwner, HasFactory;

    protected $fillable = [
        'trip_date',
        'trip_number_of_day',
        'owner_id',
        'operator_id',
        'started_at',
        'ended_at',
        'ended_reason',
        'qty_carried_total',
        'qty_bs_redistributed',
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

    /**
     * Total mika drop yang benar-benar berasal dari stok baru yang dibawa
     * operator — mengecualikan mika BS redistribusi (yang bukan dari qty_carried).
     */
    public function getTotalDropReal(): int
    {
        return (int) $this->deliveries()
            ->where('delivery_type', '!=', 'bs_redistribution')
            ->sum('qty_delivered');
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
                // Kios baru = first_titip_date sama dengan tanggal trip ini.
                $q->whereDate('first_titip_date', $this->trip_date);
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

    /**
     * HPP per mika dari owner trip ini. Fallback Rp 9.500 kalau owner belum di-set.
     */
    private function getHpp(): float
    {
        return (float) ($this->owner?->hpp_per_mika ?? 9500);
    }

    /**
     * Untung kotor per mika = harga jual (Rp 12.000) - HPP.
     */
    private function getUntungPerMika(): float
    {
        return 12000 - $this->getHpp();
    }

    /**
     * Komisi reguler per mika = 20% dari untung kotor per mika.
     */
    private function getKomisiPerMika(): float
    {
        return $this->getUntungPerMika() * 0.2;
    }

    public function getHppEstimasiAttribute(): float
    {
        return $this->mika_terjual * $this->getHpp();
    }

    public function getUntungKotorAttribute(): float
    {
        return $this->mika_terjual * $this->getUntungPerMika();
    }

    public function getKomisiRegulerAttribute(): float
    {
        return $this->mika_terjual * $this->getKomisiPerMika();
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
                // Kios baru = first_titip_date sama dengan tanggal trip ini.
                $q->whereDate('first_titip_date', $this->trip_date);
            })
            ->count();
    }

    public function getKiosLamaCountAttribute(): int
    {
        $totalVisited = $this->visits()->count();
        return max(0, $totalVisited - $this->kios_baru_count);
    }
}
