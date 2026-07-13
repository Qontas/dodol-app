<?php

namespace App\Models;

use App\Models\Scopes\OwnerScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

class Kiosk extends Model
{
    use HasFactory;

    /**
     * Global read scope multi-tenant: Kiosk owner lewat rantai cluster
     * (whereHas cluster.owner_id). Secure-by-default — lihat OwnerScope.
     * Kios tanpa cluster (cluster_id null / orphan) TER-FILTER (tak tampil)
     * saat ada owner aktif; cluster kini wajib jadi data baru tidak orphan.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new OwnerScope);
    }

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
        'sort_order',
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
        'store_note',
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
     * Pindahkan $kiosk ke posisi $desired DALAM cluster-nya sendiri, tanpa pernah
     * menghasilkan duplikat ATAU lubang — pola "insert-within-list" (ala
     * Notion/Trello reorder), bukan sekadar "increment semua >= target" (itu naif,
     * bikin lubang kalau kios dipindah ke posisi LEBIH AWAL karena slot lamanya
     * ditinggal kosong tanpa digeser).
     *
     * Hanya kios LAIN di cluster yang SAMA yang ikut tergeser — cluster/tenant lain
     * 0 tersentuh (query di-scope `cluster_id`, OwnerScope Kiosk otomatis berlaku
     * juga di sini, redundan tapi aman, pola sama dgn gerbang dobel di ActiveTrip).
     *
     * @return int|null Posisi akhir kios ini setelah di-clamp (null = dilepas dari urutan).
     */
    /**
     * Tempatkan kios di URUTAN TERAKHIR cluster-nya (MAX(sort_order)+1, atau 1 kalau
     * cluster kosong). Dipakai saat CREATE tanpa mengisi urutan (owner Filament &
     * operator create-kiosk) supaya kios BARU tidak jatuh ke bawah tanpa nomor (NULL)
     * yang mengacaukan urutan rute — kosong = otomatis jadi terakhir. Juga dipakai saat
     * kios DIPINDAH ke cluster lain (urutan lama tak relevan → terakhir di cluster baru).
     *
     * Race condition: MAX dihitung dalam transaction + lockForUpdate (serialisasi dua
     * create bersamaan). Kalau di bawah beban ekstrem tetap ada tabrakan, sisi tulis
     * angka (reorderWithinCluster / TextInputColumn) yang membereskan (auto-reflow).
     * Scope per-CLUSTER (satu cluster = satu owner) → tenant lain 0 tersentuh.
     *
     * @return int Posisi terakhir yang diberikan ke kios ini.
     */
    public static function appendToClusterOrder(self $kiosk): int
    {
        return DB::transaction(function () use ($kiosk) {
            $max = static::query()
                ->where('cluster_id', $kiosk->cluster_id)
                ->where('id', '!=', $kiosk->id)
                ->lockForUpdate()
                ->max('sort_order');

            $next = ((int) $max) + 1;
            $kiosk->update(['sort_order' => $next]);

            return $next;
        });
    }

    public static function reorderWithinCluster(self $kiosk, ?int $desired): ?int
    {
        return DB::transaction(function () use ($kiosk, $desired) {
            $clusterId = $kiosk->cluster_id;

            if ($desired === null) {
                $kiosk->update(['sort_order' => null]);

                return null;
            }

            // Rentang valid: 1..(JUMLAH kios lain yang sudah punya posisi + 1) — sengaja
            // pakai COUNT, BUKAN MAX(sort_order). Total slot TAK berubah saat kios yang
            // SUDAH punya posisi cuma dipindah (masih kios yang sama, cuma total-1 "lain"
            // + kios ini = total sama); pakai MAX di sini ketangkep salah lewat test:
            // kalau $kiosk yang dipindah BUKAN pemegang nilai tertinggi saat ini, MAX
            // punya-orang-lain tetap = nilai tertinggi keseluruhan → +1 kelebihan satu
            // dari batas sebenarnya (off-by-one, KETEMU lewat test clamp).
            $totalOthers = static::query()
                ->where('cluster_id', $clusterId)
                ->where('id', '!=', $kiosk->id)
                ->whereNotNull('sort_order')
                ->count();

            $target = max(1, min($desired, $totalOthers + 1));
            $oldPos = $kiosk->sort_order;

            if ($oldPos === null) {
                // Sisip baru: kios lain di posisi >= target naik satu, beri ruang.
                static::query()
                    ->where('cluster_id', $clusterId)
                    ->where('id', '!=', $kiosk->id)
                    ->where('sort_order', '>=', $target)
                    ->increment('sort_order');
            } elseif ($target > $oldPos) {
                // Pindah ke belakang: yang di ANTARA posisi lama & baru maju satu.
                static::query()
                    ->where('cluster_id', $clusterId)
                    ->where('id', '!=', $kiosk->id)
                    ->where('sort_order', '>', $oldPos)
                    ->where('sort_order', '<=', $target)
                    ->decrement('sort_order');
            } elseif ($target < $oldPos) {
                // Pindah ke depan: yang di ANTARA posisi baru & lama mundur satu.
                static::query()
                    ->where('cluster_id', $clusterId)
                    ->where('id', '!=', $kiosk->id)
                    ->where('sort_order', '>=', $target)
                    ->where('sort_order', '<', $oldPos)
                    ->increment('sort_order');
            }

            $kiosk->update(['sort_order' => $target]);

            return $target;
        });
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
     * URL foto kios — SATU sumber kebenaran dipakai owner (Filament ImageColumn)
     * DAN operator (active-trip.blade.php), sengaja bukan URL R2 langsung.
     *
     * AKAR yang dihindari: URL R2 langsung (pub-*.r2.dev) terbukti kena
     * net::ERR_CERT_COMMON_NAME_INVALID transien di sebagian browser/environment
     * (koneksi HTTPS lintas-origin ke domain CDN cert-wildcard bersama) —
     * dikonfirmasi BUKAN app/URL/server (byte-identik, curl 100% bersih), BUKAN
     * device/jaringan spesifik user (direproduksi di mesin lain juga). Proxy
     * same-origin lewat KioskPhotoController menghilangkan kelas bug ini sama
     * sekali karena browser tak pernah lagi koneksi langsung ke r2.dev.
     *
     * `?v=` = timestamp `updated_at` → cache-bust otomatis tiap kios disimpan
     * ulang (termasuk saat photo_path berganti karena upload baru), aman di-cache
     * agresif (lihat KioskPhotoController + sw.js) karena URL berubah begitu
     * kontennya berubah — sama seperti pola asset Vite ber-hash.
     */
    public function getPhotoUrlAttribute(): ?string
    {
        if (! $this->photo_path) {
            return null;
        }

        return route('kiosks.photo', $this).'?v='.$this->updated_at?->timestamp;
    }

    public function getMapsUrlAttribute(): ?string
    {
        if (is_null($this->latitude) || is_null($this->longitude)) {
            return null;
        }

        return "https://www.google.com/maps?q={$this->latitude},{$this->longitude}";
    }
}
