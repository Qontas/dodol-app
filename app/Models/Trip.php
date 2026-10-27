<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Trip extends Model
{
    // PENGECUALIAN dari kebijakan umum "tanpa SoftDeletes" (model lain pakai is_active):
    // trip diARSIP, bukan dihapus. Global scope SoftDeletes menyembunyikan trip terarsip
    // dari SEMUA query lewat model Trip / whereHas('trip') → laporan bulanan, dashboard,
    // omset/untung/komisi otomatis mengecualikannya. Data anak (kiosk_visits, deliveries,
    // settlements, commissions) TIDAK ikut terhapus fisik saat delete()/arsip. Query pakai
    // join manual pada tabel `trips` (bukan lewat model) TIDAK kena scope ini — di sana
    // ditambahkan whereNull('trips.deleted_at') manual. Pulihkan: `php artisan trip:restore`.
    use BelongsToOwner, HasFactory, SoftDeletes;

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
        'komisi_per_mika_snapshot',
        'app_fee_per_pack_snapshot',
    ];

    protected $casts = [
        'trip_date' => 'date',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'komisi_per_mika_snapshot' => 'decimal:2',
        'app_fee_per_pack_snapshot' => 'decimal:2',
    ];

    /** Cache instance untuk salesSnapshotTotals() — lihat docblock method-nya. */
    private ?array $salesSnapshotTotalsCache = null;

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
     * BRIEF 27 Okt 2026 "PENGAMAN STOK DI MOTOR" — tambahan stok mika yang dicatat
     * operator di tengah trip ("➕ Tambah Stok di Motor"). qty_carried_total TETAP
     * stok awal; baris di sini ditambahkan di atasnya. Lihat getStokDiMotor().
     */
    public function stockAdditions(): HasMany
    {
        return $this->hasMany(TripStockAddition::class);
    }

    /**
     * Trip MIGRASI SENTINEL (saldo awal kedai lama) — dibuat OpeningBalance::
     * migrationTripFor() (trip_date 2000-01-01) ATAU command kios:saldo-awal /
     * SeedKioskSaldoAwal (trip_date = tanggal spreadsheet asli, BUKAN 2000-01-01,
     * ended_reason teks berbeda). Trip TEKNIS buat nyimpen titipan kedai lama,
     * BUKAN perjalanan nyata — tak boleh menyumbang komisi/untung/kerugian, tak
     * boleh tampil di Riwayat Trip / agregat laporan mana pun.
     *
     * SATU SUMBER KEBENARAN: operator trip ini akun sistem (User::isSystemAccount(),
     * pola email operator.migrasi.*) — BUKAN trip_date/ended_reason, karena dua jalur
     * pembuatan di atas pakai trip_date & ended_reason yang BERBEDA (operator-nya
     * konsisten di keduanya, lihat investigasi BRIEF 2026-10-23 poin 2). Titipan
     * (delivery pending) yang dibuat trip ini TETAP berlaku & bisa ditagih normal —
     * method ini TIDAK menyentuh deliveries/kiosk_visits, cuma visibilitas trip itu
     * sendiri di komisi/listing/agregat.
     */
    public function isMigrationSentinel(): bool
    {
        return (bool) $this->operator?->isSystemAccount();
    }

    /**
     * Scope: trip OPERASIONAL NYATA saja, exclude sentinel migrasi (lihat
     * isMigrationSentinel()). Pakai di SEMUA query listing/agregat trip yang
     * dibaca manusia atau dipakai basis laporan (Riwayat Trip, dashboard, laporan
     * bulanan, leaderboard admin). JANGAN dipakai pada query delivery/pending-
     * titipan (itu harus tetap jalan untuk kedai lama).
     */
    public function scopeRealOperational(Builder $query): Builder
    {
        return $query->whereDoesntHave('operator', fn ($q) => $q->where('email', 'like', User::SYSTEM_OPERATOR_EMAIL_PREFIX.'%'));
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

    /**
     * Total mika yang ditambahkan ke stok motor SETELAH trip mulai (riwayat
     * "➕ Tambah Stok di Motor"). Lihat TripStockAddition / getStokDiMotor().
     */
    public function getQtyAddedTotalAttribute(): int
    {
        return (int) $this->stockAdditions()->sum('qty');
    }

    /**
     * Stok EFEKTIF yang dibawa trip ini = stok awal (qty_carried_total, tidak
     * pernah ditimpa) + semua tambahan stok di tengah trip.
     */
    public function getQtyCarriedEffectiveAttribute(): int
    {
        return (int) ($this->qty_carried_total ?? 0) + $this->qty_added_total;
    }

    /**
     * SATU SUMBER KEBENARAN "stok di motor" — dipakai di SEMUA tempat (guard
     * titip/jual/koreksi, tampilan Active Trip, Akhiri Trip, Live Trip owner,
     * halaman detail trip, PDF & Excel). = stok dibawa efektif − total drop REAL
     * (exclude bs_redistribution, lihat getTotalDropReal()) — Dodol Sisa (BS)
     * berasal dari KIOS, bukan dari stok motor, jadi TIDAK mengurangi stok di
     * motor saat diletakkan ulang.
     *
     * BRIEF 27 Okt 2026 — investigasi trip "KualiStories" (26 Okt: dibawa 90,
     * dititip 107, komisi drop 107) membuktikan TIDAK ADA bs_redistribution di
     * trip itu sama sekali, jadi -17 BUKAN disebabkan Dodol Sisa (koreksi atas
     * hipotesis investigasi lama di TripExportController::buildTripData()).
     * Akar masalahnya memang tidak ada guard & tidak ada jalur tambah stok —
     * keduanya baru ditambahkan brief ini.
     */
    public function getStokDiMotor(): int
    {
        return $this->qty_carried_effective - $this->getTotalDropReal();
    }

    /**
     * Tampilan "Mika Dibawa" — "90 + 20 tambahan = 110" kalau ada tambahan stok,
     * atau cukup "90" kalau belum pernah ditambah. Dipakai Akhiri Trip, halaman
     * detail trip owner, Live Trip owner, PDF & Excel trip.
     */
    public function getMikaDibawaDisplayAttribute(): string
    {
        $awal = (int) ($this->qty_carried_total ?? 0);
        $tambahan = $this->qty_added_total;

        if ($tambahan <= 0) {
            return (string) $awal;
        }

        return "{$awal} + {$tambahan} tambahan = {$this->qty_carried_effective}";
    }

    /**
     * Versi BIJI dari getTotalDropReal() — tampilan tambahan "X mika (Y biji)", bukan
     * basis hitungan baru. qty_delivered × units_per_pack_snapshot per baris (SUM di SQL,
     * bukan N+1), fallback 15 identik accessor Delivery::getQtyBijiDeliveredAttribute().
     */
    public function getBijiKomisiAttribute(): int
    {
        return (int) $this->deliveries()
            ->where('delivery_type', '!=', 'bs_redistribution')
            ->selectRaw('COALESCE(SUM(qty_delivered * COALESCE(units_per_pack_snapshot, 15)), 0) as total')
            ->value('total');
    }

    /**
     * Versi BIJI dari total mika diantar (semua delivery_type, termasuk BS) — tampilan
     * tambahan "Mika Diantar" di sisi mika/biji, bukan basis komisi (lihat biji_komisi).
     */
    public function getBijiDiantarAttribute(): int
    {
        return (int) $this->deliveries()
            ->selectRaw('COALESCE(SUM(qty_delivered * COALESCE(units_per_pack_snapshot, 15)), 0) as total')
            ->value('total');
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
        return $this->salesSnapshotTotals()['mika_terjual'];
    }

    public function getMikaKiosBaruAttribute(): float
    {
        $newKioskVisits = $this->visits()
            ->active()
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

    /**
     * Versi BIJI dari getMikaKiosBaruAttribute() — tampilan tambahan, query sama (visit
     * drop_only kios baru), ditambah accessor Delivery::qty_biji_delivered per baris
     * (tanpa query baru, newDelivery sudah dimuat per-visit di loop yang sama).
     */
    public function getBijiKiosBaruAttribute(): int
    {
        $newKioskVisits = $this->visits()
            ->active()
            ->where('visit_action', 'drop_only')
            ->whereHas('kiosk', function ($q) {
                $q->whereDate('first_titip_date', $this->trip_date);
            })
            ->get();

        $bijiKiosBaru = 0;
        foreach ($newKioskVisits as $visit) {
            if ($visit->newDelivery) {
                $bijiKiosBaru += $visit->newDelivery->qty_biji_delivered;
            }
        }

        return $bijiKiosBaru;
    }

    public function getOmsetValAttribute(): float
    {
        $settledDeliveryIds = $this->visits()
            ->active()
            ->whereNotNull('settled_delivery_id')
            ->pluck('settled_delivery_id');

        return (float) Settlement::whereIn('delivery_id', $settledDeliveryIds)
            ->sum('amount_paid');
    }

    /**
     * HPP per mika dari owner trip ini. Fallback Rp 9.500 kalau owner belum di-set.
     * Sekarang HANYA dipakai sebagai fallback baris delivery lolos cost_snapshot NULL
     * (lihat salesSnapshotTotals()) — HPP "nyata" per transaksi dibaca dari snapshot
     * delivery, bukan dari users.hpp_per_mika lagi.
     */
    private function getHpp(): float
    {
        return (float) ($this->owner?->hpp_per_mika ?? 9500);
    }

    /**
     * Tarif komisi Rian per mika DROP YANG BERLAKU di trip ini (Opsi Y — basis
     * DROP. Bonus kios-baru terpisah sudah dilebur ke tarif ini).
     *
     * BRIEF 2 (21 Okt 2026) — dibaca dari komisi_per_mika_snapshot (diisi SEKALI
     * saat trip dimulai, StartTrip::startTrip(), dari tarif EFEKTIF operator trip
     * ybs — User::getEffectiveKomisiPerMikaValue()), BUKAN lagi tarif owner/
     * operator SAAT INI. Sebelum perubahan ini komisi_rian baca owner->komisi_
     * per_mika LIVE tanpa snapshot apa pun — trip yang sudah lama berakhir ikut
     * berubah komisinya kalau owner ganti tarif hari ini (dibuktikan dgn test,
     * lihat laporan investigasi BRIEF 2 poin 1). Fallback live dipertahankan
     * HANYA untuk trip pra-snapshot yang belum di-backfill (seharusnya tidak
     * terjadi di produksi setelah `trips:backfill-komisi-snapshot` dijalankan).
     */
    private function getKomisiPerMika(): float
    {
        return (float) ($this->komisi_per_mika_snapshot ?? $this->owner?->komisi_per_mika ?? 1000);
    }

    /**
     * mika_terjual/hpp_estimasi/untung_kotor dihitung SEKALI, per-BARIS delivery
     * snapshot (units_per_pack_snapshot/unit_price/cost_snapshot) — bukan lagi
     * qty_sold-total/15 dikali HPP-flat-owner. Perlu per-baris karena satu trip bisa
     * menyentuh delivery dengan isi/harga/HPP snapshot berbeda-beda (owner ganti varian,
     * atau produk berbeda). Hasil di-cache di instance karena 3 accessor di bawah
     * memanggil helper ini — tanpa cache jadi 3 query identik per Trip per request.
     *
     * Fallback cost_snapshot NULL → HPP owner saat ini (getHpp(), default 9500) — jalur
     * delivery lama sebelum snapshot ada; seharusnya sudah tak terjadi lagi setelah
     * deliveries:backfill-snapshot dijalankan, defensif saja.
     *
     * @return array{mika_terjual: float, hpp_estimasi: float, untung_kotor: float}
     */
    private function salesSnapshotTotals(): array
    {
        if ($this->salesSnapshotTotalsCache !== null) {
            return $this->salesSnapshotTotalsCache;
        }

        $settledDeliveryIds = $this->visits()
            ->active()
            ->whereNotNull('settled_delivery_id')
            ->pluck('settled_delivery_id');

        $rows = Settlement::whereIn('delivery_id', $settledDeliveryIds)
            ->join('deliveries', 'deliveries.id', '=', 'settlements.delivery_id')
            ->get([
                'settlements.qty_sold',
                'deliveries.unit_price',
                'deliveries.cost_snapshot',
                'deliveries.units_per_pack_snapshot',
            ]);

        $fallbackHpp = $this->getHpp();

        $mikaTerjual = 0.0;
        $hppEstimasi = 0.0;
        $untungKotor = 0.0;
        // biji_terjual = qty_sold mentah (settlements.qty_sold SUDAH dalam satuan biji,
        // bukan mika) — tampilan tambahan "X mika (Y biji)", TIDAK dipakai rumus uang.
        $bijiTerjual = 0;

        foreach ($rows as $row) {
            $units = max(1, (int) ($row->units_per_pack_snapshot ?? 15));
            $mikaRow = ((float) $row->qty_sold) / $units;
            $cost = $row->cost_snapshot !== null ? (float) $row->cost_snapshot : $fallbackHpp;
            $price = (float) $row->unit_price;

            $mikaTerjual += $mikaRow;
            $hppEstimasi += $mikaRow * $cost;
            $untungKotor += $mikaRow * ($price - $cost);
            $bijiTerjual += (int) $row->qty_sold;
        }

        return $this->salesSnapshotTotalsCache = [
            'mika_terjual' => $mikaTerjual,
            'hpp_estimasi' => $hppEstimasi,
            'untung_kotor' => $untungKotor,
            'biji_terjual' => $bijiTerjual,
        ];
    }

    /**
     * Versi BIJI dari mika_terjual — tampilan tambahan "X,XX mika (Y biji)". Nilainya
     * qty_sold mentah (satuan asli settlements, lihat salesSnapshotTotals()).
     */
    public function getBijiTerjualAttribute(): int
    {
        return $this->salesSnapshotTotals()['biji_terjual'];
    }

    public function getHppEstimasiAttribute(): float
    {
        return $this->salesSnapshotTotals()['hpp_estimasi'];
    }

    public function getUntungKotorAttribute(): float
    {
        return $this->salesSnapshotTotals()['untung_kotor'];
    }

    /**
     * BASIS KOMISI (Opsi Y — DROP): mika yang Rian BAWA & DILETAKKAN di trip ini,
     * EXCLUDE BS daur-ulang (bukan stok bawaan). Identik getTotalDropReal():
     * mencakup titip konsinyasi (kedai lama/baru/ekstra jatah) + semua penjualan
     * cash (walk-in, cash-only, cash-extra); exclude bs_redistribution. Stop+Tagih
     * tak pernah drop mika baru (dropBaru dipaksa 0) → otomatis 0 komisi.
     *
     * SENGAJA TERPISAH dari mika_terjual (basis LAKU, dipakai omset/HPP/untung
     * yang sudah benar & lulus test) — JANGAN dicampur.
     *
     * Trip SENTINEL migrasi (isMigrationSentinel()) dipaksa 0 — titipan saldo awal
     * kedai lama bukan "mika diletakkan operator", tak boleh menghasilkan komisi
     * atau untung_bersih negatif (lihat investigasi BRIEF 2026-10-23 poin 2).
     */
    public function getMikaKomisiAttribute(): float
    {
        if ($this->isMigrationSentinel()) {
            return 0.0;
        }

        return (float) $this->getTotalDropReal();
    }

    /**
     * KOMISI RIAN (Opsi Y — basis DROP): tarif (default Rp 1.000) × mika_komisi.
     * Bonus kios-baru terpisah DILEBUR ke tarif flat ini (kedai baru = tarif sama).
     */
    public function getKomisiRianAttribute(): float
    {
        return $this->mika_komisi * $this->getKomisiPerMika();
    }

    /**
     * LEGACY alias — dipertahankan agar konsumen lama tak pecah. Komisi kini FLAT,
     * jadi "reguler" = seluruh komisi Rian, "kios baru" = 0 (sudah dilebur).
     */
    public function getKomisiRegulerAttribute(): float
    {
        return $this->komisi_rian;
    }

    public function getKomisiKiosBaruAttribute(): float
    {
        return 0.0;
    }

    public function getUntungBersihOwnerAttribute(): float
    {
        return $this->untung_kotor - $this->komisi_rian;
    }

    public function getKiosBaruCountAttribute(): int
    {
        return $this->visits()
            ->active()
            ->where('visit_action', 'drop_only')
            ->whereHas('kiosk', function ($q) {
                // Kios baru = first_titip_date sama dengan tanggal trip ini.
                $q->whereDate('first_titip_date', $this->trip_date);
            })
            ->count();
    }

    public function getKiosLamaCountAttribute(): int
    {
        $totalVisited = $this->visits()->active()->count();
        return max(0, $totalVisited - $this->kios_baru_count);
    }
}
