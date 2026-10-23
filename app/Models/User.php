<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Prefix email akun sistem (dibuat command kios:saldo-awal / OpeningBalance,
     * password acak, nonaktif) — SATU SUMBER KEBENARAN dipakai isSystemAccount(),
     * scopeExcludeSystem(), Trip::scopeRealOperational()/isMigrationSentinel(),
     * TripAggregator, dan OwnerDashboardController. Refactor 2026-10-23: dulu
     * literal 'operator.migrasi.' tersebar di 4 file berbeda, sekarang satu
     * konstanta — rumus/angka TIDAK berubah, murni konsolidasi string.
     */
    public const SYSTEM_OPERATOR_EMAIL_PREFIX = 'operator.migrasi.';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'owner_id',
        'commission_rate',
        'hpp_per_mika',
        'is_active',
        'harga_mika',
        'komisi_per_mika',
        'komisi_kios_baru_per_mika',
        'komisi_per_mika_khusus',
        'business_name',
        'default_target_visit_interval_days',
        'default_warning_visit_interval_days',
        'default_fast_mover_threshold_days',
        'kios_baru_days',
        'default_qty_mika',
        'app_fee_per_pack',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => 'string',
            'commission_rate' => 'decimal:4',
            'hpp_per_mika' => 'decimal:2',
            'is_active' => 'boolean',
            'harga_mika' => 'decimal:2',
            'komisi_per_mika' => 'decimal:2',
            'komisi_kios_baru_per_mika' => 'decimal:2',
            'komisi_per_mika_khusus' => 'decimal:2',
            'business_name' => 'string',
            'default_target_visit_interval_days' => 'integer',
            'default_warning_visit_interval_days' => 'integer',
            'default_fast_mover_threshold_days' => 'integer',
            'kios_baru_days' => 'integer',
            'default_qty_mika' => 'integer',
            'app_fee_per_pack' => 'decimal:2',
        ];
    }

    /**
     * Email SELALU disimpan ternormalisasi: trim + lowercase. Satu titik untuk SEMUA
     * jalur tulis (seeder, UserResource super admin, OperatorResource owner,
     * ManageOperators, tinker/CLI, factory/test) — tak ada jalur yang bisa lolos.
     *
     * Kenapa penting: (a) spasi depan/belakang dari autocomplete HP tak boleh bikin
     * email kembar/aneh; (b) collation utf8mb4_unicode_ci sudah case-insensitive untuk
     * lookup, tapi menyimpan lowercase membuat data eksplisit & portabel (aman kalau
     * suatu saat pindah DB/collation case-sensitive).
     *
     * Login-nya sendiri menormalkan input sebelum Auth::attempt (lihat LoginForm),
     * karena mutator hanya berlaku saat MENULIS model, bukan saat query WHERE email.
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => $value === null ? null : mb_strtolower(trim($value)),
        );
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    /**
     * Sistem hanya boleh punya SATU super_admin (keputusan owner: tak ada alasan
     * bisnis untuk super admin kedua; tiap tambahan = permukaan serangan lintas-tenant).
     * True kalau sudah ada super_admin lain (opsional kecualikan satu id, mis. saat
     * Edit si super itu sendiri). Dipakai form role option + guard CreateUser/EditUser.
     */
    public static function anotherSuperAdminExists(?int $exceptId = null): bool
    {
        return static::query()
            ->where('role', 'super_admin')
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->exists();
    }

    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }

    public function isOperator(): bool
    {
        return $this->role === 'operator';
    }

    /**
     * Akun sistem (dibuat command kios:saldo-awal, password acak, nonaktif) —
     * bukan akun manusia. Tak boleh diedit/diaktifkan/dihapus dari UI & disembunyikan
     * dari daftar user. Penanda: pola email operator.migrasi.owner{N}@...
     */
    public function isSystemAccount(): bool
    {
        return str_starts_with((string) $this->email, self::SYSTEM_OPERATOR_EMAIL_PREFIX);
    }

    /**
     * Sembunyikan akun sistem dari listing/panel. Dipakai getEloquentQuery
     * UserResource (super admin) & OperatorResource (owner).
     */
    public function scopeExcludeSystem(Builder $query): Builder
    {
        return $query->where('email', 'not like', self::SYSTEM_OPERATOR_EMAIL_PREFIX.'%');
    }

    /**
     * Alasan ramah kenapa user ini TIDAK BOLEH dihapus (null = boleh hapus).
     * Dipakai guard delete di UserResource (super admin) & OperatorResource (owner)
     * agar muncul pesan jelas — BUKAN QueryException FK 500 mentah, dan bukan cascade
     * parsial yang menghapus data owner/operator diam-diam.
     *
     * - Super admin: tak boleh dihapus siapa pun (cegah lockout / hapus admin lain).
     * - Owner berdata (punya kios/trip/operator): nonaktifkan saja, jangan hapus.
     * - Operator berdata (punya trip/komisi): nonaktifkan saja, jangan hapus.
     *
     * ⚠️ SELALU MENGHITUNG REAL-TIME — sengaja. Ini yang dipakai guard server-side
     * (->before()) saat tombol hapus BENAR-BENAR ditekan: keputusan hapus tak boleh
     * bersandar pada angka yang dimuat saat render tabel (bisa basi berdetik/bermenit).
     * Untuk RENDER tabel pakai deletionBlockReasonForDisplay() yang 0 query.
     */
    public function deletionBlockReason(): ?string
    {
        if ($alasan = $this->deletionBlockReasonByRole()) {
            return $alasan;
        }

        if ($this->isOwner()) {
            return $this->ownerBlockMessage(
                \App\Models\Kiosk::whereHas('cluster', fn ($q) => $q->where('owner_id', $this->id))->count(),
                \App\Models\Trip::where('owner_id', $this->id)->count(),
                static::where('owner_id', $this->id)->count(),
            );
        }

        return $this->operatorBlockMessage(
            $this->operatedTrips()->count(),
            $this->commissions()->count(),
        );
    }

    /**
     * Versi RENDER-ONLY: baca count yang sudah di-eager-load (withCount di
     * getEloquentQuery resource) → 0 query tambahan per baris.
     *
     * KENAPA ADA: ->disabled() dan ->tooltip() Filament masing-masing memanggil guard
     * ini sekali per baris, jadi versi real-time menembak 2-3 COUNT × 2 = 4-6 query
     * PER BARIS (audit: ~53 query untuk 10 baris). Terbatas pagination, tapi mubazir.
     *
     * KENAPA TERPISAH, bukan bikin deletionBlockReason() ikut baca count: instance
     * record yang sama dipakai ulang oleh guard ->before(), jadi kalau guard utama
     * membaca count hasil render, keputusan HAPUS jadi bersandar angka basi. Pemisahan
     * ini menjaga: render = murah, keputusan = real-time.
     *
     * Kalau count belum dimuat (dipanggil di luar tabel), otomatis jatuh ke versi
     * real-time — jadi tak pernah salah, cuma tak sehemat itu.
     */
    public function deletionBlockReasonForDisplay(): ?string
    {
        if ($alasan = $this->deletionBlockReasonByRole()) {
            return $alasan;
        }

        if ($this->isOwner()) {
            if (! $this->countsSudahDimuat(['owned_kiosks_count', 'owned_trips_count', 'operators_count'])) {
                return $this->deletionBlockReason();
            }

            return $this->ownerBlockMessage(
                (int) $this->owned_kiosks_count,
                (int) $this->owned_trips_count,
                (int) $this->operators_count,
            );
        }

        if (! $this->countsSudahDimuat(['operated_trips_count', 'commissions_count'])) {
            return $this->deletionBlockReason();
        }

        return $this->operatorBlockMessage(
            (int) $this->operated_trips_count,
            (int) $this->commissions_count,
        );
    }

    /** Blokir yang tak butuh hitungan sama sekali (murni dari role/pola email). */
    private function deletionBlockReasonByRole(): ?string
    {
        if ($this->isSuperAdmin()) {
            return 'Akun Super Admin tidak bisa dihapus.';
        }

        if ($this->isSystemAccount()) {
            return 'Akun sistem tidak bisa dihapus.';
        }

        return null;
    }

    /** Satu sumber teks — supaya jalur real-time & jalur render tak bisa menyimpang. */
    private function ownerBlockMessage(int $kios, int $trip, int $operator): ?string
    {
        if ($kios || $trip || $operator) {
            return "Owner ini punya {$kios} kios, {$trip} trip, {$operator} operator. Nonaktifkan saja, jangan hapus.";
        }

        return null;
    }

    private function operatorBlockMessage(int $trip, int $komisi): ?string
    {
        if ($trip || $komisi) {
            return "Operator ini punya {$trip} trip, {$komisi} komisi. Nonaktifkan saja, jangan hapus.";
        }

        return null;
    }

    /** @param  array<string>  $atribut */
    private function countsSudahDimuat(array $atribut): bool
    {
        foreach ($atribut as $a) {
            if (! array_key_exists($a, $this->attributes)) {
                return false;
            }
        }

        return true;
    }

    /**
     * URL dashboard sesuai role — satu sumber kebenaran dipakai oleh route
     * "/dashboard" dan LoginResponse (Filament) agar tidak divergent.
     * Super admin diarahkan ke Filament admin panel.
     */
    public function homePath(): string
    {
        if ($this->isSuperAdmin()) {
            return '/admin';
        }

        return route("{$this->role}.dashboard");
    }

    /**
     * HPP per mika owner ini, fallback ke default Rp 9.500.
     */
    public function getHppPerMikaValue(): float
    {
        return (float) ($this->hpp_per_mika ?? 9500);
    }

    public function getHargaMikaValue(): float
    {
        return (float) ($this->harga_mika ?? 200);
    }

    public function getKomisiPerMikaValue(): float
    {
        // Tarif komisi Rian per mika DROP (Opsi Y). Default Rp 1.000.
        return (float) ($this->komisi_per_mika ?? 1000);
    }

    public function getKomisiKiosBaruPerMikaValue(): float
    {
        return (float) ($this->komisi_kios_baru_per_mika ?? 1000);
    }

    /**
     * BRIEF 2 Section 3 — tarif komisi EFEKTIF untuk user ini. Operator: tarif
     * khusus (komisi_per_mika_khusus) kalau diisi, kalau tidak ikut tarif
     * default owner-nya. Owner/super admin: tarif owner itu sendiri (sama
     * seperti getKomisiPerMikaValue() — dipertahankan sbg method terpisah
     * supaya titik panggil StartTrip tidak perlu peduli role siapa yang login).
     *
     * Dipakai HANYA saat trip DIMULAI (snapshot ke trips.komisi_per_mika_snapshot)
     * — ubah tarif di sini tidak pernah menyentuh trip yang sudah berjalan/berakhir.
     */
    public function getEffectiveKomisiPerMikaValue(): float
    {
        if ($this->isOperator()) {
            if ($this->komisi_per_mika_khusus !== null) {
                return (float) $this->komisi_per_mika_khusus;
            }

            return (float) ($this->owner?->getKomisiPerMikaValue() ?? 1000);
        }

        return $this->getKomisiPerMikaValue();
    }

    /**
     * TUGAS 4 (BRIEF 23 Okt 2026) — tarif fee aplikasi (Rp per kemasan/mika)
     * yang OWNER ini bayar ke pemilik aplikasi. Null = 0 (default; owner lama/
     * Ismi sendiri tetap Rp 0, tak perlu backfill). BEDA dari komisi Rian:
     * field ini TIDAK punya varian "khusus operator" — satu tarif per owner.
     */
    public function getAppFeePerPackValue(): float
    {
        return (float) ($this->app_fee_per_pack ?? 0);
    }

    /**
     * Tarif fee aplikasi EFEKTIF untuk user ini, dipakai StartTrip untuk
     * mengisi trips.app_fee_per_pack_snapshot — simetris dengan
     * getEffectiveKomisiPerMikaValue() supaya titik panggil StartTrip tidak
     * perlu peduli role siapa yang login (operator ikut tarif owner-nya).
     */
    public function getEffectiveAppFeePerPackValue(): float
    {
        if ($this->isOperator()) {
            return (float) ($this->owner?->getAppFeePerPackValue() ?? 0);
        }

        return $this->getAppFeePerPackValue();
    }

    /**
     * Nama bisnis owner ini — dipakai header panel/laporan/export. Fallback nama
     * lama yang dulu hardcode di layout/branding sebelum field ini ada.
     */
    public function getBusinessNameValue(): string
    {
        return $this->business_name ?? 'Cemilan Qontas';
    }

    /**
     * Default "idealnya dikunjungi tiap berapa hari" untuk KIOS BARU yang dibuat
     * owner ini. Fallback 10 — sama dengan default form KioskResource (15 Juli
     * 2026, lihat komentarnya) yang jadi sumber kebenaran, BUKAN default kolom DB.
     */
    public function getDefaultTargetVisitIntervalDaysValue(): int
    {
        return (int) ($this->default_target_visit_interval_days ?? 10);
    }

    /**
     * Default "peringatan kalau belum dikunjungi" untuk kios baru. Fallback 14.
     */
    public function getDefaultWarningVisitIntervalDaysValue(): int
    {
        return (int) ($this->default_warning_visit_interval_days ?? 14);
    }

    /**
     * Default "batas kios laris (hari)" untuk kios baru. Fallback 7.
     */
    public function getDefaultFastMoverThresholdDaysValue(): int
    {
        return (int) ($this->default_fast_mover_threshold_days ?? 7);
    }

    /**
     * Lama (hari) status "kios baru" dihitung dari first_titip_date — dipakai badge
     * ⭐ dan penentuan komisi kios baru. Fallback 30 (perilaku hardcode lama).
     */
    public function getKiosBaruDaysValue(): int
    {
        return (int) ($this->kios_baru_days ?? 30);
    }

    /**
     * Default jatah titipan (mika) untuk kios konsinyasi baru. Fallback 1 — sama
     * dengan perilaku live sekarang (CreateKiosk operator), BUKAN 4.
     */
    public function getDefaultQtyMikaValue(): int
    {
        return (int) ($this->default_qty_mika ?? 1);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return in_array($this->role, ['owner', 'super_admin'], true) && $this->is_active;
    }

    /**
     * Owner dari user ini (hanya relevan untuk operator).
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Operator yang terikat ke owner ini.
     */
    public function operators(): HasMany
    {
        return $this->hasMany(User::class, 'owner_id');
    }

    /**
     * Operator NYATA milik owner ini — AKTIF dan BUKAN akun sistem (operator.migrasi.*
     * dibuat kios:saldo-awal/OpeningBalance). SATU DEFINISI "jumlah operator" dipakai
     * UserResource (kolom "Operator"), OwnerPerformanceTable (leaderboard), dan
     * dashboard owner (kartu "Total Operator") — supaya operator nonaktif/akun sistem
     * tidak pernah menggelembungkan angka yang owner lihat (BRIEF fix 23 Okt 2026).
     *
     * SENGAJA BUKAN operators() yang dipakai guard deletionBlockReason()/withCount
     * 'operators' polos — guard HAPUS owner harus tetap menghitung SEMUA (termasuk
     * nonaktif & akun sistem) supaya pesan "tidak bisa dihapus" tidak pernah meleset
     * (owner dengan 1 operator nonaktif/sistem TETAP punya data yang harus dijaga).
     */
    public function realOperators(): HasMany
    {
        return $this->operators()->where('role', 'operator')->where('is_active', true)->excludeSystem();
    }

    public function operatedTrips(): HasMany
    {
        return $this->hasMany(Trip::class, 'operator_id');
    }

    /**
     * Trip MILIK owner ini (owner_id) — beda dari operatedTrips() yang trip yang
     * DIJALANKAN user ini sebagai operator. Dipakai withCount guard delete.
     */
    public function ownedTrips(): HasMany
    {
        return $this->hasMany(Trip::class, 'owner_id');
    }

    /**
     * Kios milik owner ini lewat rantai cluster (kiosks tak punya kolom owner_id) —
     * rantai yang sama dipakai OwnerScope. Dipakai withCount guard delete supaya
     * hitungan kios ikut query utama tabel, bukan query terpisah per baris.
     */
    public function ownedKiosks(): HasManyThrough
    {
        return $this->hasManyThrough(
            Kiosk::class,
            Cluster::class,
            'owner_id',   // FK di clusters → users
            'cluster_id', // FK di kiosks → clusters
            'id',
            'id',
        );
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(Commission::class, 'operator_id');
    }

    public function fuelLogs(): HasMany
    {
        return $this->hasMany(FuelLog::class, 'operator_id');
    }
}
