<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Kiosk extends Model
{
    use HasFactory;

    /**
     * Penjualan walk-in (pembeli random di jalan, bukan kios terdaftar) dicatat
     * lewat 1 "kios sentinel" tersembunyi per owner. Penanda yang dipakai untuk
     * exclude dari listing/laporan per-kios: NAMA kios = WALKIN_SENTINEL_NAME
     * (tanpa kolom baru). Cluster induknya juga sentinel (lihat WALKIN_CLUSTER_PREFIX).
     */
    public const WALKIN_SENTINEL_NAME = 'Penjualan Walk-in';
    public const WALKIN_CLUSTER_PREFIX = '__walkin_owner_';

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
        'stopped_at',
        'stop_reason',
        'stopped_by',
        'notes',
    ];

    protected $casts = [
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'first_titip_date' => 'date',
        'is_active' => 'boolean',
        'is_cash_only' => 'boolean',
        'stopped_at' => 'datetime',
        'fast_mover_threshold_days' => 'integer',
    ];

    /**
     * Label manusiawi untuk alasan stop titipan (lihat brief Cut Off Kios).
     */
    public const STOP_REASONS = [
        'pemilik_minta_stop' => 'Pemilik minta berhenti sementara',
        'tutup_permanen' => 'Kedai tutup permanen',
        'kurang_laku' => 'Penjualan kurang jalan',
        'pindah_lokasi' => 'Pindah lokasi',
        'lainnya' => 'Alasan lain',
    ];

    public function getStopReasonLabelAttribute(): ?string
    {
        return $this->stop_reason
            ? (self::STOP_REASONS[$this->stop_reason] ?? $this->stop_reason)
            : null;
    }

    public function cluster(): BelongsTo
    {
        return $this->belongsTo(Cluster::class);
    }

    /**
     * Resolve (buat jika belum ada) kios sentinel walk-in milik $ownerId.
     * Idempoten: dipanggil berkali-kali tetap menghasilkan tepat 1 sentinel
     * + 1 cluster sentinel per owner. cluster_id WAJIB agar kios tetap
     * ter-scope ke owner yang benar (owner kios = cluster.owner_id).
     */
    public static function walkInSentinelFor(?int $ownerId): self
    {
        $cluster = Cluster::firstOrCreate(
            ['owner_id' => $ownerId, 'name' => self::WALKIN_CLUSTER_PREFIX . ($ownerId ?? 0)],
            ['is_active' => false, 'notes' => 'Cluster sistem untuk penjualan walk-in. Jangan hapus/ubah.']
        );

        return self::firstOrCreate(
            ['cluster_id' => $cluster->id, 'name' => self::WALKIN_SENTINEL_NAME],
            ['is_cash_only' => true, 'is_active' => false, 'notes' => 'Kios sistem untuk penjualan cash walk-in. Jangan hapus/ubah.']
        );
    }

    /**
     * Keluarkan kios sentinel walk-in dari listing & laporan per-kios.
     * (Omset walk-in tetap masuk perhitungan komisi — itu lewat trip, bukan kios.)
     */
    public function scopeExcludeWalkInSentinel(Builder $query): Builder
    {
        return $query->where($query->getModel()->getTable() . '.name', '!=', self::WALKIN_SENTINEL_NAME);
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
     * Kunjungan terakhir yang mencatat sisa biji di kios — sumber prediksi habis.
     * sisa_biji hanya pernah ditulis oleh visit catat-sisa (Cek Sisa atau Tunda
     * Bayar), jadi whereNotNull('sisa_biji') cukup sebagai penanda observasi sisa
     * tanpa mengikat visit_action tertentu.
     */
    public function latestCheckVisit(): HasOne
    {
        return $this->hasOne(KioskVisit::class)
            ->whereNull('corrected_at')
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

    /**
     * URL publik foto kios dari disk media (configurable, lihat config app.media_disk).
     * Null kalau belum ada foto. Jangan hardcode /storage/... di view — pakai ini.
     */
    public function getPhotoUrlAttribute(): ?string
    {
        if (! $this->photo_path) {
            return null;
        }

        return \Illuminate\Support\Facades\Storage::disk(config('app.media_disk', 'public'))
            ->url($this->photo_path);
    }

    public function getMapsUrlAttribute(): ?string
    {
        if (is_null($this->latitude) || is_null($this->longitude)) {
            return null;
        }

        return "https://www.google.com/maps?q={$this->latitude},{$this->longitude}";
    }
}
